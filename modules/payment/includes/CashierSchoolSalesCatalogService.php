<?php

declare(strict_types=1);

/**
 * Cashier-only read model for the authoritative School Sales catalog.
 *
 * This service intentionally has no mutation, receipt, transaction, or
 * allocation capability. Every result is re-resolved from the normalized
 * catalog tables and is suitable only for display; checkout must validate
 * again under its own controlled transaction in a later phase.
 */
final class CashierSchoolSalesCatalogException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

final class CashierSchoolSalesCatalogService
{
    public function __construct(private readonly PDO $pdo, private readonly ?DateTimeImmutable $clock = null)
    {
    }

    /** @return array<int,array{category_id:int,category_code:string,category_name:string}> */
    public function categoriesForStudent(int $studentId): array
    {
        $student = $this->studentContext($studentId);
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT c.sale_category_id, c.category_code, c.category_name '
            . $this->eligibleItemFromWhere(null, null, null)
            . ' ORDER BY c.category_name, c.sale_category_id'
        );
        $stmt->execute($this->eligibilityBindings($student, null, null, null));
        return array_map(static fn(array $row): array => [
            'category_id' => (int) $row['sale_category_id'],
            'category_code' => $row['category_code'],
            'category_name' => $row['category_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,page:int,per_page:int,total:int}
     */
    public function listSellableItems(
        int $studentId,
        ?string $search = null,
        ?int $categoryId = null,
        ?int $itemTypeId = null,
        int $page = 1,
        int $perPage = 25
    ): array {
        $student = $this->studentContext($studentId);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $bindings = $this->eligibilityBindings($student, $search, $categoryId, $itemTypeId);

        $count = $this->pdo->prepare('SELECT COUNT(*) ' . $this->eligibleItemFromWhere($search, $categoryId, $itemTypeId));
        $count->execute($bindings);
        $total = (int) $count->fetchColumn();

        $ids = $this->pdo->prepare(
            'SELECT i.sale_item_id ' . $this->eligibleItemFromWhere($search, $categoryId, $itemTypeId)
            . ' ORDER BY c.sort_order, c.category_name, i.item_name, i.sale_item_id LIMIT :limit OFFSET :offset'
        );
        $this->executeWithPaging($ids, $bindings, $perPage, ($page - 1) * $perPage);
        $itemIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

        return ['items' => $this->itemsByIds($itemIds), 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function sellableItemDetails(int $studentId, int $itemId): array
    {
        if ($itemId <= 0) throw new InvalidArgumentException('INVALID_ITEM_ID');
        // Re-run the authoritative eligibility predicate so an arbitrary item
        // ID cannot bypass lifecycle, price, Book, or applicability checks.
        $student = $this->studentContext($studentId);
        $bindings = $this->eligibilityBindings($student, null, null, null);
        $bindings[':item_id'] = $itemId;
        $check = $this->pdo->prepare('SELECT i.sale_item_id ' . $this->eligibleItemFromWhere(null, null, null) . ' AND i.sale_item_id = :item_id');
        $check->execute($bindings);
        if ($check->fetchColumn() === false) throw new CashierSchoolSalesCatalogException('ITEM_NOT_SELLABLE');
        $items = $this->itemsByIds([$itemId]);
        if ($items === []) throw new CashierSchoolSalesCatalogException('ITEM_NOT_SELLABLE');
        return $items[0];
    }

    /** @return array{student_id:int,program_code:?string,year_level:?string} */
    private function studentContext(int $studentId): array
    {
        if ($studentId <= 0) throw new InvalidArgumentException('INVALID_STUDENT_ID');
        $stmt = $this->pdo->prepare('SELECT student_id, course, year_level FROM students WHERE student_id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new CashierSchoolSalesCatalogException('STUDENT_NOT_FOUND');
        $program = strtoupper(trim((string) ($student['course'] ?? '')));
        $year = trim((string) ($student['year_level'] ?? ''));
        return ['student_id' => (int) $student['student_id'], 'program_code' => $program === '' ? null : $program, 'year_level' => $year === '' ? null : $year];
    }

    /** @param array{program_code:?string,year_level:?string} $student @return array<string,mixed> */
    private function eligibilityBindings(array $student, ?string $search, ?int $categoryId, ?int $itemTypeId): array
    {
        $bindings = [':now' => $this->now()];
        $bindings[':student_program'] = $student['program_code'];
        $bindings[':student_year'] = $student['year_level'];
        if ($search !== null && trim($search) !== '') $bindings[':search'] = '%' . trim($search) . '%';
        if ($categoryId !== null) $bindings[':category_id'] = $categoryId;
        if ($itemTypeId !== null) $bindings[':item_type_id'] = $itemTypeId;
        return $bindings;
    }

    private function eligibleItemFromWhere(?string $search, ?int $categoryId, ?int $itemTypeId): string
    {
        // A restricted item is never eligible until both trusted context fields
        // are available. The endpoint never accepts those fields from a client.
        $hasContext = '(context.student_program IS NOT NULL AND context.student_year IS NOT NULL)';
        $where = " FROM school_sale_items i
            JOIN school_sale_categories c ON c.sale_category_id = i.sale_category_id AND c.status = 'Active'
            JOIN school_sale_item_types t ON t.sale_item_type_id = i.sale_item_type_id AND t.status = 'Active'
            CROSS JOIN (SELECT :now AS current_at, :student_program AS student_program, :student_year AS student_year) context
            WHERE i.status = 'Active'
              AND (i.applicability_mode = 'ALL' AND NOT EXISTS (
                    SELECT 1 FROM school_sale_item_applicability aa
                    WHERE aa.sale_item_id = i.sale_item_id AND aa.status = 'Active'
                  ) OR (i.applicability_mode = 'RESTRICTED' AND {$hasContext} AND EXISTS (
                    SELECT 1 FROM school_sale_item_applicability a
                    WHERE a.sale_item_id = i.sale_item_id AND a.status = 'Active'
                      AND (a.program_code IS NULL OR a.program_code = context.student_program)
                      AND (a.year_level IS NULL OR a.year_level = context.student_year)
                  )))
              AND (t.type_code <> 'BOOK' OR EXISTS (
                    SELECT 1 FROM school_sale_book_details b
                    WHERE b.sale_item_id = i.sale_item_id
                      AND TRIM(b.book_title) <> ''
                      AND (b.author IS NULL OR TRIM(b.author) <> '')
                      AND (b.publisher IS NULL OR TRIM(b.publisher) <> '')
                      AND (b.edition IS NULL OR TRIM(b.edition) <> '')
                      AND (b.isbn IS NULL OR (LENGTH(TRIM(b.isbn)) BETWEEN 10 AND 32 AND b.isbn REGEXP '^[0-9Xx -]+$'))
                      AND (b.notes IS NULL OR TRIM(b.notes) <> '')
                  ))
              AND EXISTS (SELECT 1 FROM school_sale_item_variants v
                          WHERE v.sale_item_id = i.sale_item_id AND v.status = 'Active')
              AND NOT EXISTS (
                    SELECT 1 FROM school_sale_item_variants invalid_variant
                    WHERE invalid_variant.sale_item_id = i.sale_item_id AND invalid_variant.status = 'Active'
                      AND (SELECT COUNT(*) FROM school_sale_variant_prices cp
                           WHERE cp.sale_variant_id = invalid_variant.sale_variant_id
                             AND cp.status = 'Active' AND cp.effective_from <= context.current_at
                             AND (cp.effective_to IS NULL OR cp.effective_to > context.current_at)) <> 1
                  )
              AND NOT EXISTS (
                    SELECT 1 FROM school_sale_variant_prices p1
                    JOIN school_sale_variant_prices p2 ON p2.sale_variant_id = p1.sale_variant_id
                         AND p2.sale_variant_price_id > p1.sale_variant_price_id AND p2.status = 'Active'
                         AND p1.effective_from < COALESCE(p2.effective_to, '9999-12-31 23:59:59')
                         AND p2.effective_from < COALESCE(p1.effective_to, '9999-12-31 23:59:59')
                    JOIN school_sale_item_variants ov ON ov.sale_variant_id = p1.sale_variant_id
                    WHERE ov.sale_item_id = i.sale_item_id AND p1.status = 'Active'
                  )";
        if ($search !== null && trim($search) !== '') $where .= ' AND (i.item_name LIKE :search OR i.item_code LIKE :search)';
        if ($categoryId !== null) $where .= ' AND i.sale_category_id = :category_id';
        if ($itemTypeId !== null) $where .= ' AND i.sale_item_type_id = :item_type_id';
        return $where;
    }

    /** @param array<int,int> $itemIds @return array<int,array<string,mixed>> */
    private function itemsByIds(array $itemIds): array
    {
        if ($itemIds === []) return [];
        $marks = implode(',', array_fill(0, count($itemIds), '?'));
        $sql = "SELECT i.sale_item_id, i.item_code, i.item_name, i.description, i.applicability_mode,
                       c.sale_category_id, c.category_code, c.category_name,
                       t.sale_item_type_id, t.type_code, t.type_name,
                       b.book_title, b.author, b.publisher, b.edition, b.isbn
                FROM school_sale_items i
                JOIN school_sale_categories c ON c.sale_category_id = i.sale_category_id
                JOIN school_sale_item_types t ON t.sale_item_type_id = i.sale_item_type_id
                LEFT JOIN school_sale_book_details b ON b.sale_item_id = i.sale_item_id
                WHERE i.sale_item_id IN ({$marks})
                ORDER BY c.sort_order, c.category_name, i.item_name, i.sale_item_id";
        $stmt = $this->pdo->prepare($sql); $stmt->execute($itemIds);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['sale_item_id'];
            $items[$id] = ['item_id' => $id, 'item_code' => $row['item_code'], 'item_name' => $row['item_name'],
                'description' => $row['description'], 'applicability_mode' => $row['applicability_mode'], 'sellable' => true,
                'category' => ['category_id' => (int) $row['sale_category_id'], 'category_code' => $row['category_code'], 'category_name' => $row['category_name']],
                'item_type' => ['item_type_id' => (int) $row['sale_item_type_id'], 'type_code' => $row['type_code'], 'type_name' => $row['type_name']],
                'variants' => []];
            if ($row['type_code'] === 'BOOK') $items[$id]['book'] = array_filter(['title' => $row['book_title'], 'author' => $row['author'], 'publisher' => $row['publisher'], 'edition' => $row['edition'], 'isbn' => $row['isbn']], static fn($value): bool => $value !== null);
        }
        $variants = $this->pdo->prepare("SELECT v.sale_variant_id, v.sale_item_id, v.variant_code, v.variant_name, v.sku, v.size_label,
                    p.sale_variant_price_id, p.amount, p.currency, p.effective_from, p.effective_to
             FROM school_sale_item_variants v JOIN school_sale_variant_prices p ON p.sale_variant_id = v.sale_variant_id
             WHERE v.sale_item_id IN ({$marks}) AND v.status = 'Active' AND p.status = 'Active'
               AND p.effective_from <= ? AND (p.effective_to IS NULL OR p.effective_to > ?)
             ORDER BY v.sort_order, v.variant_name, v.sale_variant_id");
        $params = array_merge($itemIds, [$this->now(), $this->now()]); $variants->execute($params);
        foreach ($variants->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(int) $row['sale_item_id']]['variants'][] = ['variant_id' => (int) $row['sale_variant_id'], 'variant_code' => $row['variant_code'],
                'variant_name' => $row['variant_name'], 'sku' => $row['sku'], 'size_label' => $row['size_label'],
                'current_price' => ['price_id' => (int) $row['sale_variant_price_id'], 'amount' => number_format((float) $row['amount'], 2, '.', ''),
                    'currency' => $row['currency'], 'effective_from' => $row['effective_from'], 'effective_to' => $row['effective_to']]];
        }
        return array_values($items);
    }

    /** @param array<string,mixed> $bindings */
    private function executeWithPaging(PDOStatement $statement, array $bindings, int $limit, int $offset): void
    {
        foreach ($bindings as $key => $value) $statement->bindValue($key, $value);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
    }

    private function now(): string { return ($this->clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
}
