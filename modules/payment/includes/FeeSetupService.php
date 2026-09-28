<?php

declare(strict_types=1);

final class FeeSetupException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class FeeSetupService
{
    private const IDENTITY_ACTIVE = 'Active';
    private const IDENTITY_ARCHIVED = 'Archived';
    private const VERSION_DRAFT = 'Draft';
    private const VERSION_ACTIVE = 'Active';
    private const VERSION_ARCHIVED = 'Archived';
    private const BEHAVIORS = ['Standard', 'One-Time', 'Optional', 'Manual'];
    private const SEMESTERS = ['1st', '2nd', 'Summer'];

    /** @var null|callable(string,string):void */
    private $auditLogger;

    public function __construct(private readonly PDO $pdo, ?callable $auditLogger = null)
    {
        $this->auditLogger = $auditLogger;
    }

    public function taxonomy(): array
    {
        $stmt = $this->pdo->query(
            "SELECT fg.fee_group_id, fg.group_code, fg.group_name, fg.status, fg.sort_order,
                    ft.fee_type_id, ft.type_code, ft.type_name, ft.status AS type_status, ft.sort_order AS type_sort_order
             FROM fee_groups fg
             LEFT JOIN fee_types ft ON ft.fee_group_id = fg.fee_group_id AND ft.status = 'Active'
             WHERE fg.status = 'Active'
             ORDER BY fg.sort_order, fg.fee_group_id, ft.sort_order, ft.fee_type_id"
        );

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupId = (int) $row['fee_group_id'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'fee_group_id' => $groupId,
                    'group_code' => $row['group_code'],
                    'group_name' => $row['group_name'],
                    'status' => $row['status'],
                    'types' => [],
                ];
            }
            if ($row['fee_type_id'] !== null) {
                $groups[$groupId]['types'][] = [
                    'fee_type_id' => (int) $row['fee_type_id'],
                    'type_code' => $row['type_code'],
                    'type_name' => $row['type_name'],
                    'status' => $row['type_status'],
                ];
            }
        }

        return array_values($groups);
    }

    public function catalog(): array
    {
        $stmt = $this->pdo->query(
            "SELECT f.fee_id, f.fee_code, f.fee_name, f.fee_type_id, f.identity_status,
                    ft.type_code, ft.type_name, fg.group_code, fg.group_name,
                    COUNT(fv.fee_version_id) AS version_count,
                    SUM(CASE WHEN fv.effective_status = 'Draft' THEN 1 ELSE 0 END) AS draft_count,
                    SUM(CASE WHEN fv.effective_status = 'Active' THEN 1 ELSE 0 END) AS active_count,
                    MAX(CASE WHEN fv.effective_status = 'Active' THEN fv.version_no END) AS active_version_no,
                    MAX(CASE WHEN fv.effective_status = 'Active' THEN fv.academic_year END) AS active_academic_year,
                    MAX(CASE WHEN fv.effective_status = 'Active' THEN fv.semester END) AS active_semester,
                    MAX(CASE WHEN fv.effective_status = 'Active' THEN fv.amount END) AS active_amount,
                    MAX(CASE WHEN fv.effective_status = 'Draft' THEN fv.version_no END) AS latest_draft_version_no,
                    MAX(CASE WHEN fv.activated_at IS NOT NULL THEN 1 ELSE 0 END) AS identity_locked
             FROM fees f
             JOIN fee_types ft ON ft.fee_type_id = f.fee_type_id
             JOIN fee_groups fg ON fg.fee_group_id = ft.fee_group_id
             LEFT JOIN fee_versions fv ON fv.fee_id = f.fee_id
             WHERE f.fee_code IS NOT NULL
               AND f.fee_type_id IS NOT NULL
               AND f.identity_status = 'Active'
             GROUP BY f.fee_id, f.fee_code, f.fee_name, f.fee_type_id, f.identity_status,
                      ft.type_code, ft.type_name, fg.group_code, fg.group_name
             ORDER BY fg.sort_order, ft.sort_order, f.fee_name, f.fee_id"
        );

        return array_map([$this, 'castCatalogRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function fee(int $feeId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.fee_id, f.fee_code, f.fee_name, f.fee_type_id, f.identity_status,
                    f.status AS legacy_status, ft.type_code, ft.type_name, fg.group_code, fg.group_name
             FROM fees f
             JOIN fee_types ft ON ft.fee_type_id = f.fee_type_id
             JOIN fee_groups fg ON fg.fee_group_id = ft.fee_group_id
             WHERE f.fee_id = ? AND f.fee_code IS NOT NULL AND f.identity_status IS NOT NULL"
        );
        $stmt->execute([$this->positiveId($feeId, 'fee_id')]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fee) {
            throw new FeeSetupException('FEE_NOT_FOUND', 'The managed fee identity was not found.', 404);
        }

        $versions = $this->pdo->prepare(
            "SELECT fee_version_id, fee_id, version_no, academic_year, semester, amount, behavior,
                    is_required, effective_status, description, created_by, created_at, updated_by,
                    updated_at, activated_at, archived_at
             FROM fee_versions WHERE fee_id = ? ORDER BY version_no DESC"
        );
        $versions->execute([$feeId]);
        $versionRows = $versions->fetchAll(PDO::FETCH_ASSOC);
        if ($versionRows) {
            $ids = array_map(static fn(array $row): int => (int) $row['fee_version_id'], $versionRows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $scopes = $this->pdo->prepare(
                "SELECT fee_applicability_id, fee_version_id, course, year_level,
                        applies_to_all_courses, applies_to_all_year_levels
                 FROM fee_applicability WHERE fee_version_id IN ($placeholders)
                 ORDER BY fee_version_id, normalized_course_scope, normalized_year_scope"
            );
            $scopes->execute($ids);
            $byVersion = [];
            foreach ($scopes->fetchAll(PDO::FETCH_ASSOC) as $scope) {
                $scope['fee_applicability_id'] = (int) $scope['fee_applicability_id'];
                $scope['fee_version_id'] = (int) $scope['fee_version_id'];
                $scope['applies_to_all_courses'] = (bool) $scope['applies_to_all_courses'];
                $scope['applies_to_all_year_levels'] = (bool) $scope['applies_to_all_year_levels'];
                $byVersion[$scope['fee_version_id']][] = $scope;
            }
            foreach ($versionRows as &$version) {
                $version['fee_version_id'] = (int) $version['fee_version_id'];
                $version['fee_id'] = (int) $version['fee_id'];
                $version['version_no'] = (int) $version['version_no'];
                $version['amount'] = (float) $version['amount'];
                $version['is_required'] = (bool) $version['is_required'];
                $version['applicability'] = $byVersion[$version['fee_version_id']] ?? [];
            }
            unset($version);
        }

        $fee['fee_id'] = (int) $fee['fee_id'];
        $fee['fee_type_id'] = (int) $fee['fee_type_id'];
        $fee['identity_locked'] = array_reduce(
            $versionRows,
            static fn(bool $locked, array $version): bool => $locked || $version['activated_at'] !== null,
            false
        );
        $fee['versions'] = $versionRows;
        return $fee;
    }

    public function archives(): array
    {
        $identities = $this->pdo->query(
            "SELECT f.fee_id, f.fee_code, f.fee_name, f.identity_status, f.updated_at AS archived_at,
                    ft.type_name, fg.group_name, COUNT(fv.fee_version_id) AS version_count
             FROM fees f
             JOIN fee_types ft ON ft.fee_type_id = f.fee_type_id
             JOIN fee_groups fg ON fg.fee_group_id = ft.fee_group_id
             LEFT JOIN fee_versions fv ON fv.fee_id = f.fee_id
             WHERE f.identity_status = 'Archived'
             GROUP BY f.fee_id, f.fee_code, f.fee_name, f.identity_status, f.updated_at, ft.type_name, fg.group_name
             ORDER BY f.updated_at DESC, f.fee_id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $versions = $this->pdo->query(
            "SELECT fv.fee_version_id, fv.fee_id, fv.version_no, fv.academic_year, fv.semester,
                    fv.amount, fv.behavior, fv.is_required, fv.activated_at, fv.archived_at,
                    f.fee_code, f.fee_name, ft.type_name, fg.group_name
             FROM fee_versions fv
             JOIN fees f ON f.fee_id = fv.fee_id
             JOIN fee_types ft ON ft.fee_type_id = f.fee_type_id
             JOIN fee_groups fg ON fg.fee_group_id = ft.fee_group_id
             WHERE fv.effective_status = 'Archived'
             ORDER BY fv.archived_at DESC, fv.fee_version_id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($identities as &$identity) {
            $identity['fee_id'] = (int) $identity['fee_id'];
            $identity['version_count'] = (int) $identity['version_count'];
        }
        foreach ($versions as &$version) {
            $version['fee_version_id'] = (int) $version['fee_version_id'];
            $version['fee_id'] = (int) $version['fee_id'];
            $version['version_no'] = (int) $version['version_no'];
            $version['amount'] = (float) $version['amount'];
            $version['is_required'] = (bool) $version['is_required'];
            $version['applicability'] = $this->version($version['fee_version_id'])['applicability'];
        }
        return ['identities' => $identities, 'versions' => $versions];
    }

    public function legacyFees(): array
    {
        return $this->pdo->query(
            "SELECT f.fee_id, f.fee_name, f.category_id, fc.category_name,
                    f.default_amount, f.is_required, f.status, f.description, f.created_at, f.updated_at
             FROM fees f
             LEFT JOIN fee_categories fc ON fc.category_id = f.category_id
             WHERE f.fee_type_id IS NULL AND f.fee_code IS NULL AND f.identity_status IS NULL
             ORDER BY fc.priority_order, f.fee_name, f.fee_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createIdentity(array $input, int $actorId): array
    {
        $actorId = $this->actorId($actorId);
        $code = $this->feeCode($input['fee_code'] ?? null);
        $name = $this->feeName($input['fee_name'] ?? null);
        $typeId = $this->positiveId($input['fee_type_id'] ?? 0, 'fee_type_id');
        $this->requireActiveType($typeId);
        $this->requireIdentityActive($input['identity_status'] ?? self::IDENTITY_ACTIVE);
        $this->assertFeeCodeAvailable($code);

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "INSERT INTO fees
                    (fee_code, category_id, fee_type_id, fee_name, default_amount, is_required, status, identity_status, description)
                 VALUES (?, NULL, ?, ?, 0.00, 0, 'Inactive', 'Active', ?)"
            );
            $stmt->execute([$code, $typeId, $name, $this->description($input['description'] ?? null)]);
            $feeId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollback();
            throw $this->mapDatabaseError($e);
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $this->audit('fee_identity_created', "Created managed fee #$feeId ($code) by user #$actorId.");
        return $this->fee($feeId);
    }

    public function updateIdentity(int $feeId, array $input, int $actorId): array
    {
        $feeId = $this->positiveId($feeId, 'fee_id');
        $actorId = $this->actorId($actorId);
        try {
            $this->pdo->beginTransaction();
            $fee = $this->lockManagedFee($feeId);
            $this->assertIdentityActive($fee);
            if ($this->identityHasActivationHistory($feeId)) {
                throw new FeeSetupException(
                    'IDENTITY_LOCKED',
                    'Fee Code, Fee Name, Fee Type, and Fee Group are locked after the first activation.',
                    409
                );
            }

            foreach (['category_id', 'default_amount', 'is_required', 'status', 'identity_status'] as $forbiddenField) {
                if (array_key_exists($forbiddenField, $input)) {
                    throw new FeeSetupException(
                        'VALIDATION_FAILED',
                        "$forbiddenField cannot be changed through the managed fee identity update."
                    );
                }
            }

            if (array_key_exists('fee_code', $input)) {
                $submittedCode = $this->feeCode($input['fee_code']);
                if ($submittedCode !== $fee['fee_code']) {
                    throw new FeeSetupException('FEE_CODE_IMMUTABLE', 'Fee codes cannot be changed after creation.', 409);
                }
            }

            $name = array_key_exists('fee_name', $input) ? $this->feeName($input['fee_name']) : $fee['fee_name'];
            $typeId = array_key_exists('fee_type_id', $input)
                ? $this->positiveId($input['fee_type_id'], 'fee_type_id')
                : (int) $fee['fee_type_id'];
            $this->requireActiveType($typeId);

            if ($typeId !== (int) $fee['fee_type_id']) {
                $count = $this->pdo->prepare('SELECT COUNT(*) FROM fee_versions WHERE fee_id = ?');
                $count->execute([$feeId]);
                if ((int) $count->fetchColumn() > 0) {
                    throw new FeeSetupException('FEE_TYPE_LOCKED', 'The fee type cannot be changed after a version exists.', 409);
                }
            }

            $stmt = $this->pdo->prepare('UPDATE fees SET fee_name = ?, fee_type_id = ? WHERE fee_id = ?');
            $stmt->execute([$name, $typeId, $feeId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $changes = [];
        if ($name !== $fee['fee_name']) {
            $changes[] = "name corrected from '{$fee['fee_name']}' to '$name'";
        }
        if ($typeId !== (int) $fee['fee_type_id']) {
            $changes[] = "type corrected from #{$fee['fee_type_id']} to #$typeId";
        }
        $detail = $changes ? implode('; ', $changes) : 'no material identity changes';
        $this->audit('fee_identity_updated', "Fee #$feeId $detail by user #$actorId.");
        return $this->fee($feeId);
    }

    public function archiveIdentity(int $feeId, int $actorId): array
    {
        $feeId = $this->positiveId($feeId, 'fee_id');
        $actorId = $this->actorId($actorId);
        try {
            $this->pdo->beginTransaction();
            $fee = $this->lockManagedFee($feeId);
            $this->assertIdentityActive($fee);
            $open = $this->pdo->prepare("SELECT COUNT(*) FROM fee_versions WHERE fee_id = ? AND effective_status IN ('Draft','Active')");
            $open->execute([$feeId]);
            if ((int) $open->fetchColumn() > 0) {
                throw new FeeSetupException(
                    'IDENTITY_HAS_OPEN_VERSIONS',
                    'Archive all Draft and Active versions before archiving this fee identity.',
                    409
                );
            }
            $this->pdo->prepare("UPDATE fees SET identity_status = 'Archived' WHERE fee_id = ?")->execute([$feeId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->audit('fee_identity_archived', "Archived managed fee #$feeId by user #$actorId.");
        return $this->fee($feeId);
    }

    public function createDraftVersion(int $feeId, array $input, int $actorId): array
    {
        $feeId = $this->positiveId($feeId, 'fee_id');
        $actorId = $this->actorId($actorId);
        $version = $this->versionPayload($input);
        $scopes = $this->applicability($input['applicability'] ?? null);

        try {
            $this->pdo->beginTransaction();
            $fee = $this->lockManagedFee($feeId);
            $this->assertIdentityActive($fee);
            $this->assertVersionTermAvailable($feeId, $version['academic_year'], $version['semester']);
            $next = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM fee_versions WHERE fee_id = ?');
            $next->execute([$feeId]);
            $versionNo = (int) $next->fetchColumn();
            $versionId = $this->insertDraft($feeId, $versionNo, $version, $actorId);
            $this->insertApplicability($versionId, $scopes);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollback();
            throw $this->mapDatabaseError($e);
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $this->audit('fee_version_created', "Created Draft version #$versionNo for fee #$feeId by user #$actorId.");
        return $this->version($versionId);
    }

    public function updateDraftVersion(int $versionId, array $input, int $actorId): array
    {
        $versionId = $this->positiveId($versionId, 'fee_version_id');
        $actorId = $this->actorId($actorId);
        $payload = $this->versionPayload($input);
        try {
            $this->pdo->beginTransaction();
            $version = $this->lockVersionWithParent($versionId);
            $this->assertDraft($version);
            $this->assertVersionTermAvailable((int) $version['fee_id'], $payload['academic_year'], $payload['semester'], $versionId);
            $stmt = $this->pdo->prepare(
                "UPDATE fee_versions SET academic_year=?, semester=?, amount=?, behavior=?, is_required=?,
                        description=?, updated_by=? WHERE fee_version_id=?"
            );
            $stmt->execute([
                $payload['academic_year'], $payload['semester'], $payload['amount'], $payload['behavior'],
                $payload['is_required'], $payload['description'], $actorId, $versionId,
            ]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollback();
            throw $this->mapDatabaseError($e);
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->audit('fee_version_updated', "Updated Draft fee version #$versionId by user #$actorId.");
        return $this->version($versionId);
    }

    public function replaceDraftApplicability(int $versionId, array $scopesInput, int $actorId): array
    {
        $versionId = $this->positiveId($versionId, 'fee_version_id');
        $actorId = $this->actorId($actorId);
        $scopes = $this->applicability($scopesInput);
        try {
            $this->pdo->beginTransaction();
            $version = $this->lockVersionWithParent($versionId);
            $this->assertDraft($version);
            $this->pdo->prepare('DELETE FROM fee_applicability WHERE fee_version_id = ?')->execute([$versionId]);
            $this->insertApplicability($versionId, $scopes);
            $this->pdo->prepare('UPDATE fee_versions SET updated_by = ? WHERE fee_version_id = ?')->execute([$actorId, $versionId]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollback();
            throw $this->mapDatabaseError($e);
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->audit('fee_applicability_replaced', "Replaced applicability for Draft version #$versionId by user #$actorId.");
        return $this->version($versionId);
    }

    public function activateVersion(int $versionId, int $actorId): array
    {
        $versionId = $this->positiveId($versionId, 'fee_version_id');
        $actorId = $this->actorId($actorId);
        try {
            $this->pdo->beginTransaction();
            $version = $this->lockVersionWithParent($versionId);
            $this->assertDraft($version);
            $scopeCount = $this->pdo->prepare('SELECT COUNT(*) FROM fee_applicability WHERE fee_version_id = ?');
            $scopeCount->execute([$versionId]);
            if ((int) $scopeCount->fetchColumn() === 0) {
                throw new FeeSetupException('INVALID_APPLICABILITY', 'Add at least one applicability scope before activation.');
            }
            $active = $this->pdo->prepare(
                "SELECT fee_version_id FROM fee_versions
                 WHERE fee_id=? AND academic_year=? AND semester=? AND effective_status='Active'
                   AND fee_version_id<>? LIMIT 1 FOR UPDATE"
            );
            $active->execute([$version['fee_id'], $version['academic_year'], $version['semester'], $versionId]);
            if ($active->fetchColumn()) {
                throw new FeeSetupException(
                    'ACTIVE_VERSION_EXISTS',
                    'An Active version already exists for this fee, academic year, and semester.',
                    409
                );
            }
            $this->pdo->prepare(
                "UPDATE fee_versions SET effective_status='Active', activated_at=NOW(), archived_at=NULL, updated_by=?
                 WHERE fee_version_id=?"
            )->execute([$actorId, $versionId]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollback();
            throw $this->mapDatabaseError($e);
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->audit('fee_version_activated', "Activated fee version #$versionId by user #$actorId.");
        return $this->version($versionId);
    }

    public function archiveVersion(int $versionId, int $actorId): array
    {
        $versionId = $this->positiveId($versionId, 'fee_version_id');
        $actorId = $this->actorId($actorId);
        try {
            $this->pdo->beginTransaction();
            $version = $this->lockVersionWithParent($versionId);
            if (!in_array($version['effective_status'], [self::VERSION_DRAFT, self::VERSION_ACTIVE], true)) {
                throw new FeeSetupException('VERSION_IMMUTABLE', 'Archived fee versions cannot be changed.', 409);
            }
            $this->pdo->prepare(
                "UPDATE fee_versions SET effective_status='Archived', archived_at=NOW(), updated_by=? WHERE fee_version_id=?"
            )->execute([$actorId, $versionId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
        $this->audit('fee_version_archived', "Archived fee version #$versionId by user #$actorId.");
        return $this->version($versionId);
    }

    public function previewLegacyClassification(int $feeId, array $input): array
    {
        $fee = $this->unresolvedLegacyFee($this->positiveId($feeId, 'fee_id'));
        $proposal = $this->classificationPayload($input);
        return ['legacy_fee' => $fee, 'proposed' => $proposal];
    }

    public function commitLegacyClassification(int $feeId, array $input, int $actorId): array
    {
        $feeId = $this->positiveId($feeId, 'fee_id');
        $actorId = $this->actorId($actorId);
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare('SELECT * FROM fees WHERE fee_id = ? FOR UPDATE');
            $stmt->execute([$feeId]);
            $fee = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fee) {
                throw new FeeSetupException('FEE_NOT_FOUND', 'The legacy fee was not found.', 404);
            }
            if ($fee['fee_type_id'] !== null || $fee['fee_code'] !== null || $fee['identity_status'] !== null) {
                throw new FeeSetupException(
                    'LEGACY_FEE_ALREADY_CLASSIFIED',
                    'This legacy fee has already been classified.',
                    409
                );
            }
            $proposal = $this->classificationPayload($input);
            $this->pdo->prepare(
                "UPDATE fees SET fee_code=?, fee_type_id=?, identity_status='Active' WHERE fee_id=?"
            )->execute([$proposal['fee_code'], $proposal['fee_type_id'], $feeId]);

            $next = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM fee_versions WHERE fee_id = ?');
            $next->execute([$feeId]);
            $versionNo = (int) $next->fetchColumn();
            $versionId = $this->insertDraft($feeId, $versionNo, $proposal['version'], $actorId);
            $this->insertApplicability($versionId, $proposal['applicability']);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollback();
            throw $this->mapDatabaseError($e);
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $this->audit(
            'legacy_fee_classified',
            "Classified legacy fee #$feeId as {$proposal['fee_code']} with Draft version #$versionNo by user #$actorId."
        );
        return $this->fee($feeId);
    }

    private function version(int $versionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT fee_version_id, fee_id, version_no, academic_year, semester, amount, behavior, is_required,
                    effective_status, description, created_by, created_at, updated_by, updated_at, activated_at, archived_at
             FROM fee_versions WHERE fee_version_id = ?"
        );
        $stmt->execute([$versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new FeeSetupException('VERSION_NOT_FOUND', 'The fee version was not found.', 404);
        }
        $scopes = $this->pdo->prepare(
            "SELECT fee_applicability_id, fee_version_id, course, year_level,
                    applies_to_all_courses, applies_to_all_year_levels
             FROM fee_applicability WHERE fee_version_id = ?
             ORDER BY normalized_course_scope, normalized_year_scope"
        );
        $scopes->execute([$versionId]);
        $version['fee_version_id'] = (int) $version['fee_version_id'];
        $version['fee_id'] = (int) $version['fee_id'];
        $version['version_no'] = (int) $version['version_no'];
        $version['amount'] = (float) $version['amount'];
        $version['is_required'] = (bool) $version['is_required'];
        $version['applicability'] = array_map(static function (array $scope): array {
            $scope['fee_applicability_id'] = (int) $scope['fee_applicability_id'];
            $scope['fee_version_id'] = (int) $scope['fee_version_id'];
            $scope['applies_to_all_courses'] = (bool) $scope['applies_to_all_courses'];
            $scope['applies_to_all_year_levels'] = (bool) $scope['applies_to_all_year_levels'];
            return $scope;
        }, $scopes->fetchAll(PDO::FETCH_ASSOC));
        return $version;
    }

    private function classificationPayload(array $input): array
    {
        $this->requireIdentityActive($input['identity_status'] ?? self::IDENTITY_ACTIVE);
        $typeId = $this->positiveId($input['fee_type_id'] ?? 0, 'fee_type_id');
        $this->requireActiveType($typeId);
        $code = $this->feeCode($input['fee_code'] ?? null);
        $this->assertFeeCodeAvailable($code);
        return [
            'fee_code' => $code,
            'fee_type_id' => $typeId,
            'identity_status' => self::IDENTITY_ACTIVE,
            'version' => $this->versionPayload($input['version'] ?? []),
            'applicability' => $this->applicability($input['applicability'] ?? null),
        ];
    }

    private function unresolvedLegacyFee(int $feeId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.fee_id, f.fee_name, f.category_id, fc.category_name, f.default_amount,
                    f.is_required, f.status, f.description
             FROM fees f LEFT JOIN fee_categories fc ON fc.category_id=f.category_id
             WHERE f.fee_id=? AND f.fee_type_id IS NULL AND f.fee_code IS NULL AND f.identity_status IS NULL"
        );
        $stmt->execute([$feeId]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fee) {
            throw new FeeSetupException(
                'LEGACY_FEE_ALREADY_CLASSIFIED',
                'The fee is not an unresolved legacy fee.',
                409
            );
        }
        return $fee;
    }

    private function lockManagedFee(int $feeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fees WHERE fee_id=? AND fee_code IS NOT NULL AND fee_type_id IS NOT NULL AND identity_status IS NOT NULL FOR UPDATE'
        );
        $stmt->execute([$feeId]);
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fee) {
            throw new FeeSetupException('FEE_NOT_FOUND', 'The managed fee identity was not found.', 404);
        }
        return $fee;
    }

    private function lockVersionWithParent(int $versionId): array
    {
        $lookup = $this->pdo->prepare('SELECT fee_id FROM fee_versions WHERE fee_version_id = ?');
        $lookup->execute([$versionId]);
        $feeId = $lookup->fetchColumn();
        if ($feeId === false) {
            throw new FeeSetupException('VERSION_NOT_FOUND', 'The fee version was not found.', 404);
        }
        $fee = $this->lockManagedFee((int) $feeId);
        $this->assertIdentityActive($fee);
        $stmt = $this->pdo->prepare('SELECT * FROM fee_versions WHERE fee_version_id = ? AND fee_id = ? FOR UPDATE');
        $stmt->execute([$versionId, $feeId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new FeeSetupException('VERSION_NOT_FOUND', 'The fee version was not found.', 404);
        }
        return $version;
    }

    private function insertDraft(int $feeId, int $versionNo, array $version, int $actorId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO fee_versions
                (fee_id, version_no, academic_year, semester, amount, behavior, is_required,
                 effective_status, description, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Draft', ?, ?, ?)"
        );
        $stmt->execute([
            $feeId, $versionNo, $version['academic_year'], $version['semester'], $version['amount'],
            $version['behavior'], $version['is_required'], $version['description'], $actorId, $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertApplicability(int $versionId, array $scopes): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fee_applicability
                (fee_version_id, course, year_level, applies_to_all_courses, applies_to_all_year_levels)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($scopes as $scope) {
            $stmt->execute([
                $versionId, $scope['course'], $scope['year_level'],
                $scope['applies_to_all_courses'], $scope['applies_to_all_year_levels'],
            ]);
        }
    }

    private function applicability(mixed $input): array
    {
        if (!is_array($input) || $input === []) {
            throw new FeeSetupException('INVALID_APPLICABILITY', 'At least one applicability scope is required.');
        }
        $normalized = [];
        $seen = [];
        foreach ($input as $scope) {
            if (!is_array($scope)) {
                throw new FeeSetupException('INVALID_APPLICABILITY', 'Each applicability scope must be an object.');
            }
            $allCourses = $this->boolean($scope['applies_to_all_courses'] ?? null, 'applies_to_all_courses');
            $allYears = $this->boolean($scope['applies_to_all_year_levels'] ?? null, 'applies_to_all_year_levels');
            $course = $this->optionalText($scope['course'] ?? null, 100);
            $year = $this->optionalText($scope['year_level'] ?? null, 20);
            if (($allCourses && $course !== null) || (!$allCourses && $course === null)) {
                throw new FeeSetupException(
                    'INVALID_APPLICABILITY',
                    'Course scope must explicitly choose either all courses or one specific course.'
                );
            }
            if (($allYears && $year !== null) || (!$allYears && $year === null)) {
                throw new FeeSetupException(
                    'INVALID_APPLICABILITY',
                    'Year-level scope must explicitly choose either all year levels or one specific year level.'
                );
            }
            $key = mb_strtolower(($allCourses ? '__all__' : $course) . '|' . ($allYears ? '__all__' : $year), 'UTF-8');
            if (isset($seen[$key])) {
                throw new FeeSetupException('DUPLICATE_APPLICABILITY', 'The same applicability scope was provided more than once.', 409);
            }
            $seen[$key] = true;
            $normalized[] = [
                'course' => $allCourses ? null : $course,
                'year_level' => $allYears ? null : $year,
                'applies_to_all_courses' => $allCourses ? 1 : 0,
                'applies_to_all_year_levels' => $allYears ? 1 : 0,
            ];
        }
        return $normalized;
    }

    private function versionPayload(array $input): array
    {
        $academicYear = trim((string) ($input['academic_year'] ?? ''));
        if (!preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $match) || (int) $match[2] !== (int) $match[1] + 1) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Academic year must use consecutive YYYY-YYYY values.');
        }
        $semester = (string) ($input['semester'] ?? '');
        if (!in_array($semester, self::SEMESTERS, true)) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Choose a valid semester.');
        }
        if (!isset($input['amount']) || !is_numeric($input['amount'])) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Enter a valid fee amount.');
        }
        $amount = round((float) $input['amount'], 2);
        if ($amount < 0 || $amount > 99999999.99) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Fee amount must be between 0.00 and 99,999,999.99.');
        }
        $behavior = (string) ($input['behavior'] ?? '');
        if (!in_array($behavior, self::BEHAVIORS, true)) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Choose a valid fee behavior.');
        }
        return [
            'academic_year' => $academicYear,
            'semester' => $semester,
            'amount' => number_format($amount, 2, '.', ''),
            'behavior' => $behavior,
            'is_required' => $this->boolean($input['is_required'] ?? null, 'is_required') ? 1 : 0,
            'description' => $this->description($input['description'] ?? null),
        ];
    }

    private function requireActiveType(int $typeId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM fee_types ft JOIN fee_groups fg ON fg.fee_group_id=ft.fee_group_id
             WHERE ft.fee_type_id=? AND ft.status='Active' AND fg.status='Active'"
        );
        $stmt->execute([$typeId]);
        if (!$stmt->fetchColumn()) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Choose an active fee type.');
        }
    }

    private function assertFeeCodeAvailable(string $code): void
    {
        $stmt = $this->pdo->prepare('SELECT fee_id FROM fees WHERE fee_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn()) {
            throw new FeeSetupException('FEE_CODE_EXISTS', 'That fee code is already reserved.', 409);
        }
    }

    private function feeCode(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,59}$/', $code)) {
            throw new FeeSetupException(
                'VALIDATION_FAILED',
                'Fee code must be 3-60 characters using uppercase letters, numbers, hyphens, or underscores.'
            );
        }
        return $code;
    }

    private function feeName(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Fee name is required and must not exceed 100 characters.');
        }
        return $name;
    }

    private function description(mixed $value): ?string
    {
        return $this->optionalText($value, 2000);
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            throw new FeeSetupException('VALIDATION_FAILED', "Text must not exceed $maxLength characters.");
        }
        return $text;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        throw new FeeSetupException('VALIDATION_FAILED', "$field must be explicitly true or false.");
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new FeeSetupException('VALIDATION_FAILED', "$field must be a positive integer.");
        }
        return $id;
    }

    private function actorId(int $actorId): int
    {
        if ($actorId < 1) {
            throw new FeeSetupException('AUTHENTICATION_REQUIRED', 'An authenticated Accounting user is required.', 401);
        }
        return $actorId;
    }

    private function requireIdentityActive(mixed $status): void
    {
        if ($status !== self::IDENTITY_ACTIVE) {
            throw new FeeSetupException('VALIDATION_FAILED', 'New and classified fee identities must initially be Active.');
        }
    }

    private function assertIdentityActive(array $fee): void
    {
        if ($fee['identity_status'] !== self::IDENTITY_ACTIVE) {
            throw new FeeSetupException('IDENTITY_ARCHIVED', 'Archived fee identities cannot be changed.', 409);
        }
    }

    private function assertDraft(array $version): void
    {
        if ($version['effective_status'] !== self::VERSION_DRAFT) {
            throw new FeeSetupException('VERSION_NOT_DRAFT', 'Only Draft fee versions may be edited or activated.', 409);
        }
    }

    private function identityHasActivationHistory(int $feeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM fee_versions WHERE fee_id = ? AND activated_at IS NOT NULL LIMIT 1');
        $stmt->execute([$feeId]);
        return (bool) $stmt->fetchColumn();
    }

    private function assertVersionTermAvailable(int $feeId, string $academicYear, string $semester, ?int $excludeVersionId = null): void
    {
        $sql = 'SELECT version_no, effective_status FROM fee_versions WHERE fee_id = ? AND academic_year = ? AND semester = ?';
        $params = [$feeId, $academicYear, $semester];
        if ($excludeVersionId !== null) {
            $sql .= ' AND fee_version_id <> ?';
            $params[] = $excludeVersionId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($conflict) {
            throw new FeeSetupException(
                'VERSION_TERM_EXISTS',
                "Version {$conflict['version_no']} ({$conflict['effective_status']}) already uses $academicYear / $semester for this fee.",
                409
            );
        }
    }

    private function castCatalogRow(array $row): array
    {
        $row['fee_id'] = (int) $row['fee_id'];
        $row['fee_type_id'] = (int) $row['fee_type_id'];
        $row['version_count'] = (int) $row['version_count'];
        $row['draft_count'] = (int) $row['draft_count'];
        $row['active_count'] = (int) $row['active_count'];
        $row['active_version_no'] = $row['active_version_no'] !== null ? (int) $row['active_version_no'] : null;
        $row['active_amount'] = $row['active_amount'] !== null ? (float) $row['active_amount'] : null;
        $row['latest_draft_version_no'] = $row['latest_draft_version_no'] !== null ? (int) $row['latest_draft_version_no'] : null;
        $row['identity_locked'] = (bool) $row['identity_locked'];
        return $row;
    }

    private function mapDatabaseError(PDOException $e): FeeSetupException
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if ($driverCode === 1062) {
            if (str_contains($message, 'uq_fee_versions_term')) {
                return new FeeSetupException('VERSION_TERM_EXISTS', 'That academic year and semester already exists for this fee.', 409, $e);
            }
            if (str_contains($message, 'uq_fees_fee_code')) {
                return new FeeSetupException('FEE_CODE_EXISTS', 'That fee code is already reserved.', 409, $e);
            }
            if (str_contains($message, 'uq_fee_versions_active_scope')) {
                return new FeeSetupException(
                    'ACTIVE_VERSION_EXISTS',
                    'An Active version already exists for this fee, academic year, and semester.',
                    409,
                    $e
                );
            }
            if (str_contains($message, 'uq_fee_applicability_scope')) {
                return new FeeSetupException('DUPLICATE_APPLICABILITY', 'The applicability scope already exists.', 409, $e);
            }
            if (str_contains($message, 'uq_fee_versions_number')) {
                return new FeeSetupException('VERSION_CONFLICT', 'Another version was created concurrently. Retry the request.', 409, $e);
            }
        }
        return new FeeSetupException('DATABASE_WRITE_FAILED', 'The Fee Setup change could not be saved.', 500, $e);
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function audit(string $action, string $detail): void
    {
        if ($this->auditLogger === null) {
            return;
        }
        try {
            ($this->auditLogger)($action, $detail);
        } catch (Throwable $e) {
            error_log('Fee Setup audit logging failed: ' . $e->getMessage());
        }
    }
}
