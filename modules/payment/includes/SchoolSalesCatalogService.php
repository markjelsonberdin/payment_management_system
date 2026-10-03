<?php

declare(strict_types=1);

require_once __DIR__ . '/SchoolSalesCatalogMutationInfrastructure.php';
require_once __DIR__ . '/SchoolSalesApplicabilityScopeProvider.php';

final class SchoolSalesCatalogReadException extends RuntimeException
{
}

final class SchoolSalesCatalogValidationException extends RuntimeException
{
}

final class SchoolSalesCatalogAuthorizationException extends RuntimeException
{
}

/** Authoritative read-only access to the Phase 5 School Sales catalog. */
final class SchoolSalesCatalogService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SchoolSalesCatalogMutationInfrastructure $mutationInfrastructure = null,
        private readonly ?SchoolSalesApplicabilityScopeProvider $applicabilityScopeProvider = null
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        $rows = $this->pdo->query(
            'SELECT sale_category_id, category_code, category_name, sort_order, status,
                    created_by, updated_by, created_at, updated_at
             FROM school_sale_categories
             ORDER BY sort_order, category_name, sale_category_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->categoryRow($row), $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function itemTypes(): array
    {
        $rows = $this->pdo->query(
            'SELECT sale_item_type_id, type_code, type_name, metadata_profile, status,
                    sort_order, created_by, updated_by, created_at, updated_at
             FROM school_sale_item_types
             ORDER BY sort_order, type_name, sale_item_type_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->itemTypeRow($row), $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function catalogItems(): array
    {
        $rows = $this->pdo->query($this->itemSelect() .
            ' ORDER BY c.sort_order, c.category_name, i.item_name, i.sale_item_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->itemRow($row), $rows);
    }

    /** @return array{item:array<string,mixed>,variants:array<int,array<string,mixed>>,applicability:array<int,array<string,mixed>>} */
    public function itemDetails(int $itemId, ?DateTimeImmutable $at = null): array
    {
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $stmt = $this->pdo->prepare($this->itemSelect() . ' WHERE i.sale_item_id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new SchoolSalesCatalogReadException('School Sales catalog item was not found.');
        }
        return [
            'item' => $this->itemRow($item),
            'variants' => $this->itemVariants($itemId, $at),
            'applicability' => $this->itemApplicabilityHistory($itemId),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function itemVariants(int $itemId, ?DateTimeImmutable $at = null): array
    {
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $stmt = $this->pdo->prepare(
            'SELECT sale_variant_id, sale_item_id, variant_code, variant_name, sku,
                    size_label, variant_metadata, sort_order, status, created_by,
                    updated_by, created_at, updated_at
             FROM school_sale_item_variants
             WHERE sale_item_id = ?
             ORDER BY sort_order, variant_name, sale_variant_id'
        );
        $stmt->execute([$itemId]);
        $variants = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variant = $this->variantRow($row);
            $resolution = $this->resolveCurrentVariantPrice((int) $row['sale_variant_id'], $at);
            $variant['prices'] = $resolution['prices'];
            $variant['current_price_count'] = $resolution['current_price_count'];
            $variant['current_price_state'] = $resolution['current_price_state'];
            $variant['current_price'] = $resolution['current_price'];
            $variants[] = $variant;
        }
        return $variants;
    }

    /** @return array{prices:array<int,array<string,mixed>>,current_price_count:int,current_price_state:string,current_price:?array} */
    public function resolveCurrentVariantPrice(int $variantId, ?DateTimeImmutable $at = null): array
    {
        $prices = $this->variantPriceHistory($variantId, $at);
        $current = array_values(array_filter($prices, static fn(array $price): bool => $price['is_current']));
        $count = count($current);
        return [
            'prices' => $prices,
            'current_price_count' => $count,
            'current_price_state' => $count === 1 ? 'Available' : ($count === 0 ? 'Missing' : 'Conflict'),
            'current_price' => $count === 1 ? $current[0] : null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function variantPriceHistory(int $variantId, ?DateTimeImmutable $at = null): array
    {
        $variantId = $this->positiveId($variantId, 'sale_variant_id');
        $stmt = $this->pdo->prepare(
            'SELECT sale_variant_price_id, sale_variant_id, amount, currency,
                    effective_from, effective_to, status, created_by, updated_by,
                    activated_by, retired_by, created_at, updated_at, activated_at, retired_at
             FROM school_sale_variant_prices
             WHERE sale_variant_id = ?
             ORDER BY effective_from DESC, sale_variant_price_id DESC'
        );
        $stmt->execute([$variantId]);
        $now = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return array_map(fn(array $row): array => $this->priceRow($row, $now), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int,array<string,mixed>> */
    public function itemApplicabilityHistory(int $itemId): array
    {
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $stmt = $this->pdo->prepare(
            'SELECT sale_item_applicability_id, sale_item_id, program_code, year_level,
                    status, created_by, updated_by, deactivated_by, created_at,
                    updated_at, deactivated_at
             FROM school_sale_item_applicability
             WHERE sale_item_id = ?
             ORDER BY CASE WHEN status = \'Active\' THEN 0 ELSE 1 END,
                      created_at DESC, sale_item_applicability_id DESC'
        );
        $stmt->execute([$itemId]);
        return array_map(fn(array $row): array => $this->applicabilityRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function bookDetails(int $itemId): ?array
    {
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $this->assertBookItem($itemId, false, false);
        return $this->lockedBookDetails($itemId, false);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function createBookDetails(int $itemId, string $correlationId, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $data = $this->normalizeBookDetailsInput($input);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'create_book_details', 'sale_item_id' => $itemId, 'input' => $data],
            $this->auditBase('school_sale_book_details_created', 'school_sale_book_details', 'Created School Sales Book metadata.', $actor),
            function (PDO $pdo) use ($itemId, $data, $actorId): CatalogMutationOutcome {
                $this->assertBookItem($itemId, true, true);
                if ($this->lockedBookDetails($itemId, true) !== null) {
                    throw new SchoolSalesCatalogValidationException('BOOK_DETAILS_ALREADY_EXIST');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO school_sale_book_details
                        (sale_item_id, book_title, author, publisher, edition, isbn, notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$itemId, $data['book_title'], $data['author'], $data['publisher'],
                    $data['edition'], $data['isbn'], $data['notes'], $actorId, $actorId]);
                $after = $this->lockedBookDetails($itemId, false);
                return new CatalogMutationOutcome($after, [
                    'entity_id' => (int) $after['sale_book_detail_id'], 'before_state' => null, 'after_state' => $after,
                ]);
            }
        );
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function updateBookDetails(int $itemId, string $correlationId, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $data = $this->normalizeBookDetailsInput($input);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'update_book_details', 'sale_item_id' => $itemId, 'input' => $data],
            $this->auditBase('school_sale_book_details_updated', 'school_sale_book_details', 'Updated School Sales Book metadata.', $actor),
            function (PDO $pdo) use ($itemId, $data, $actorId): CatalogMutationOutcome {
                $this->assertBookItem($itemId, true, true);
                $before = $this->lockedBookDetails($itemId, true);
                if ($before === null) throw new SchoolSalesCatalogValidationException('BOOK_DETAILS_NOT_FOUND');
                $stmt = $pdo->prepare(
                    'UPDATE school_sale_book_details
                     SET book_title = ?, author = ?, publisher = ?, edition = ?, isbn = ?, notes = ?, updated_by = ?
                     WHERE sale_item_id = ?'
                );
                $stmt->execute([$data['book_title'], $data['author'], $data['publisher'], $data['edition'],
                    $data['isbn'], $data['notes'], $actorId, $itemId]);
                $after = $this->lockedBookDetails($itemId, false);
                return new CatalogMutationOutcome($after, [
                    'entity_id' => (int) $after['sale_book_detail_id'], 'before_state' => $before, 'after_state' => $after,
                ]);
            }
        );
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function createDraftItem(string $correlationId, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $data = $this->normalizeItemInput($input);
        $data['actor_user_id'] = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'create_draft_item', 'input' => $data],
            $this->auditBase('school_sale_item_created', 'school_sale_item', 'Created a Draft School Sales catalog item.', $actor),
            function (PDO $pdo) use ($data): CatalogMutationOutcome {
                $this->assertCategoryAndType($data['sale_category_id'], $data['sale_item_type_id']);
                $this->assertItemCodeAvailable($data['item_code']);
                $stmt = $pdo->prepare(
                    "INSERT INTO school_sale_items
                        (item_code, sale_category_id, sale_item_type_id, item_name, description,
                         applicability_mode, status, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, 'Draft', ?, ?)"
                );
                $stmt->execute([$data['item_code'], $data['sale_category_id'], $data['sale_item_type_id'],
                    $data['item_name'], $data['description'], $data['applicability_mode'],
                    $data['actor_user_id'], $data['actor_user_id']]);
                $id = (int) $pdo->lastInsertId();
                $after = $this->lockedItem($id, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $id, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function updateDraftItem(int $itemId, string $correlationId, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $data = $this->normalizeItemInput($input);
        $data['actor_user_id'] = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'update_draft_item', 'sale_item_id' => $itemId, 'input' => $data],
            $this->auditBase('school_sale_item_updated', 'school_sale_item', 'Updated a Draft School Sales catalog item.', $actor),
            function (PDO $pdo) use ($itemId, $data): CatalogMutationOutcome {
                $before = $this->lockedItem($itemId);
                if ($before['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                if ($before['status'] !== 'Draft') throw new SchoolSalesCatalogValidationException('ITEM_NOT_DRAFT');
                $this->assertCategoryAndType($data['sale_category_id'], $data['sale_item_type_id']);
                if ((int) $before['sale_item_type_id'] !== $data['sale_item_type_id']
                    && !$this->itemTypeIsBook($data['sale_item_type_id'], true)
                    && $this->lockedBookDetails($itemId, true) !== null) {
                    throw new SchoolSalesCatalogValidationException('BOOK_DETAILS_REQUIRE_BOOK_ITEM_TYPE');
                }
                $this->assertItemCodeAvailable($data['item_code'], $itemId);
                $stmt = $pdo->prepare(
                    'UPDATE school_sale_items
                     SET item_code = ?, sale_category_id = ?, sale_item_type_id = ?, item_name = ?,
                         description = ?, applicability_mode = ?, updated_by = ?
                     WHERE sale_item_id = ? AND status = \'Draft\''
                );
                $stmt->execute([$data['item_code'], $data['sale_category_id'], $data['sale_item_type_id'],
                    $data['item_name'], $data['description'], $data['applicability_mode'],
                    $data['actor_user_id'], $itemId]);
                $after = $this->lockedItem($itemId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $itemId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function createDraftVariant(int $itemId, string $correlationId, array $input, array $actor): array
    {
        return $this->createVariant($itemId, $correlationId, $input, $actor, false);
    }

    /** Explicit approved action; never called implicitly by item creation. @param array<string,mixed> $actor */
    public function createStandardDraftVariant(int $itemId, string $correlationId, array $actor, array $overrides = []): array
    {
        return $this->createVariant($itemId, $correlationId, array_replace([
            'variant_code' => 'STANDARD', 'variant_name' => 'Standard', 'sku' => null,
            'size_label' => null, 'variant_metadata' => null, 'sort_order' => 0,
        ], $overrides), $actor, true);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function updateVariant(int $variantId, string $correlationId, array $input, array $actor): array
    {
        $this->requireManage($actor);
        $variantId = $this->positiveId($variantId, 'sale_variant_id');
        $data = $this->normalizeVariantInput($input);
        $data['actor_user_id'] = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'update_variant', 'sale_variant_id' => $variantId, 'input' => $data],
            $this->auditBase('school_sale_variant_updated', 'school_sale_variant', 'Updated a School Sales item variant.', $actor),
            function (PDO $pdo) use ($variantId, $data): CatalogMutationOutcome {
                $before = $this->lockedVariant($variantId);
                if ($before['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_VARIANT_IMMUTABLE');
                if ($before['status'] === 'Active') throw new SchoolSalesCatalogValidationException('ACTIVE_VARIANT_UPDATE_REQUIRES_LATER_SERVICE');
                $this->assertVariantIdentityAvailable((int) $before['sale_item_id'], $data['variant_code'], $data['sku'], $variantId);
                $stmt = $pdo->prepare(
                    'UPDATE school_sale_item_variants
                     SET variant_code = ?, variant_name = ?, sku = ?, size_label = ?,
                         variant_metadata = ?, sort_order = ?, updated_by = ?
                     WHERE sale_variant_id = ? AND status <> \'Archived\''
                );
                $stmt->execute([$data['variant_code'], $data['variant_name'], $data['sku'], $data['size_label'],
                    $data['variant_metadata_json'], $data['sort_order'], $data['actor_user_id'], $variantId]);
                $after = $this->lockedVariant($variantId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $variantId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function deactivateVariant(int $variantId, string $correlationId, array $actor): array
    {
        return $this->changeVariantLifecycle($variantId, $correlationId, $actor, 'Inactive', 'school_sale_variant_deactivated');
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function archiveVariant(int $variantId, string $correlationId, array $actor): array
    {
        return $this->changeVariantLifecycle($variantId, $correlationId, $actor, 'Archived', 'school_sale_variant_archived');
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function createDraftVariantPrice(int $variantId, string $correlationId, array $input, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
        $variantId = $this->positiveId($variantId, 'sale_variant_id');
        $data = $this->normalizePriceInput($input);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'create_draft_price', 'sale_variant_id' => $variantId, 'input' => $data],
            $this->auditBase('school_sale_price_created', 'school_sale_variant_price', 'Created a Draft School Sales variant price.', $actor),
            function (PDO $pdo) use ($variantId, $data, $actorId): CatalogMutationOutcome {
                $variant = $this->lockedVariant($variantId);
                if ($variant['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_VARIANT_IMMUTABLE');
                $this->lockVariantPrices($variantId);
                $this->assertEffectiveStartAvailable($variantId, $data['effective_from']);
                $stmt = $pdo->prepare(
                    "INSERT INTO school_sale_variant_prices
                        (sale_variant_id, amount, currency, effective_from, effective_to, status,
                         created_by, updated_by)
                     VALUES (?, ?, 'PHP', ?, ?, 'Draft', ?, ?)"
                );
                $stmt->execute([$variantId, $data['amount'], $data['effective_from'], $data['effective_to'], $actorId, $actorId]);
                $id = (int) $pdo->lastInsertId();
                $after = $this->lockedPrice($id, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $id, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function updateDraftVariantPrice(int $priceId, string $correlationId, array $input, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
        $priceId = $this->positiveId($priceId, 'sale_variant_price_id');
        $data = $this->normalizePriceInput($input);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'update_draft_price', 'sale_variant_price_id' => $priceId, 'input' => $data],
            $this->auditBase('school_sale_price_updated', 'school_sale_variant_price', 'Updated a Draft School Sales variant price.', $actor),
            function (PDO $pdo) use ($priceId, $data, $actorId): CatalogMutationOutcome {
                $before = $this->lockedPrice($priceId);
                if ($before['status'] !== 'Draft') throw new SchoolSalesCatalogValidationException('ACTIVATED_PRICE_IMMUTABLE');
                $this->lockedVariant((int) $before['sale_variant_id']);
                $this->lockVariantPrices((int) $before['sale_variant_id']);
                $this->assertEffectiveStartAvailable((int) $before['sale_variant_id'], $data['effective_from'], $priceId);
                $stmt = $pdo->prepare(
                    "UPDATE school_sale_variant_prices
                     SET amount = ?, currency = 'PHP', effective_from = ?, effective_to = ?, updated_by = ?
                     WHERE sale_variant_price_id = ? AND status = 'Draft'"
                );
                $stmt->execute([$data['amount'], $data['effective_from'], $data['effective_to'], $actorId, $priceId]);
                $after = $this->lockedPrice($priceId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $priceId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function activateVariantPrice(int $priceId, string $correlationId, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.activate');
        $priceId = $this->positiveId($priceId, 'sale_variant_price_id');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'activate_price', 'sale_variant_price_id' => $priceId],
            $this->auditBase('school_sale_price_activated', 'school_sale_variant_price', 'Activated a School Sales variant price.', $actor),
            function (PDO $pdo) use ($priceId, $actorId): CatalogMutationOutcome {
                $before = $this->lockedPrice($priceId);
                if ($before['status'] !== 'Draft') throw new SchoolSalesCatalogValidationException('PRICE_NOT_DRAFT');
                if ($before['effective_to'] !== null && $before['effective_to'] <= $this->now()) {
                    throw new SchoolSalesCatalogValidationException('PRICE_WINDOW_ALREADY_ENDED');
                }
                $variant = $this->lockedVariant((int) $before['sale_variant_id']);
                if ($variant['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_VARIANT_IMMUTABLE');
                $this->lockVariantPrices((int) $before['sale_variant_id']);
                $this->assertNoActivePriceOverlap((int) $before['sale_variant_id'], $before['effective_from'], $before['effective_to'], $priceId);
                $stmt = $pdo->prepare(
                    "UPDATE school_sale_variant_prices
                     SET status = 'Active', activated_by = ?, activated_at = ?, updated_by = ?
                     WHERE sale_variant_price_id = ? AND status = 'Draft'"
                );
                $stmt->execute([$actorId, $this->now(), $actorId, $priceId]);
                $this->assertActiveVariantCurrentPriceInvariant($variant);
                $after = $this->lockedPrice($priceId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $priceId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** Create and activate a successor while closing the price it replaces. @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    public function replaceActiveVariantPrice(int $variantId, string $correlationId, array $input, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.activate');
        $variantId = $this->positiveId($variantId, 'sale_variant_id');
        $data = $this->normalizePriceInput($input);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'replace_active_price', 'sale_variant_id' => $variantId, 'input' => $data],
            $this->auditBase('school_sale_price_activated', 'school_sale_variant_price', 'Activated a successor School Sales price and closed its predecessor.', $actor),
            function (PDO $pdo) use ($variantId, $data, $actorId): CatalogMutationOutcome {
                $variant = $this->lockedVariant($variantId);
                if ($variant['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_VARIANT_IMMUTABLE');
                if ($data['effective_to'] !== null && $data['effective_to'] <= $this->now()) {
                    throw new SchoolSalesCatalogValidationException('PRICE_WINDOW_ALREADY_ENDED');
                }
                $prices = $this->lockVariantPrices($variantId);
                $predecessors = array_values(array_filter($prices, static fn(array $row): bool =>
                    $row['status'] === 'Active'
                    && $row['effective_from'] < $data['effective_from']
                    && ($row['effective_to'] === null || $row['effective_to'] > $data['effective_from'])
                ));
                if (count($predecessors) !== 1) throw new SchoolSalesCatalogValidationException('EXACTLY_ONE_PREDECESSOR_PRICE_REQUIRED');
                $predecessor = $predecessors[0];
                $this->assertEffectiveStartAvailable($variantId, $data['effective_from']);
                $replacementIsCurrent = $data['effective_from'] <= $this->now();
                $predecessorStatus = $replacementIsCurrent ? 'Retired' : 'Active';
                $stmt = $pdo->prepare(
                    "UPDATE school_sale_variant_prices
                     SET effective_to = ?, status = ?, retired_by = ?, retired_at = ?, updated_by = ?
                     WHERE sale_variant_price_id = ? AND status = 'Active'"
                );
                $stmt->execute([$data['effective_from'], $predecessorStatus,
                    $replacementIsCurrent ? $actorId : null, $replacementIsCurrent ? $this->now() : null,
                    $actorId, $predecessor['sale_variant_price_id']]);
                $stmt = $pdo->prepare(
                    "INSERT INTO school_sale_variant_prices
                        (sale_variant_id, amount, currency, effective_from, effective_to, status,
                         created_by, updated_by, activated_by, activated_at)
                     VALUES (?, ?, 'PHP', ?, ?, 'Active', ?, ?, ?, ?)"
                );
                $stmt->execute([$variantId, $data['amount'], $data['effective_from'], $data['effective_to'],
                    $actorId, $actorId, $actorId, $this->now()]);
                $id = (int) $pdo->lastInsertId();
                $this->assertNoActivePriceOverlap($variantId, $data['effective_from'], $data['effective_to'], $id);
                $this->assertActiveVariantCurrentPriceInvariant($variant);
                $after = $this->lockedPrice($id, false);
                $retired = $this->lockedPrice((int) $predecessor['sale_variant_price_id'], false);
                return new CatalogMutationOutcome(
                    ['successor' => $after, 'retired_predecessor' => $retired],
                    ['entity_id' => $id, 'before_state' => ['predecessor' => $predecessor],
                     'after_state' => ['successor' => $after, 'retired_predecessor' => $retired]]
                );
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function retireActiveVariantPrice(int $priceId, string $effectiveTo, string $correlationId, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.activate');
        $priceId = $this->positiveId($priceId, 'sale_variant_price_id');
        $closeAt = $this->normalizeDateTime($effectiveTo, 'INVALID_EFFECTIVE_TO');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'retire_active_price', 'sale_variant_price_id' => $priceId, 'effective_to' => $closeAt],
            $this->auditBase('school_sale_price_retired', 'school_sale_variant_price', 'Retired a School Sales variant price.', $actor),
            function (PDO $pdo) use ($priceId, $closeAt, $actorId): CatalogMutationOutcome {
                $before = $this->lockedPrice($priceId);
                if ($before['status'] !== 'Active') throw new SchoolSalesCatalogValidationException('PRICE_NOT_ACTIVE');
                if ($closeAt <= $before['effective_from']) throw new SchoolSalesCatalogValidationException('INVALID_EFFECTIVE_INTERVAL');
                $variant = $this->lockedVariant((int) $before['sale_variant_id']);
                $this->lockVariantPrices((int) $before['sale_variant_id']);
                if ($variant['status'] === 'Active' && $this->priceIsCurrent($before)) {
                    throw new SchoolSalesCatalogValidationException('ACTIVE_VARIANT_REQUIRES_REPLACEMENT_PRICE');
                }
                $stmt = $pdo->prepare(
                    "UPDATE school_sale_variant_prices
                     SET effective_to = ?, status = 'Retired', retired_by = ?, retired_at = ?, updated_by = ?
                     WHERE sale_variant_price_id = ? AND status = 'Active'"
                );
                $stmt->execute([$closeAt, $actorId, $this->now(), $actorId, $priceId]);
                $after = $this->lockedPrice($priceId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $priceId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** Draft cancellation needs manage; future Active cancellation needs activate. @param array<string,mixed> $actor @return array<string,mixed> */
    public function cancelVariantPrice(int $priceId, string $correlationId, array $actor): array
    {
        $this->requireAnyCatalogMutationPermission($actor);
        $priceId = $this->positiveId($priceId, 'sale_variant_price_id');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'cancel_price', 'sale_variant_price_id' => $priceId],
            $this->auditBase('school_sale_price_cancelled', 'school_sale_variant_price', 'Cancelled a Draft or future School Sales variant price.', $actor),
            function (PDO $pdo) use ($priceId, $actor, $actorId): CatalogMutationOutcome {
                $before = $this->lockedPrice($priceId);
                $this->lockedVariant((int) $before['sale_variant_id']);
                $this->lockVariantPrices((int) $before['sale_variant_id']);
                if ($before['status'] === 'Draft') {
                    $this->requirePermission($actor, 'school_sales.catalog.manage');
                } elseif ($before['status'] === 'Active' && $before['effective_from'] > $this->now()) {
                    $this->requirePermission($actor, 'school_sales.catalog.activate');
                } else {
                    throw new SchoolSalesCatalogValidationException('PRICE_CANNOT_BE_CANCELLED');
                }
                $stmt = $pdo->prepare(
                    "UPDATE school_sale_variant_prices SET status = 'Cancelled', updated_by = ?
                     WHERE sale_variant_price_id = ? AND status IN ('Draft', 'Active')"
                );
                $stmt->execute([$actorId, $priceId]);
                $after = $this->lockedPrice($priceId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $priceId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function changeItemApplicabilityMode(int $itemId, string $mode, string $correlationId, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, ['ALL', 'RESTRICTED'], true)) throw new SchoolSalesCatalogValidationException('INVALID_APPLICABILITY_MODE');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'change_applicability_mode', 'sale_item_id' => $itemId, 'applicability_mode' => $mode],
            $this->auditBase('school_sale_applicability_mode_changed', 'school_sale_item', 'Changed School Sales item applicability mode.', $actor),
            function (PDO $pdo) use ($itemId, $mode, $actorId): CatalogMutationOutcome {
                $beforeItem = $this->lockedItem($itemId);
                if ($beforeItem['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                if ($beforeItem['applicability_mode'] === $mode) throw new SchoolSalesCatalogValidationException('APPLICABILITY_MODE_UNCHANGED');
                $beforeRows = $this->lockApplicabilityRows($itemId);
                if ($mode === 'RESTRICTED' && $beforeItem['status'] === 'Active') {
                    throw new SchoolSalesCatalogValidationException('ACTIVE_ITEM_RESTRICTED_MODE_REQUIRES_ASSIGNMENT_SET');
                }
                if ($mode === 'ALL') $this->deactivateActiveApplicabilityRows($itemId, $actorId);
                $stmt = $pdo->prepare('UPDATE school_sale_items SET applicability_mode = ?, updated_by = ? WHERE sale_item_id = ?');
                $stmt->execute([$mode, $actorId, $itemId]);
                $afterItem = $this->lockedItem($itemId, false);
                $afterRows = $this->lockApplicabilityRows($itemId, false);
                $this->assertApplicabilityModeConsistency($afterItem, $afterRows);
                return new CatalogMutationOutcome(
                    ['item' => $afterItem, 'applicability' => $afterRows],
                    ['entity_id' => $itemId, 'before_state' => ['item' => $beforeItem, 'applicability' => $beforeRows],
                     'after_state' => ['item' => $afterItem, 'applicability' => $afterRows]]
                );
            }
        );
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $actor @return array<string,mixed> */
    public function addItemApplicabilityAssignment(int $itemId, string $correlationId, array $scope, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $normalized = $this->normalizeApplicabilityScope($scope);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'add_applicability_assignment', 'sale_item_id' => $itemId, 'scope' => $normalized],
            $this->auditBase('school_sale_applicability_created', 'school_sale_item_applicability', 'Created a School Sales applicability assignment.', $actor),
            function (PDO $pdo) use ($itemId, $normalized, $actorId): CatalogMutationOutcome {
                $item = $this->lockedItem($itemId);
                if ($item['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                if ($item['applicability_mode'] !== 'RESTRICTED') throw new SchoolSalesCatalogValidationException('RESTRICTED_MODE_REQUIRED');
                $beforeRows = $this->lockApplicabilityRows($itemId);
                $this->assertActiveApplicabilityScopeAvailable($beforeRows, $normalized);
                $stmt = $pdo->prepare(
                    "INSERT INTO school_sale_item_applicability
                        (sale_item_id, program_code, year_level, status, created_by, updated_by)
                     VALUES (?, ?, ?, 'Active', ?, ?)"
                );
                $stmt->execute([$itemId, $normalized['program_code'], $normalized['year_level'], $actorId, $actorId]);
                $id = (int) $pdo->lastInsertId();
                $after = $this->lockedApplicabilityRow($id, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $id, 'before_state' => null, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function deactivateItemApplicabilityAssignment(int $assignmentId, string $correlationId, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
        $assignmentId = $this->positiveId($assignmentId, 'sale_item_applicability_id');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'deactivate_applicability_assignment', 'sale_item_applicability_id' => $assignmentId],
            $this->auditBase('school_sale_applicability_deactivated', 'school_sale_item_applicability', 'Deactivated a School Sales applicability assignment.', $actor),
            function (PDO $pdo) use ($assignmentId, $actorId): CatalogMutationOutcome {
                $candidate = $this->lockedApplicabilityRow($assignmentId, false);
                $item = $this->lockedItem((int) $candidate['sale_item_id']);
                if ($item['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                $rows = $this->lockApplicabilityRows((int) $candidate['sale_item_id']);
                $before = null;
                foreach ($rows as $row) if ((int) $row['sale_item_applicability_id'] === $assignmentId) { $before = $row; break; }
                if ($before === null || $before['status'] !== 'Active') throw new SchoolSalesCatalogValidationException('APPLICABILITY_ASSIGNMENT_NOT_ACTIVE');
                $activeCount = count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'Active'));
                if ($item['status'] === 'Active' && $item['applicability_mode'] === 'RESTRICTED' && $activeCount <= 1) {
                    throw new SchoolSalesCatalogValidationException('ACTIVE_RESTRICTED_ITEM_REQUIRES_ASSIGNMENT');
                }
                $this->deactivateApplicabilityRow($assignmentId, $actorId);
                $after = $this->lockedApplicabilityRow($assignmentId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $assignmentId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** @param array<int,array<string,mixed>> $scopes @param array<string,mixed> $actor @return array<string,mixed> */
    public function replaceItemApplicabilityAssignments(int $itemId, string $correlationId, array $scopes, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        if (!array_is_list($scopes) || $scopes === []) throw new SchoolSalesCatalogValidationException('RESTRICTED_ASSIGNMENT_SET_REQUIRED');
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope)) throw new SchoolSalesCatalogValidationException('INVALID_APPLICABILITY_SCOPE');
            $value = $this->normalizeApplicabilityScope($scope);
            $key = $this->applicabilityScopeKey($value);
            if (isset($normalized[$key])) throw new SchoolSalesCatalogValidationException('DUPLICATE_ACTIVE_APPLICABILITY_SCOPE');
            $normalized[$key] = $value;
        }
        $normalized = array_values($normalized);
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'replace_applicability_assignments', 'sale_item_id' => $itemId, 'scopes' => $normalized],
            $this->auditBase('school_sale_applicability_replaced', 'school_sale_item', 'Replaced the active School Sales applicability assignment set.', $actor),
            function (PDO $pdo) use ($itemId, $normalized, $actorId): CatalogMutationOutcome {
                $beforeItem = $this->lockedItem($itemId);
                if ($beforeItem['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                $beforeRows = $this->lockApplicabilityRows($itemId);
                $this->deactivateActiveApplicabilityRows($itemId, $actorId);
                $insert = $pdo->prepare(
                    "INSERT INTO school_sale_item_applicability
                        (sale_item_id, program_code, year_level, status, created_by, updated_by)
                     VALUES (?, ?, ?, 'Active', ?, ?)"
                );
                foreach ($normalized as $scope) {
                    $insert->execute([$itemId, $scope['program_code'], $scope['year_level'], $actorId, $actorId]);
                }
                $stmt = $pdo->prepare("UPDATE school_sale_items SET applicability_mode = 'RESTRICTED', updated_by = ? WHERE sale_item_id = ?");
                $stmt->execute([$actorId, $itemId]);
                $afterItem = $this->lockedItem($itemId, false);
                $afterRows = $this->lockApplicabilityRows($itemId, false);
                $this->assertApplicabilityModeConsistency($afterItem, $afterRows);
                return new CatalogMutationOutcome(
                    ['item' => $afterItem, 'applicability' => $afterRows],
                    ['entity_id' => $itemId, 'before_state' => ['item' => $beforeItem, 'applicability' => $beforeRows],
                     'after_state' => ['item' => $afterItem, 'applicability' => $afterRows]]
                );
            }
        );
    }

    /** @return array<string,mixed> */
    public function evaluateItemActivationReadiness(int $itemId, ?DateTimeImmutable $at = null): array
    {
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $now = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return $this->activationReadiness($itemId, $now, false);
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function activateItem(int $itemId, string $correlationId, array $actor): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.activate');
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => 'activate_item', 'sale_item_id' => $itemId],
            $this->auditBase('school_sale_item_activated', 'school_sale_item', 'Activated a School Sales catalog item.', $actor),
            function (PDO $pdo) use ($itemId, $actorId): CatalogMutationOutcome {
                $before = $this->lockedItem($itemId);
                if ($before['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                if ($before['status'] === 'Active') throw new SchoolSalesCatalogValidationException('ITEM_ALREADY_ACTIVE');
                $readiness = $this->activationReadiness($itemId, $this->now(), true);
                if (!$readiness['ready']) {
                    throw new SchoolSalesCatalogValidationException('ITEM_ACTIVATION_PREREQUISITES_FAILED:' . implode(',', array_column($readiness['failures'], 'code')));
                }
                $stmt = $pdo->prepare("UPDATE school_sale_items SET status = 'Active', updated_by = ? WHERE sale_item_id = ? AND status <> 'Archived'");
                $stmt->execute([$actorId, $itemId]);
                $after = $this->lockedItem($itemId, false);
                return new CatalogMutationOutcome(
                    ['item' => $after, 'readiness' => $readiness],
                    ['entity_id' => $itemId, 'before_state' => ['item' => $before, 'readiness' => $readiness],
                     'after_state' => ['item' => $after, 'readiness' => $readiness]]
                );
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function deactivateItem(int $itemId, string $correlationId, array $actor): array
    {
        return $this->changeItemLifecycle($itemId, $correlationId, $actor, 'Inactive', 'school_sale_item_deactivated');
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function archiveItem(int $itemId, string $correlationId, array $actor): array
    {
        return $this->changeItemLifecycle($itemId, $correlationId, $actor, 'Archived', 'school_sale_item_archived');
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function changeItemLifecycle(int $itemId, string $correlationId, array $actor, string $target, string $action): array
    {
        $this->requirePermission($actor, 'school_sales.catalog.activate');
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => $action, 'sale_item_id' => $itemId, 'target_status' => $target],
            $this->auditBase($action, 'school_sale_item', "Changed School Sales item lifecycle to {$target}.", $actor),
            function (PDO $pdo) use ($itemId, $target, $actorId): CatalogMutationOutcome {
                $before = $this->lockedItem($itemId);
                if ($before['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                if ($target === 'Inactive' && $before['status'] !== 'Active') throw new SchoolSalesCatalogValidationException('ITEM_NOT_ACTIVE');
                if ($target === 'Archived' && $before['status'] === 'Active') throw new SchoolSalesCatalogValidationException('ACTIVE_ITEM_MUST_BE_DEACTIVATED_FIRST');
                $stmt = $pdo->prepare('UPDATE school_sale_items SET status = ?, updated_by = ? WHERE sale_item_id = ? AND status <> \'Archived\'');
                $stmt->execute([$target, $actorId, $itemId]);
                $after = $this->lockedItem($itemId, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $itemId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor @return array<string,mixed> */
    private function createVariant(int $itemId, string $correlationId, array $input, array $actor, bool $standardAction): array
    {
        $this->requireManage($actor);
        $itemId = $this->positiveId($itemId, 'sale_item_id');
        $data = $this->normalizeVariantInput($input);
        $data['actor_user_id'] = $this->actorId($actor);
        if ($standardAction && $data['variant_code'] !== 'STANDARD') {
            throw new SchoolSalesCatalogValidationException('STANDARD_VARIANT_CODE_REQUIRED');
        }
        return $this->mutation()->execute(
            $correlationId,
            ['action' => $standardAction ? 'create_standard_draft_variant' : 'create_draft_variant', 'sale_item_id' => $itemId, 'input' => $data],
            $this->auditBase('school_sale_variant_created', 'school_sale_variant', 'Created a Draft School Sales item variant.', $actor),
            function (PDO $pdo) use ($itemId, $data): CatalogMutationOutcome {
                $item = $this->lockedItem($itemId);
                if ($item['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
                $this->assertVariantIdentityAvailable($itemId, $data['variant_code'], $data['sku']);
                $stmt = $pdo->prepare(
                    "INSERT INTO school_sale_item_variants
                        (sale_item_id, variant_code, variant_name, sku, size_label, variant_metadata,
                         sort_order, status, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'Draft', ?, ?)"
                );
                $stmt->execute([$itemId, $data['variant_code'], $data['variant_name'], $data['sku'],
                    $data['size_label'], $data['variant_metadata_json'], $data['sort_order'],
                    $data['actor_user_id'], $data['actor_user_id']]);
                $id = (int) $pdo->lastInsertId();
                $after = $this->lockedVariant($id, false);
                return new CatalogMutationOutcome($after, ['entity_id' => $id, 'after_state' => $after]);
            }
        );
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function changeVariantLifecycle(int $variantId, string $correlationId, array $actor, string $target, string $action): array
    {
        $this->requireAnyCatalogMutationPermission($actor);
        $variantId = $this->positiveId($variantId, 'sale_variant_id');
        $actorId = $this->actorId($actor);
        return $this->mutation()->execute(
            $correlationId,
            ['action' => $action, 'sale_variant_id' => $variantId, 'target_status' => $target],
            $this->auditBase($action, 'school_sale_variant', "Changed School Sales variant lifecycle to {$target}.", $actor),
            function (PDO $pdo) use ($variantId, $target, $actorId, $actor): CatalogMutationOutcome {
                $candidate = $this->lockedVariant($variantId, false);
                $item = $this->lockedItem((int) $candidate['sale_item_id']);
                $before = $this->lockedVariant($variantId);
                if ($before['status'] === 'Archived') throw new SchoolSalesCatalogValidationException('ARCHIVED_VARIANT_IMMUTABLE');
                if ($item['status'] === 'Active' && $before['status'] === 'Active') {
                    $this->requirePermission($actor, 'school_sales.catalog.activate');
                } else {
                    $this->requirePermission($actor, 'school_sales.catalog.manage');
                }
                if ($target === 'Archived' && $before['status'] === 'Active') {
                    throw new SchoolSalesCatalogValidationException('ACTIVE_VARIANT_MUST_BE_DEACTIVATED_FIRST');
                }
                if ($target === 'Inactive' && $before['status'] === 'Inactive') {
                    throw new SchoolSalesCatalogValidationException('VARIANT_ALREADY_INACTIVE');
                }
                $stmt = $pdo->prepare('UPDATE school_sale_item_variants SET status = ?, updated_by = ? WHERE sale_variant_id = ? AND status <> \'Archived\'');
                $stmt->execute([$target, $actorId, $variantId]);
                $after = $this->lockedVariant($variantId, false);
                if ($item['status'] === 'Active') {
                    $readiness = $this->activationReadiness((int) $item['sale_item_id'], $this->now(), true);
                    if (!$readiness['ready']) {
                        throw new SchoolSalesCatalogValidationException('ACTIVE_ITEM_INVARIANT_VIOLATION:' . implode(',', array_column($readiness['failures'], 'code')));
                    }
                }
                return new CatalogMutationOutcome($after, ['entity_id' => $variantId, 'before_state' => $before, 'after_state' => $after]);
            }
        );
    }

    private function mutation(): SchoolSalesCatalogMutationInfrastructure
    {
        if ($this->mutationInfrastructure === null) {
            throw new LogicException('Catalog mutation infrastructure is not configured.');
        }
        return $this->mutationInfrastructure;
    }

    /** @param array<string,mixed> $actor */
    private function requirePermission(array $actor, string $permission): void
    {
        $permissions = $actor['permissions'] ?? [];
        if (!is_array($permissions) || !in_array($permission, $permissions, true)) {
            throw new SchoolSalesCatalogAuthorizationException(strtoupper(str_replace('.', '_', $permission)) . '_REQUIRED');
        }
        $this->actorId($actor);
    }

    /** @param array<string,mixed> $actor */
    private function requireAnyCatalogMutationPermission(array $actor): void
    {
        $permissions = $actor['permissions'] ?? [];
        if (!is_array($permissions)
            || (!in_array('school_sales.catalog.manage', $permissions, true)
                && !in_array('school_sales.catalog.activate', $permissions, true))) {
            throw new SchoolSalesCatalogAuthorizationException('SCHOOL_SALES_CATALOG_MUTATION_PERMISSION_REQUIRED');
        }
        $this->actorId($actor);
    }

    /** @param array<string,mixed> $actor */
    private function requireManage(array $actor): void
    {
        $this->requirePermission($actor, 'school_sales.catalog.manage');
    }

    /** @return array<string,mixed> */
    private function activationReadiness(int $itemId, string $now, bool $lock): array
    {
        $itemSql = 'SELECT sale_item_id, item_code, sale_category_id, sale_item_type_id, item_name,
                           description, applicability_mode, status, created_by, updated_by, created_at, updated_at
                    FROM school_sale_items WHERE sale_item_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($itemSql); $stmt->execute([$itemId]);
        $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$itemRow) {
            return ['item_id' => $itemId, 'evaluated_at' => $now, 'ready' => false,
                'failures' => [['code' => 'ITEM_NOT_FOUND', 'message' => 'Catalog item does not exist.', 'entity_id' => $itemId]],
                'summary' => ['active_variants' => 0, 'active_applicability' => 0]];
        }
        $item = $this->itemRow($itemRow);
        $failures = [];
        $fail = static function (string $code, string $message, ?int $entityId = null) use (&$failures): void {
            $entry = ['code' => $code, 'message' => $message];
            if ($entityId !== null) $entry['entity_id'] = $entityId;
            $failures[] = $entry;
        };
        if ($item['status'] === 'Archived') $fail('ITEM_ARCHIVED', 'Archived catalog items are terminal.', $itemId);

        $stmt = $this->pdo->prepare('SELECT sale_category_id, status FROM school_sale_categories WHERE sale_category_id = ?' . $this->lockSuffix($lock));
        $stmt->execute([$item['sale_category_id']]); $category = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category || $category['status'] !== 'Active') $fail('CATEGORY_INACTIVE', 'Parent category must be Active.', (int) $item['sale_category_id']);

        $stmt = $this->pdo->prepare('SELECT sale_item_type_id, type_code, status FROM school_sale_item_types WHERE sale_item_type_id = ?' . $this->lockSuffix($lock));
        $stmt->execute([$item['sale_item_type_id']]); $type = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$type || $type['status'] !== 'Active') $fail('ITEM_TYPE_INACTIVE', 'Controlled item type must be Active.', (int) $item['sale_item_type_id']);
        $bookDetails = null;
        if ($type && strtoupper((string) $type['type_code']) === 'BOOK') {
            $bookDetails = $this->lockedBookDetails($itemId, $lock);
            if ($bookDetails === null) {
                $fail('BOOK_DETAILS_MISSING', 'BOOK items require Book metadata before activation.', $itemId);
            } else {
                foreach ($this->bookDetailsValidationFailures($bookDetails) as $bookFailure) {
                    $fail($bookFailure['code'], $bookFailure['message'], $itemId);
                }
            }
        }

        $variantSql = 'SELECT sale_variant_id, sale_item_id, variant_code, variant_name, sku, size_label,
                              variant_metadata, sort_order, status, created_by, updated_by, created_at, updated_at
                       FROM school_sale_item_variants WHERE sale_item_id = ?
                       ORDER BY sale_variant_id' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($variantSql); $stmt->execute([$itemId]);
        $variants = array_map(fn(array $row): array => $this->variantRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $activeVariants = array_values(array_filter($variants, static fn(array $row): bool => $row['status'] === 'Active'));
        if ($activeVariants === []) $fail('NO_ACTIVE_VARIANTS', 'At least one Active variant is required.', $itemId);

        $pricesByVariant = [];
        if ($activeVariants !== []) {
            $ids = array_column($activeVariants, 'sale_variant_id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $priceSql = "SELECT sale_variant_price_id, sale_variant_id, amount, currency, effective_from,
                                effective_to, status, created_by, updated_by, activated_by, retired_by,
                                created_at, updated_at, activated_at, retired_at
                         FROM school_sale_variant_prices WHERE sale_variant_id IN ({$marks})
                         ORDER BY sale_variant_id, effective_from, sale_variant_price_id" . $this->lockSuffix($lock);
            $stmt = $this->pdo->prepare($priceSql); $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $price = $this->priceRow($row, $now);
                $pricesByVariant[(int) $price['sale_variant_id']][] = $price;
            }
        }
        foreach ($activeVariants as $variant) {
            $variantId = (int) $variant['sale_variant_id'];
            $prices = $pricesByVariant[$variantId] ?? [];
            $current = array_values(array_filter($prices, static fn(array $price): bool => $price['is_current']));
            if (count($current) === 0) {
                $fail('ACTIVE_VARIANT_MISSING_CURRENT_PRICE', 'Every Active variant needs one current Active price.', $variantId);
            } elseif (count($current) > 1) {
                $fail('ACTIVE_VARIANT_MULTIPLE_CURRENT_PRICES', 'An Active variant has multiple current Active prices.', $variantId);
            }
            if ($this->activePriceWindowsOverlap($prices)) {
                $fail('ACTIVE_PRICE_WINDOW_OVERLAP', 'Active price windows must not overlap.', $variantId);
            }
        }

        $applicability = $this->lockApplicabilityRows($itemId, $lock);
        $activeApplicability = array_values(array_filter($applicability, static fn(array $row): bool => $row['status'] === 'Active'));
        if ($item['applicability_mode'] === 'ALL' && $activeApplicability !== []) {
            $fail('ALL_HAS_ACTIVE_APPLICABILITY', 'ALL mode requires zero Active applicability assignments.', $itemId);
        } elseif ($item['applicability_mode'] === 'RESTRICTED' && $activeApplicability === []) {
            $fail('RESTRICTED_HAS_NO_ACTIVE_APPLICABILITY', 'RESTRICTED mode requires an Active applicability assignment.', $itemId);
        }

        return [
            'item_id' => $itemId, 'evaluated_at' => $now, 'ready' => $failures === [],
            'failures' => $failures,
            'summary' => ['active_variants' => count($activeVariants),
                'active_applicability' => count($activeApplicability),
                'applicability_mode' => $item['applicability_mode'],
                'item_status' => $item['status'],
                'item_type_code' => $type['type_code'] ?? null,
                'book_details_present' => $bookDetails !== null],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeBookDetailsInput(array $input): array
    {
        $title = $this->requiredString($input['book_title'] ?? null, 255, 'INVALID_BOOK_TITLE');
        $author = $this->nullableLimitedString($input['author'] ?? null, 255);
        $publisher = $this->nullableLimitedString($input['publisher'] ?? null, 255);
        $edition = $this->nullableLimitedString($input['edition'] ?? null, 100);
        $notes = $this->nullableLimitedString($input['notes'] ?? null, 16000);
        $isbn = $this->nullableLimitedString($input['isbn'] ?? null, 32);
        if ($isbn !== null && (strlen($isbn) < 10 || !preg_match('/^[0-9Xx -]+$/', $isbn))) {
            throw new SchoolSalesCatalogValidationException('INVALID_BOOK_ISBN');
        }
        return ['book_title' => $title, 'author' => $author, 'publisher' => $publisher,
            'edition' => $edition, 'isbn' => $isbn, 'notes' => $notes];
    }

    private function assertBookItem(int $itemId, bool $lock, bool $forMutation): void
    {
        $sql = 'SELECT i.status, t.type_code
                FROM school_sale_items i
                JOIN school_sale_item_types t ON t.sale_item_type_id = i.sale_item_type_id
                WHERE i.sale_item_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new SchoolSalesCatalogValidationException('ITEM_NOT_FOUND');
        if (strtoupper((string) $item['type_code']) !== 'BOOK') {
            throw new SchoolSalesCatalogValidationException('BOOK_ITEM_REQUIRED');
        }
        if ($forMutation && $item['status'] === 'Archived') {
            throw new SchoolSalesCatalogValidationException('ARCHIVED_ITEM_IMMUTABLE');
        }
        if ($forMutation && $item['status'] === 'Active') {
            throw new SchoolSalesCatalogValidationException('ACTIVE_BOOK_DETAILS_IMMUTABLE');
        }
    }

    private function itemTypeIsBook(int $itemTypeId, bool $lock): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT type_code FROM school_sale_item_types WHERE sale_item_type_id = ?' . $this->lockSuffix($lock)
        );
        $stmt->execute([$itemTypeId]);
        return strtoupper((string) $stmt->fetchColumn()) === 'BOOK';
    }

    /** @return array<string,mixed>|null */
    private function lockedBookDetails(int $itemId, bool $lock): ?array
    {
        $sql = 'SELECT sale_book_detail_id, sale_item_id, book_title, author, publisher,
                       edition, isbn, notes, created_by, updated_by, created_at, updated_at
                FROM school_sale_book_details WHERE sale_item_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->bookDetailsRow($row) : null;
    }

    /** @param array<string,mixed> $details @return array<int,array{code:string,message:string}> */
    private function bookDetailsValidationFailures(array $details): array
    {
        $failures = [];
        if (trim((string) ($details['book_title'] ?? '')) === '' || mb_strlen((string) $details['book_title']) > 255) {
            $failures[] = ['code' => 'BOOK_TITLE_INVALID', 'message' => 'BOOK title is required and must fit the deployed schema.'];
        }
        foreach (['author' => 255, 'publisher' => 255, 'edition' => 100] as $field => $max) {
            $value = $details[$field] ?? null;
            if ($value !== null && (trim((string) $value) === '' || mb_strlen((string) $value) > $max)) {
                $failures[] = ['code' => 'BOOK_' . strtoupper($field) . '_INVALID', 'message' => "BOOK {$field} violates the deployed schema."];
            }
        }
        $isbn = $details['isbn'] ?? null;
        if ($isbn !== null && (strlen(trim((string) $isbn)) < 10 || strlen(trim((string) $isbn)) > 32
            || !preg_match('/^[0-9Xx -]+$/', (string) $isbn))) {
            $failures[] = ['code' => 'BOOK_ISBN_INVALID', 'message' => 'BOOK ISBN violates the deployed schema.'];
        }
        $notes = $details['notes'] ?? null;
        if ($notes !== null && trim((string) $notes) === '') {
            $failures[] = ['code' => 'BOOK_NOTES_INVALID', 'message' => 'BOOK notes must be NULL or nonblank.'];
        }
        return $failures;
    }

    /** @param array<int,array<string,mixed>> $prices */
    private function activePriceWindowsOverlap(array $prices): bool
    {
        $active = array_values(array_filter($prices, static fn(array $price): bool => $price['status'] === 'Active'));
        usort($active, static fn(array $a, array $b): int => strcmp($a['effective_from'], $b['effective_from']));
        for ($i = 0, $count = count($active); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $leftEnd = $active[$i]['effective_to'];
                if ($leftEnd !== null && $leftEnd <= $active[$j]['effective_from']) break;
                $rightEnd = $active[$j]['effective_to'];
                if (($leftEnd === null || $active[$j]['effective_from'] < $leftEnd)
                    && ($rightEnd === null || $active[$i]['effective_from'] < $rightEnd)) return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $scope @return array{program_code:?string,year_level:?string} */
    private function normalizeApplicabilityScope(array $scope): array
    {
        $program = $scope['program_code'] ?? null;
        $year = $scope['year_level'] ?? null;
        if ($program !== null && !is_string($program)) throw new SchoolSalesCatalogValidationException('INVALID_PROGRAM_CODE');
        if ($year !== null && !is_string($year)) throw new SchoolSalesCatalogValidationException('INVALID_YEAR_LEVEL');
        $program = $program === null || trim($program) === '' ? null : strtoupper(trim($program));
        $year = $year === null || trim($year) === '' ? null : trim($year);
        if ($program === null && $year === null) throw new SchoolSalesCatalogValidationException('INVALID_APPLICABILITY_SCOPE');
        if ($program !== null && (strlen($program) > 60 || !preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $program))) {
            throw new SchoolSalesCatalogValidationException('INVALID_PROGRAM_CODE');
        }
        if ($year !== null && !in_array($year, ['1', '2', '3', '4'], true)) {
            throw new SchoolSalesCatalogValidationException('INVALID_YEAR_LEVEL');
        }
        $provider = $this->applicabilityProvider();
        if ($program !== null && $year !== null && !$provider->isCanonicalProgramYear($program, $year)) {
            throw new SchoolSalesCatalogValidationException('UNKNOWN_CANONICAL_PROGRAM_YEAR');
        }
        if ($program !== null && $year === null && !$provider->isCanonicalProgram($program)) {
            throw new SchoolSalesCatalogValidationException('UNKNOWN_CANONICAL_PROGRAM_CODE');
        }
        if ($program === null && $year !== null && !$provider->isCanonicalYearLevel($year)) {
            throw new SchoolSalesCatalogValidationException('UNKNOWN_CANONICAL_YEAR_LEVEL');
        }
        return ['program_code' => $program, 'year_level' => $year];
    }

    private function applicabilityProvider(): SchoolSalesApplicabilityScopeProvider
    {
        if ($this->applicabilityScopeProvider === null) {
            throw new SchoolSalesCatalogValidationException('CANONICAL_APPLICABILITY_SOURCE_UNAVAILABLE');
        }
        return $this->applicabilityScopeProvider;
    }

    /** @param array{program_code:?string,year_level:?string} $scope */
    private function applicabilityScopeKey(array $scope): string
    {
        return ($scope['program_code'] ?? '__ANY__') . '|' . ($scope['year_level'] ?? '__ANY__');
    }

    /** @param array<int,array<string,mixed>> $rows @param array{program_code:?string,year_level:?string} $scope */
    private function assertActiveApplicabilityScopeAvailable(array $rows, array $scope): void
    {
        $key = $this->applicabilityScopeKey($scope);
        foreach ($rows as $row) {
            if ($row['status'] === 'Active' && $this->applicabilityScopeKey($row) === $key) {
                throw new SchoolSalesCatalogValidationException('DUPLICATE_ACTIVE_APPLICABILITY_SCOPE');
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function lockApplicabilityRows(int $itemId, bool $lock = true): array
    {
        $sql = 'SELECT sale_item_applicability_id, sale_item_id, program_code, year_level,
                       status, created_by, updated_by, deactivated_by, created_at,
                       updated_at, deactivated_at
                FROM school_sale_item_applicability WHERE sale_item_id = ?
                ORDER BY sale_item_applicability_id' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$itemId]);
        return array_map(fn(array $row): array => $this->applicabilityRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function lockedApplicabilityRow(int $assignmentId, bool $lock = true): array
    {
        $sql = 'SELECT sale_item_applicability_id, sale_item_id, program_code, year_level,
                       status, created_by, updated_by, deactivated_by, created_at,
                       updated_at, deactivated_at
                FROM school_sale_item_applicability WHERE sale_item_applicability_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$assignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new SchoolSalesCatalogValidationException('APPLICABILITY_ASSIGNMENT_NOT_FOUND');
        return $this->applicabilityRow($row);
    }

    private function deactivateActiveApplicabilityRows(int $itemId, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE school_sale_item_applicability
             SET status = 'Inactive', updated_by = ?, deactivated_by = ?, deactivated_at = ?
             WHERE sale_item_id = ? AND status = 'Active'"
        );
        $stmt->execute([$actorId, $actorId, $this->now(), $itemId]);
    }

    private function deactivateApplicabilityRow(int $assignmentId, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE school_sale_item_applicability
             SET status = 'Inactive', updated_by = ?, deactivated_by = ?, deactivated_at = ?
             WHERE sale_item_applicability_id = ? AND status = 'Active'"
        );
        $stmt->execute([$actorId, $actorId, $this->now(), $assignmentId]);
    }

    /** @param array<string,mixed> $item @param array<int,array<string,mixed>> $rows */
    private function assertApplicabilityModeConsistency(array $item, array $rows): void
    {
        $active = count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'Active'));
        if ($item['applicability_mode'] === 'ALL' && $active !== 0) {
            throw new SchoolSalesCatalogValidationException('ALL_MODE_REQUIRES_ZERO_ACTIVE_ASSIGNMENTS');
        }
        if ($item['status'] === 'Active' && $item['applicability_mode'] === 'RESTRICTED' && $active < 1) {
            throw new SchoolSalesCatalogValidationException('ACTIVE_RESTRICTED_ITEM_REQUIRES_ASSIGNMENT');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizePriceInput(array $input): array
    {
        if (array_key_exists('status', $input)) throw new SchoolSalesCatalogValidationException('CLIENT_CONTROLLED_PRICE_STATUS_FORBIDDEN');
        $amount = $this->normalizeAmount($input['amount'] ?? null);
        $rawCurrency = $input['currency'] ?? 'PHP';
        if (!is_string($rawCurrency)) throw new SchoolSalesCatalogValidationException('UNSUPPORTED_PRICE_CURRENCY');
        $currency = strtoupper(trim($rawCurrency));
        if ($currency !== 'PHP') throw new SchoolSalesCatalogValidationException('UNSUPPORTED_PRICE_CURRENCY');
        $from = $this->normalizeDateTime($input['effective_from'] ?? null, 'INVALID_EFFECTIVE_FROM');
        $rawTo = $input['effective_to'] ?? null;
        if ($rawTo !== null && !is_string($rawTo)) throw new SchoolSalesCatalogValidationException('INVALID_EFFECTIVE_TO');
        $to = $rawTo === null || trim($rawTo) === '' ? null : $this->normalizeDateTime($rawTo, 'INVALID_EFFECTIVE_TO');
        if ($to !== null && $to <= $from) throw new SchoolSalesCatalogValidationException('INVALID_EFFECTIVE_INTERVAL');
        return ['amount' => $amount, 'currency' => 'PHP', 'effective_from' => $from, 'effective_to' => $to];
    }

    private function normalizeAmount(mixed $value): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new SchoolSalesCatalogValidationException('INVALID_PRICE_AMOUNT');
        }
        $raw = trim((string) $value);
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $raw, $matches)) {
            throw new SchoolSalesCatalogValidationException('INVALID_PRICE_AMOUNT');
        }
        $cents = ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents <= 0) throw new SchoolSalesCatalogValidationException('INVALID_PRICE_AMOUNT');
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function normalizeDateTime(mixed $value, string $error): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            throw new SchoolSalesCatalogValidationException($error);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new SchoolSalesCatalogValidationException($error);
        }
        return $parsed->format('Y-m-d H:i:s');
    }

    /** @return array<int,array<string,mixed>> */
    private function lockVariantPrices(int $variantId): array
    {
        $sql = 'SELECT sale_variant_price_id, sale_variant_id, amount, currency, effective_from,
                       effective_to, status, created_by, updated_by, activated_by, retired_by,
                       created_at, updated_at, activated_at, retired_at
                FROM school_sale_variant_prices WHERE sale_variant_id = ?
                ORDER BY effective_from, sale_variant_price_id' . $this->lockSuffix(true);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$variantId]);
        return array_map(fn(array $row): array => $this->priceRow($row, $this->now()), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function lockedPrice(int $priceId, bool $lock = true): array
    {
        $sql = 'SELECT sale_variant_price_id, sale_variant_id, amount, currency, effective_from,
                       effective_to, status, created_by, updated_by, activated_by, retired_by,
                       created_at, updated_at, activated_at, retired_at
                FROM school_sale_variant_prices WHERE sale_variant_price_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$priceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new SchoolSalesCatalogValidationException('PRICE_NOT_FOUND');
        return $this->priceRow($row, $this->now());
    }

    private function assertEffectiveStartAvailable(int $variantId, string $effectiveFrom, ?int $excludeId = null): void
    {
        $sql = 'SELECT sale_variant_price_id FROM school_sale_variant_prices WHERE sale_variant_id = ? AND effective_from = ?';
        $params = [$variantId, $effectiveFrom];
        if ($excludeId !== null) { $sql .= ' AND sale_variant_price_id <> ?'; $params[] = $excludeId; }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) throw new SchoolSalesCatalogValidationException('DUPLICATE_PRICE_EFFECTIVE_START');
    }

    private function assertNoActivePriceOverlap(int $variantId, string $from, ?string $to, ?int $excludeId = null): void
    {
        $sql = "SELECT sale_variant_price_id FROM school_sale_variant_prices
                WHERE sale_variant_id = ? AND status = 'Active'
                  AND (effective_to IS NULL OR effective_to > ?)";
        $params = [$variantId, $from];
        if ($to !== null) { $sql .= ' AND effective_from < ?'; $params[] = $to; }
        if ($excludeId !== null) { $sql .= ' AND sale_variant_price_id <> ?'; $params[] = $excludeId; }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) throw new SchoolSalesCatalogValidationException('ACTIVE_PRICE_WINDOW_OVERLAP');
    }

    /** @param array<string,mixed> $variant */
    private function assertActiveVariantCurrentPriceInvariant(array $variant): void
    {
        if ($variant['status'] !== 'Active') return;
        $current = array_filter($this->lockVariantPrices((int) $variant['sale_variant_id']), fn(array $price): bool => $this->priceIsCurrent($price));
        if (count($current) !== 1) throw new SchoolSalesCatalogValidationException('ACTIVE_VARIANT_REQUIRES_EXACTLY_ONE_CURRENT_PRICE');
    }

    /** @param array<string,mixed> $price */
    private function priceIsCurrent(array $price): bool
    {
        $now = $this->now();
        return $price['status'] === 'Active' && $price['effective_from'] <= $now
            && ($price['effective_to'] === null || $price['effective_to'] > $now);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $actor */
    private function actorId(array $actor): int
    {
        $id = filter_var($actor['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new SchoolSalesCatalogAuthorizationException('VALID_ACTOR_REQUIRED');
        return (int) $id;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function auditBase(string $action, string $entityType, string $detail, array $actor): array
    {
        return [
            'action' => $action, 'module_key' => 'payment', 'entity_type' => $entityType,
            'detail' => $detail, 'actor_user_id' => $this->actorId($actor),
            'actor_user_name' => $this->nullableLimitedString($actor['user_name'] ?? null, 150),
            'actor_role_key' => $this->nullableLimitedString($actor['role_key'] ?? null, 40),
            'actor_ip_address' => $this->nullableLimitedString($actor['ip_address'] ?? null, 45),
            'actor_user_agent' => $this->nullableLimitedString($actor['user_agent'] ?? null, 255),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeItemInput(array $input): array
    {
        if (array_key_exists('status', $input)) throw new SchoolSalesCatalogValidationException('CLIENT_CONTROLLED_ITEM_STATUS_FORBIDDEN');
        $code = $this->requiredCode($input['item_code'] ?? null, 60, 'INVALID_ITEM_CODE');
        $name = $this->requiredString($input['item_name'] ?? null, 150, 'INVALID_ITEM_NAME');
        $categoryId = $this->inputPositiveId($input['sale_category_id'] ?? null, 'INVALID_CATEGORY_ID');
        $typeId = $this->inputPositiveId($input['sale_item_type_id'] ?? null, 'INVALID_ITEM_TYPE_ID');
        $mode = strtoupper(trim((string) ($input['applicability_mode'] ?? '')));
        if (!in_array($mode, ['ALL', 'RESTRICTED'], true)) throw new SchoolSalesCatalogValidationException('INVALID_APPLICABILITY_MODE');
        // TEXT is byte-limited; 16k Unicode characters remain safe under utf8mb4.
        $description = $this->nullableLimitedString($input['description'] ?? null, 16000);
        return ['item_code' => $code, 'item_name' => $name, 'sale_category_id' => $categoryId,
            'sale_item_type_id' => $typeId, 'description' => $description, 'applicability_mode' => $mode];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeVariantInput(array $input): array
    {
        if (array_key_exists('status', $input)) throw new SchoolSalesCatalogValidationException('CLIENT_CONTROLLED_VARIANT_STATUS_FORBIDDEN');
        $code = $this->requiredCode($input['variant_code'] ?? null, 60, 'INVALID_VARIANT_CODE');
        $name = $this->requiredString($input['variant_name'] ?? null, 120, 'INVALID_VARIANT_NAME');
        $sku = $this->nullableCode($input['sku'] ?? null, 80, 'INVALID_VARIANT_SKU');
        $size = $this->nullableLimitedString($input['size_label'] ?? null, 60);
        $sort = filter_var($input['sort_order'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
        if ($sort === false) throw new SchoolSalesCatalogValidationException('INVALID_VARIANT_SORT_ORDER');
        $metadata = $input['variant_metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) throw new SchoolSalesCatalogValidationException('INVALID_VARIANT_METADATA');
        return ['variant_code' => $code, 'variant_name' => $name, 'sku' => $sku,
            'size_label' => $size, 'variant_metadata' => $metadata,
            'variant_metadata_json' => $metadata === null ? null : CatalogCanonicalJson::encode($metadata),
            'sort_order' => (int) $sort];
    }

    private function assertCategoryAndType(int $categoryId, int $typeId): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM school_sale_categories WHERE sale_category_id = ?' . $this->lockSuffix(true));
        $stmt->execute([$categoryId]);
        if ($stmt->fetchColumn() !== 'Active') throw new SchoolSalesCatalogValidationException('ACTIVE_CATEGORY_REQUIRED');
        $stmt = $this->pdo->prepare('SELECT status FROM school_sale_item_types WHERE sale_item_type_id = ?' . $this->lockSuffix(true));
        $stmt->execute([$typeId]);
        if ($stmt->fetchColumn() !== 'Active') throw new SchoolSalesCatalogValidationException('ACTIVE_CONTROLLED_ITEM_TYPE_REQUIRED');
    }

    private function assertItemCodeAvailable(string $code, ?int $excludeId = null): void
    {
        $sql = 'SELECT sale_item_id FROM school_sale_items WHERE item_code = ?';
        $params = [$code];
        if ($excludeId !== null) { $sql .= ' AND sale_item_id <> ?'; $params[] = $excludeId; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) throw new SchoolSalesCatalogValidationException('DUPLICATE_ITEM_CODE');
    }

    private function assertVariantIdentityAvailable(int $itemId, string $code, ?string $sku, ?int $excludeId = null): void
    {
        $sql = 'SELECT sale_variant_id FROM school_sale_item_variants WHERE sale_item_id = ? AND variant_code = ?';
        $params = [$itemId, $code];
        if ($excludeId !== null) { $sql .= ' AND sale_variant_id <> ?'; $params[] = $excludeId; }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) throw new SchoolSalesCatalogValidationException('DUPLICATE_VARIANT_CODE');
        if ($sku !== null) {
            $sql = 'SELECT sale_variant_id FROM school_sale_item_variants WHERE sku = ?';
            $params = [$sku];
            if ($excludeId !== null) { $sql .= ' AND sale_variant_id <> ?'; $params[] = $excludeId; }
            $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) throw new SchoolSalesCatalogValidationException('DUPLICATE_VARIANT_SKU');
        }
    }

    /** @return array<string,mixed> */
    private function lockedItem(int $itemId, bool $lock = true): array
    {
        $sql = 'SELECT sale_item_id, item_code, sale_category_id, sale_item_type_id, item_name,
                       description, applicability_mode, status, created_by, updated_by, created_at, updated_at
                FROM school_sale_items WHERE sale_item_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new SchoolSalesCatalogValidationException('ITEM_NOT_FOUND');
        return $this->itemRow($row);
    }

    /** @return array<string,mixed> */
    private function lockedVariant(int $variantId, bool $lock = true): array
    {
        $sql = 'SELECT sale_variant_id, sale_item_id, variant_code, variant_name, sku, size_label,
                       variant_metadata, sort_order, status, created_by, updated_by, created_at, updated_at
                FROM school_sale_item_variants WHERE sale_variant_id = ?' . $this->lockSuffix($lock);
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new SchoolSalesCatalogValidationException('VARIANT_NOT_FOUND');
        return $this->variantRow($row);
    }

    private function lockSuffix(bool $lock): string
    {
        return $lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
    }

    private function requiredCode(mixed $value, int $max, string $error): string
    {
        if (!is_scalar($value)) throw new SchoolSalesCatalogValidationException($error);
        $value = strtoupper(trim((string) $value));
        if ($value === '' || strlen($value) > $max || !preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $value)) {
            throw new SchoolSalesCatalogValidationException($error);
        }
        return $value;
    }

    private function nullableCode(mixed $value, int $max, string $error): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value)) throw new SchoolSalesCatalogValidationException($error);
        if (trim((string) $value) === '') return null;
        return $this->requiredCode($value, $max, $error);
    }

    private function requiredString(mixed $value, int $max, string $error): string
    {
        if (!is_scalar($value)) throw new SchoolSalesCatalogValidationException($error);
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) throw new SchoolSalesCatalogValidationException($error);
        return $value;
    }

    private function nullableLimitedString(mixed $value, int $max): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value)) throw new SchoolSalesCatalogValidationException('INVALID_STRING_VALUE');
        $value = trim((string) $value);
        if ($value === '') return null;
        if (mb_strlen($value) > $max) throw new SchoolSalesCatalogValidationException('VALUE_TOO_LONG');
        return $value;
    }

    private function inputPositiveId(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new SchoolSalesCatalogValidationException($error);
        return (int) $id;
    }

    private function itemSelect(): string
    {
        return 'SELECT i.sale_item_id, i.item_code, i.sale_category_id, i.sale_item_type_id,
                       i.item_name, i.description, i.applicability_mode, i.status,
                       i.created_by, i.updated_by, i.created_at, i.updated_at,
                       c.category_code, c.category_name, c.status AS category_status,
                       t.type_code, t.type_name, t.metadata_profile, t.status AS item_type_status
                FROM school_sale_items i
                JOIN school_sale_categories c ON c.sale_category_id = i.sale_category_id
                JOIN school_sale_item_types t ON t.sale_item_type_id = i.sale_item_type_id';
    }

    private function categoryRow(array $row): array
    {
        return $this->castIds($row, ['sale_category_id', 'sort_order', 'created_by', 'updated_by']);
    }

    private function itemTypeRow(array $row): array
    {
        return $this->castIds($row, ['sale_item_type_id', 'sort_order', 'created_by', 'updated_by']);
    }

    private function itemRow(array $row): array
    {
        return $this->castIds($row, ['sale_item_id', 'sale_category_id', 'sale_item_type_id', 'created_by', 'updated_by']);
    }

    private function variantRow(array $row): array
    {
        $row = $this->castIds($row, ['sale_variant_id', 'sale_item_id', 'sort_order', 'created_by', 'updated_by']);
        $row['variant_metadata'] = $row['variant_metadata'] === null ? null : json_decode((string) $row['variant_metadata'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function priceRow(array $row, string $now): array
    {
        $row = $this->castIds($row, ['sale_variant_price_id', 'sale_variant_id', 'created_by', 'updated_by', 'activated_by', 'retired_by']);
        $row['amount'] = number_format((float) $row['amount'], 2, '.', '');
        $row['is_current'] = $row['status'] === 'Active'
            && (string) $row['effective_from'] <= $now
            && ($row['effective_to'] === null || (string) $row['effective_to'] > $now);
        return $row;
    }

    private function applicabilityRow(array $row): array
    {
        return $this->castIds($row, ['sale_item_applicability_id', 'sale_item_id', 'created_by', 'updated_by', 'deactivated_by']);
    }

    private function bookDetailsRow(array $row): array
    {
        return $this->castIds($row, ['sale_book_detail_id', 'sale_item_id', 'created_by', 'updated_by']);
    }

    private function castIds(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) $row[$field] = (int) $row[$field];
        }
        return $row;
    }

    private function positiveId(int $value, string $field): int
    {
        if ($value <= 0) throw new InvalidArgumentException("{$field} must be a positive integer.");
        return $value;
    }
}
