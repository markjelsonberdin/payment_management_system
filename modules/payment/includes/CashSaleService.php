<?php

require_once __DIR__ . '/OfficialReceiptService.php';

/** Processes student-linked school-item cash sales against the normalized catalog. */
class CashSaleService
{
    public function __construct(private PDO $pdo) {}

    /** @param array<int,array{sale_variant_id:mixed,quantity:mixed}> $items */
    public function create(int $studentId, int $cashierId, array $items, float $cashReceived, string $remarks = '', string $idempotencyKey = ''): array
    {
        if ($studentId <= 0 || $cashierId <= 0 || !$items) throw new InvalidArgumentException('Student and at least one school item are required.');
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey)) throw new InvalidArgumentException('The sale request expired. Refresh the page and try again.');

        $requested = [];
        foreach ($items as $item) {
            $variantId = (int) ($item['sale_variant_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($variantId <= 0 || $quantity <= 0 || $quantity > 100) throw new InvalidArgumentException('Each school item needs a valid quantity.');
            $requested[$variantId] = ($requested[$variantId] ?? 0) + $quantity;
            if ($requested[$variantId] > 100) throw new InvalidArgumentException('A school-item quantity cannot exceed 100.');
        }

        $this->pdo->beginTransaction();
        try {
            $duplicate = $this->pdo->prepare('SELECT cs.cash_sale_id, ct.official_receipt_number, ct.school_sale_total, ct.change_amount FROM cashier_transactions ct JOIN cash_sales cs ON cs.cashier_transaction_id = ct.cashier_transaction_id WHERE ct.cashier_user_id = ? AND ct.idempotency_key = ? LIMIT 1');
            $duplicate->execute([$cashierId, $idempotencyKey]);
            if ($existing = $duplicate->fetch(PDO::FETCH_ASSOC)) {
                $this->pdo->commit();
                return ['cash_sale_id'=>(int)$existing['cash_sale_id'], 'receipt_number'=>$existing['official_receipt_number'], 'total'=>(float)$existing['school_sale_total'], 'change'=>(float)$existing['change_amount'], 'duplicate'=>true];
            }

            $studentStmt = $this->pdo->prepare("SELECT s.student_id, UPPER(NULLIF(TRIM(s.course), '')) AS program_code, NULLIF(TRIM(s.year_level), '') AS year_level, (SELECT b.academic_year FROM billing b WHERE b.student_id=s.student_id ORDER BY b.created_at DESC LIMIT 1) AS academic_year, (SELECT b.semester FROM billing b WHERE b.student_id=s.student_id ORDER BY b.created_at DESC LIMIT 1) AS semester FROM students s WHERE s.student_id=? FOR UPDATE");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) throw new RuntimeException('Student record not found.');

            $marks = implode(',', array_fill(0, count($requested), '?'));
            $catalogStmt = $this->pdo->prepare("SELECT v.sale_variant_id,v.variant_code,v.variant_name,v.size_label,i.sale_item_id,i.item_code,i.item_name,i.applicability_mode,c.sale_category_id,c.category_code,c.category_name,t.type_code AS item_type_code,t.type_name AS item_type_name,p.sale_variant_price_id,p.amount,p.effective_from,p.effective_to,b.book_title,b.author AS book_author,b.publisher AS book_publisher,b.edition AS book_edition,b.isbn AS book_isbn FROM school_sale_item_variants v JOIN school_sale_items i ON i.sale_item_id=v.sale_item_id AND i.status='Active' JOIN school_sale_categories c ON c.sale_category_id=i.sale_category_id AND c.status='Active' JOIN school_sale_item_types t ON t.sale_item_type_id=i.sale_item_type_id AND t.status='Active' JOIN school_sale_variant_prices p ON p.sale_variant_id=v.sale_variant_id AND p.status='Active' AND p.effective_from<=UTC_TIMESTAMP() AND (p.effective_to IS NULL OR p.effective_to>UTC_TIMESTAMP()) LEFT JOIN school_sale_book_details b ON b.sale_item_id=i.sale_item_id WHERE v.status='Active' AND v.sale_variant_id IN ({$marks}) ORDER BY v.sale_variant_id,p.sale_variant_price_id FOR UPDATE");
            $catalogStmt->execute(array_keys($requested));
            $catalog = [];
            foreach ($catalogStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $variantId = (int)$row['sale_variant_id'];
                if (isset($catalog[$variantId])) throw new RuntimeException('A selected school item has conflicting active prices.');
                $this->assertApplicable($row, $student);
                if ($row['item_type_code'] === 'BOOK' && trim((string)($row['book_title'] ?? '')) === '') throw new RuntimeException('A selected book is missing required catalog details.');
                $catalog[$variantId] = $row;
            }
            if (count($catalog) !== count($requested)) throw new RuntimeException('One or more selected school items are no longer available.');

            $lines=[]; $total=0.0;
            foreach ($requested as $variantId=>$quantity) { $row=$catalog[$variantId]; $lineTotal=round((float)$row['amount']*$quantity,2); $total+=$lineTotal; $lines[]=[$row,$quantity,$lineTotal]; }
            $total=round($total,2);
            if ($total<=0 || $cashReceived<$total) throw new RuntimeException('Cash received cannot be less than the school-sale total.');

            $receipt=(new OfficialReceiptService($this->pdo))->reserve();
            $correlationId=$this->uuidV4();
            $transactionNumber='CS-'.gmdate('Ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $change=round($cashReceived-$total,2);
            $transactionStmt=$this->pdo->prepare("INSERT INTO cashier_transactions (transaction_number,transaction_type,student_id,cashier_user_id,academic_year_snapshot,semester_snapshot,fee_total,school_sale_total,grand_total,cash_received,change_amount,official_receipt_number,idempotency_key,correlation_id,status) VALUES (?,'SCHOOL_SALE',?,?,?,?,0,?,?,?,?,?,?,?,'Completed')");
            $transactionStmt->execute([$transactionNumber,$studentId,$cashierId,$student['academic_year']??null,$student['semester']??null,$total,$total,$cashReceived,$change,$receipt,$idempotencyKey,$correlationId]);
            $transactionId=(int)$this->pdo->lastInsertId();

            $saleStmt=$this->pdo->prepare('INSERT INTO cash_sales (cashier_transaction_id,student_id,cashier_id,receipt_number,academic_year_snapshot,year_level_snapshot,total_amount,cash_received,change_amount,remarks) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $saleStmt->execute([$transactionId,$studentId,$cashierId,$receipt,$student['academic_year']??null,$student['year_level']??null,$total,$cashReceived,$change,$remarks!==''?$remarks:null]);
            $saleId=(int)$this->pdo->lastInsertId();

            $lineStmt=$this->pdo->prepare("INSERT INTO cash_sale_items (cash_sale_id,sale_item_id,sale_variant_id,sale_variant_price_id,sale_category_id,category_name_snapshot,category_code_snapshot,item_type_code_snapshot,item_type_name_snapshot,item_name_snapshot,item_code_snapshot,variant_code_snapshot,variant_name_snapshot,size_label_snapshot,unit_price_snapshot,price_effective_from_snapshot,price_effective_to_snapshot,applicability_mode_snapshot,student_program_snapshot,student_year_level_snapshot,applicability_result_snapshot,book_title_snapshot,book_author_snapshot,book_publisher_snapshot,book_edition_snapshot,book_isbn_snapshot,quantity,line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'MATCHED',?,?,?,?,?,?,?)");
            foreach ($lines as [$row,$quantity,$lineTotal]) $lineStmt->execute([$saleId,$row['sale_item_id'],$row['sale_variant_id'],$row['sale_variant_price_id'],$row['sale_category_id'],$row['category_name'],$row['category_code'],$row['item_type_code'],$row['item_type_name'],$row['item_name'],$row['item_code'],$row['variant_code'],$row['variant_name'],$row['size_label'],$row['amount'],$row['effective_from'],$row['effective_to'],$row['applicability_mode'],$student['program_code'],$student['year_level'],$row['book_title'],$row['book_author'],$row['book_publisher'],$row['book_edition'],$row['book_isbn'],$quantity,$lineTotal]);

            $this->pdo->commit();
            return ['cash_sale_id'=>$saleId,'receipt_number'=>$receipt,'total'=>$total,'change'=>$change,'duplicate'=>false];
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    private function assertApplicable(array $item, array $student): void
    {
        $stmt=$this->pdo->prepare("SELECT program_code,year_level FROM school_sale_item_applicability WHERE sale_item_id=? AND status='Active' FOR UPDATE");
        $stmt->execute([(int)$item['sale_item_id']]); $assignments=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($item['applicability_mode']==='ALL' && $assignments===[]) return;
        if ($item['applicability_mode']!=='RESTRICTED' || !$student['program_code'] || !$student['year_level']) throw new RuntimeException('A selected school item is not applicable to this student.');
        foreach ($assignments as $assignment) {
            $programMatches=$assignment['program_code']===null || strtoupper(trim($assignment['program_code']))===$student['program_code'];
            $yearMatches=$assignment['year_level']===null || trim($assignment['year_level'])===$student['year_level'];
            if ($programMatches && $yearMatches) return;
        }
        throw new RuntimeException('A selected school item is not applicable to this student.');
    }

    private function uuidV4(): string
    {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40); $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
    }
}
