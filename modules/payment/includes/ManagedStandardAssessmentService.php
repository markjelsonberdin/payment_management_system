<?php
declare(strict_types=1);

require_once __DIR__ . '/BillingStudentContext.php';
require_once __DIR__ . '/ManagedAssessmentPreviewResult.php';

/** Read-only managed-assessment decision and preview engine. */
final class ManagedStandardAssessmentService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Reject caller-supplied IDs that are not currently selectable reviewable
     * versions for this exact context. Monetary and fee metadata remain
     * server-resolved; callers may submit only an approved selection.
     * @param list<int|string> $selectedReviewableFeeVersionIds
     * @return list<int>
     */
    public function validatedReviewableSelection(BillingStudentContext $context, array $selectedReviewableFeeVersionIds): array
    {
        $selected = $this->normaliseSelection($selectedReviewableFeeVersionIds);
        if (!$selected) return [];
        $preview = $this->preview($context, [])->toArray();
        $allowed = [];
        foreach ($preview['items'] as $item) {
            if (in_array($item['classification'], ['REVIEWABLE_STANDARD', 'ONE_TIME_ELIGIBLE'], true)) {
                $allowed[(string) $item['fee_version_id']] = true;
            }
        }
        foreach ($selected as $id => $_) {
            if (!isset($allowed[$id])) throw new InvalidArgumentException('Selected fee version is not reviewable for this billing context.');
        }
        return array_map('intval', array_keys($selected));
    }

    /** @param list<int|string> $selectedReviewableFeeVersionIds */
    public function preview(BillingStudentContext $context, array $selectedReviewableFeeVersionIds = []): ManagedAssessmentPreviewResult
    {
        $selected = $this->normaliseSelection($selectedReviewableFeeVersionIds);
        $base = ['student' => ['student_id' => $context->studentId, 'student_number' => $context->studentNumber, 'full_name' => $context->fullName, 'financially_eligible' => $context->financiallyEligible, 'eligibility_source' => $context->eligibilitySource],
            'term' => ['academic_year' => $context->academicYear, 'semester' => $context->semester], 'legacy_conflict' => null, 'canonical_billing' => null, 'items' => [],
            'summary' => ['candidate_count' => 0, 'new_count' => 0, 'existing_count' => 0, 'excluded_count' => 0, 'review_count' => 0, 'automatic_new_amount' => 0.0, 'selected_reviewable_amount' => 0.0, 'projected_new_amount' => 0.0]];
        if (!$context->financiallyEligible || $context->eligibilitySource === '') { $base['status'] = 'InvalidContext'; return new ManagedAssessmentPreviewResult($base); }

        $canonical = $this->canonicalBilling($context);
        $base['canonical_billing'] = $canonical;
        $legacy = $this->legacyConflict($context);
        if ($legacy) $base['legacy_conflict'] = $legacy;

        foreach ($this->candidateVersions($context) as $candidate) {
            $base['summary']['candidate_count']++;
            $item = $this->classify($candidate, $context, $canonical, $selected);
            $base['items'][] = $item;
            $classification = $item['classification'];
            if (in_array($classification, ['REVIEWABLE_STANDARD', 'ONE_TIME_ELIGIBLE', 'ONE_TIME_HISTORY_AMBIGUOUS'], true)) $base['summary']['review_count']++;
            if ($classification === 'ALREADY_ASSESSED' || $classification === 'ONE_TIME_ALREADY_ASSESSED') $base['summary']['existing_count']++;
            if (in_array($classification, ['NEW_REQUIRED_STANDARD', 'REVIEWABLE_STANDARD', 'ONE_TIME_ELIGIBLE'], true) && !empty($item['selected_for_preview'])) {
                $base['summary']['new_count']++;
                if ($classification === 'NEW_REQUIRED_STANDARD') $base['summary']['automatic_new_amount'] += $item['amount'];
                else $base['summary']['selected_reviewable_amount'] += $item['amount'];
            } elseif (!in_array($classification, ['ALREADY_ASSESSED', 'ONE_TIME_ALREADY_ASSESSED'], true)) $base['summary']['excluded_count']++;
        }
        $base['summary']['projected_new_amount'] = $base['summary']['automatic_new_amount'] + $base['summary']['selected_reviewable_amount'];
        foreach (['automatic_new_amount', 'selected_reviewable_amount', 'projected_new_amount'] as $field) $base['summary'][$field] = round($base['summary'][$field], 2);
        $base['status'] = $this->status($base, $legacy !== null);
        return new ManagedAssessmentPreviewResult($base);
    }

    /** @return list<array<string,mixed>> */
    private function candidateVersions(BillingStudentContext $context): array
    {
        $stmt = $this->pdo->prepare("SELECT fv.fee_version_id, fv.fee_id, f.fee_code, f.fee_name, fv.amount, fv.behavior, fv.is_required, ft.type_code, ft.type_name, fg.group_code, fg.group_name
            FROM fee_versions fv JOIN fees f ON f.fee_id=fv.fee_id
            LEFT JOIN fee_types ft ON ft.fee_type_id=f.fee_type_id LEFT JOIN fee_groups fg ON fg.fee_group_id=ft.fee_group_id
            WHERE f.identity_status='Active' AND fv.effective_status='Active' AND fv.academic_year=? AND fv.semester=? ORDER BY fv.fee_version_id");
        $stmt->execute([$context->academicYear, $context->semester]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['fee_version_id'] = (int) $row['fee_version_id']; $row['fee_id'] = (int) $row['fee_id']; $row['amount'] = (float) $row['amount']; $row['is_required'] = (bool) $row['is_required']; $row['applicability'] = $this->applicability((int) $row['fee_version_id']); }
        unset($row); return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function applicability(int $versionId): array { $q=$this->pdo->prepare('SELECT course,year_level,applies_to_all_courses,applies_to_all_year_levels FROM fee_applicability WHERE fee_version_id=? ORDER BY fee_applicability_id'); $q->execute([$versionId]); return $q->fetchAll(PDO::FETCH_ASSOC); }
    /** @return null|array<string,mixed> */
    private function canonicalBilling(BillingStudentContext $c): ?array { $q=$this->pdo->prepare('SELECT mah.billing_id,b.billing_type,b.billing_status,b.total_amount,b.remaining_balance FROM managed_assessment_headers mah JOIN billing b ON b.billing_id=mah.billing_id WHERE mah.student_id=? AND mah.academic_year=? AND mah.semester=?'); $q->execute([$c->studentId,$c->academicYear,$c->semester]); $r=$q->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    /** @return null|array<string,mixed> */
    private function legacyConflict(BillingStudentContext $c): ?array { $q=$this->pdo->prepare('SELECT b.billing_id,b.billing_type,b.billing_status,b.total_amount,b.remaining_balance,COUNT(bi.billing_item_id) item_count FROM billing b LEFT JOIN managed_assessment_headers mah ON mah.billing_id=b.billing_id LEFT JOIN billing_items bi ON bi.billing_id=b.billing_id WHERE b.student_id=? AND b.academic_year=? AND b.semester=? AND mah.managed_header_id IS NULL GROUP BY b.billing_id,b.billing_type,b.billing_status,b.total_amount,b.remaining_balance ORDER BY b.billing_id'); $q->execute([$c->studentId,$c->academicYear,$c->semester]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); return $rows ? ['code'=>'REVIEW_REQUIRED_LEGACY_TERM_BILLING','billing_rows'=>$rows] : null; }

    /** @param array<string,bool> $selected @param null|array<string,mixed> $canonical @return array<string,mixed> */
    private function classify(array $v, BillingStudentContext $c, ?array $canonical, array $selected): array
    {
        $item = ['fee_id'=>$v['fee_id'],'fee_version_id'=>$v['fee_version_id'],'fee_code'=>$v['fee_code'],'fee_name'=>$v['fee_name'],'fee_type'=>$v['type_code'],'fee_group'=>$v['group_code'],'amount'=>$v['amount'],'behavior'=>$v['behavior'],'is_required'=>$v['is_required'],'applicable'=>false,'classification'=>'NOT_APPLICABLE','reason'=>'Does not match the supplied billing context.','selected_for_preview'=>false];
        if ($v['amount'] <= 0 || !$v['fee_code'] || !$v['type_code'] || !$v['group_code'] || !$v['applicability']) { $item['classification']='FEE_CONFIGURATION_ERROR'; $item['reason']='Managed fee version has missing required configuration or non-positive amount.'; return $item; }
        $app = $this->matchesApplicability($v['applicability'], $c);
        if ($app === 'missing') { $item['classification']='APPLICABILITY_CONTEXT_MISSING'; $item['reason']='A required canonical program code or year level was not supplied.'; return $item; }
        if (!$app) return $item;
        $item['applicable']=true;
        if ($canonical && $this->managedItemExists((int)$canonical['billing_id'], $v['fee_version_id'])) { $item['classification']='ALREADY_ASSESSED'; $item['reason']='This managed fee version already exists on the canonical billing.'; return $item; }
        if ($v['behavior'] === 'Standard') { if ($v['is_required']) { $item['classification']='NEW_REQUIRED_STANDARD'; $item['reason']='Required Standard fee is automatically included.'; $item['selected_for_preview']=true; } else { $item['classification']='REVIEWABLE_STANDARD'; $item['reason']='Non-required Standard fee requires Accounting selection.'; $item['selected_for_preview']=isset($selected[(string)$v['fee_version_id']]); } return $item; }
        if ($v['behavior'] === 'One-Time') { $history=$this->oneTimeHistory($c->studentId,$v['fee_id'],$v['fee_name']); if ($history === 'clear') { $item['classification']='ONE_TIME_ALREADY_ASSESSED'; $item['reason']='Same fee identity is present in billing history.'; } elseif ($history === 'ambiguous') { $item['classification']='ONE_TIME_HISTORY_AMBIGUOUS'; $item['reason']='Legacy history with this fee identity has conflicting snapshots.'; } else { $item['classification']='ONE_TIME_ELIGIBLE'; $item['reason']='No prior same-fee history was found; Accounting selection is required.'; $item['selected_for_preview']=isset($selected[(string)$v['fee_version_id']]); } return $item; }
        if ($v['behavior'] === 'Optional') { $item['classification']='OPTIONAL_OPTIN_REQUIRED'; $item['reason']='Authoritative per-student opt-in evidence is unavailable.'; }
        else { $item['classification']='MANUAL_EXCLUDED_FROM_BULK'; $item['reason']='Manual fees are excluded from Bulk Standard Assessment.'; }
        return $item;
    }

    /** @param list<array<string,mixed>> $scopes */
    private function matchesApplicability(array $scopes, BillingStudentContext $c): bool|string
    {
        $missing=false;
        foreach ($scopes as $s) { $allCourse=(bool)$s['applies_to_all_courses']; $allYear=(bool)$s['applies_to_all_year_levels'];
            if (!$allCourse && (!$c->hasCanonicalProgramCode())) { $missing=true; continue; }
            if (!$allYear && $c->yearLevel===null) { $missing=true; continue; }
            if ((!$allCourse && $c->programCode !== trim((string)$s['course'])) || (!$allYear && $c->yearLevel !== trim((string)$s['year_level']))) continue;
            return true;
        } return $missing ? 'missing' : false;
    }
    private function managedItemExists(int $billingId, int $versionId): bool { $q=$this->pdo->prepare('SELECT 1 FROM billing_items WHERE billing_id=? AND fee_version_id=?'); $q->execute([$billingId,$versionId]); return (bool)$q->fetchColumn(); }
    private function oneTimeHistory(int $studentId, int $feeId, string $name): string { $q=$this->pdo->prepare('SELECT bi.fee_name,bi.fee_version_id FROM billing_items bi JOIN billing b ON b.billing_id=bi.billing_id WHERE b.student_id=? AND bi.fee_id=?'); $q->execute([$studentId,$feeId]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); if (!$rows) return 'none'; foreach($rows as $r) if ($r['fee_version_id']===null && trim((string)$r['fee_name'])!==trim($name)) return 'ambiguous'; return 'clear'; }
    /** @param list<int|string> $ids @return array<string,bool> */ private function normaliseSelection(array $ids): array { $out=[]; foreach($ids as $id) { if (!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<=0) throw new InvalidArgumentException('Invalid reviewable fee-version selection.'); $out[(string)(int)$id]=true; } return $out; }
    /** @param array<string,mixed> $result */ private function status(array $result,bool $legacy): string { if($legacy || array_filter($result['items'],static fn($i)=>$i['classification']==='ONE_TIME_HISTORY_AMBIGUOUS')) return 'ReviewRequired'; if($result['summary']['new_count']>0 && $result['summary']['existing_count']>0) return 'PartiallyAssessed'; if($result['summary']['new_count']>0 || $result['summary']['review_count']>0) return 'Ready'; if($result['summary']['existing_count']>0) return 'AlreadyAssessed'; return 'NoApplicableFees'; }
}
