<?php

declare(strict_types=1);

require_once __DIR__ . '/SchoolSalesCatalogService.php';

/** Thin JSON action router for the authoritative School Sales Catalog Service. */
final class SchoolSalesCatalogApiController
{
    public function __construct(private readonly SchoolSalesCatalogService $service)
    {
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $method, array $query, array $input, array $context): array
    {
        try {
            if (!($context['authenticated'] ?? false)) {
                return $this->error(401, 'AUTHENTICATION_REQUIRED', 'Authentication is required.');
            }
            $method = strtoupper($method);
            if (!in_array($method, ['GET', 'POST'], true)) {
                return $this->error(405, 'METHOD_NOT_ALLOWED', 'Use GET or POST for this endpoint.');
            }

            $action = trim((string) (($method === 'GET' ? $query : $input)['action'] ?? ''));
            if ($action === '') return $this->error(404, 'UNKNOWN_ACTION', 'Unknown School Sales catalog action.');

            if ($method === 'GET') {
                $this->requirePermission($context, 'school_sales.catalog.view');
                return $this->success($this->read($action, $query));
            }

            if (!($context['csrf_valid'] ?? false)) {
                return $this->error(403, 'CSRF_INVALID', 'Your security token is invalid or expired.');
            }
            $this->requireRoutePermission($action, $context);
            $actor = $context['actor'] ?? [];
            if (!is_array($actor)) throw new InvalidArgumentException('INVALID_ACTOR');
            $correlationId = trim((string) ($context['correlation_id'] ?? ''));
            try {
                CatalogCorrelationId::assertValid($correlationId);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('INVALID_CORRELATION_ID', 0, $e);
            }
            return $this->success($this->mutate($action, $input, $correlationId, $actor));
        } catch (SchoolSalesCatalogAuthorizationException $e) {
            return $this->error(403, $this->code($e), 'You do not have permission for this catalog operation.');
        } catch (CatalogCorrelationConflictException $e) {
            return $this->error(409, 'CORRELATION_ID_CONFLICT', 'The correlation ID belongs to a different request.');
        } catch (SchoolSalesCatalogReadException $e) {
            return $this->error(404, 'ITEM_NOT_FOUND', 'The requested catalog item was not found.');
        } catch (SchoolSalesCatalogValidationException $e) {
            $code = $this->code($e);
            $details = null;
            if (str_starts_with($e->getMessage(), 'ITEM_ACTIVATION_PREREQUISITES_FAILED:')) {
                $code = 'ITEM_ACTIVATION_PREREQUISITES_FAILED';
                $raw = substr($e->getMessage(), strlen($code) + 1);
                $details = ['prerequisite_codes' => $raw === '' ? [] : explode(',', $raw)];
            }
            return $this->error($this->validationStatus($code), $code, $this->safeValidationMessage($code), $details);
        } catch (InvalidArgumentException|JsonException $e) {
            return $this->error(422, $this->code($e, 'INVALID_REQUEST'), 'The request is malformed or incomplete.');
        } catch (PDOException $e) {
            if ($this->isConcurrencyFailure($e)) {
                return $this->error(409, 'CATALOG_CONCURRENCY_CONFLICT', 'The catalog changed concurrently. Refresh and try again.');
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $query */
    private function read(string $action, array $query): mixed
    {
        return match ($action) {
            'categories' => $this->service->categories(),
            'item_types' => $this->service->itemTypes(),
            'items' => $this->service->catalogItems(),
            'item_details' => $this->service->itemDetails($this->id($query, 'item_id')),
            'variants' => $this->service->itemVariants($this->id($query, 'item_id')),
            'variant_prices' => $this->service->resolveCurrentVariantPrice($this->id($query, 'variant_id')),
            'applicability' => $this->service->itemApplicabilityHistory($this->id($query, 'item_id')),
            'book_details' => $this->service->bookDetails($this->id($query, 'item_id')),
            'activation_readiness' => $this->service->evaluateItemActivationReadiness($this->id($query, 'item_id')),
            default => throw new SchoolSalesCatalogValidationException('UNKNOWN_ACTION'),
        };
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    private function mutate(string $action, array $input, string $correlationId, array $actor): mixed
    {
        return match ($action) {
            'create_item' => $this->service->createDraftItem($correlationId, $this->data($input), $actor),
            'update_item' => $this->service->updateDraftItem($this->id($input, 'item_id'), $correlationId, $this->data($input), $actor),
            'create_variant' => $this->service->createDraftVariant($this->id($input, 'item_id'), $correlationId, $this->data($input), $actor),
            'create_standard_variant' => $this->service->createStandardDraftVariant($this->id($input, 'item_id'), $correlationId, $actor, $this->data($input, false)),
            'update_variant' => $this->service->updateVariant($this->id($input, 'variant_id'), $correlationId, $this->data($input), $actor),
            'deactivate_variant' => $this->service->deactivateVariant($this->id($input, 'variant_id'), $correlationId, $actor),
            'archive_variant' => $this->service->archiveVariant($this->id($input, 'variant_id'), $correlationId, $actor),
            'create_price' => $this->service->createDraftVariantPrice($this->id($input, 'variant_id'), $correlationId, $this->data($input), $actor),
            'update_price' => $this->service->updateDraftVariantPrice($this->id($input, 'price_id'), $correlationId, $this->data($input), $actor),
            'activate_price' => $this->service->activateVariantPrice($this->id($input, 'price_id'), $correlationId, $actor),
            'replace_active_price' => $this->service->replaceActiveVariantPrice($this->id($input, 'variant_id'), $correlationId, $this->data($input), $actor),
            'retire_price' => $this->service->retireActiveVariantPrice($this->id($input, 'price_id'), $this->stringField($this->data($input), 'effective_to'), $correlationId, $actor),
            'cancel_draft_price', 'cancel_future_active_price' => $this->service->cancelVariantPrice($this->id($input, 'price_id'), $correlationId, $actor),
            'change_applicability_mode' => $this->service->changeItemApplicabilityMode($this->id($input, 'item_id'), $this->stringField($this->data($input), 'mode'), $correlationId, $actor),
            'add_applicability' => $this->service->addItemApplicabilityAssignment($this->id($input, 'item_id'), $correlationId, $this->data($input), $actor),
            'deactivate_applicability' => $this->service->deactivateItemApplicabilityAssignment($this->id($input, 'assignment_id'), $correlationId, $actor),
            'replace_applicability' => $this->service->replaceItemApplicabilityAssignments($this->id($input, 'item_id'), $correlationId, $this->listField($this->data($input), 'scopes'), $actor),
            'create_book_details' => $this->service->createBookDetails($this->id($input, 'item_id'), $correlationId, $this->data($input), $actor),
            'update_book_details' => $this->service->updateBookDetails($this->id($input, 'item_id'), $correlationId, $this->data($input), $actor),
            'activate_item' => $this->service->activateItem($this->id($input, 'item_id'), $correlationId, $actor),
            'deactivate_item' => $this->service->deactivateItem($this->id($input, 'item_id'), $correlationId, $actor),
            'archive_item' => $this->service->archiveItem($this->id($input, 'item_id'), $correlationId, $actor),
            default => throw new SchoolSalesCatalogValidationException('UNKNOWN_ACTION'),
        };
    }

    /** @param array<string,mixed> $context */
    private function requireRoutePermission(string $action, array $context): void
    {
        $manage = ['create_item','update_item','create_variant','create_standard_variant','update_variant',
            'create_price','update_price','change_applicability_mode','add_applicability',
            'deactivate_applicability','replace_applicability','create_book_details','update_book_details'];
        $activate = ['activate_price','replace_active_price','retire_price','activate_item','deactivate_item','archive_item'];
        $stateSensitive = ['deactivate_variant','archive_variant','cancel_draft_price','cancel_future_active_price'];
        if (in_array($action, $manage, true)) $this->requirePermission($context, 'school_sales.catalog.manage');
        elseif (in_array($action, $activate, true)) $this->requirePermission($context, 'school_sales.catalog.activate');
        elseif (in_array($action, $stateSensitive, true)) {
            if (!$this->hasPermission($context, 'school_sales.catalog.manage') && !$this->hasPermission($context, 'school_sales.catalog.activate')) {
                throw new SchoolSalesCatalogAuthorizationException('SCHOOL_SALES_CATALOG_MUTATION_PERMISSION_REQUIRED');
            }
        } else throw new SchoolSalesCatalogValidationException('UNKNOWN_ACTION');
    }

    /** @param array<string,mixed> $context */
    private function requirePermission(array $context, string $permission): void
    {
        if (!$this->hasPermission($context, $permission)) {
            throw new SchoolSalesCatalogAuthorizationException(strtoupper(str_replace('.', '_', $permission)) . '_REQUIRED');
        }
    }

    /** @param array<string,mixed> $context */
    private function hasPermission(array $context, string $permission): bool
    {
        return in_array($permission, is_array($context['permissions'] ?? null) ? $context['permissions'] : [], true);
    }

    /** @param array<string,mixed> $source */
    private function id(array $source, string $key): int
    {
        $id = filter_var($source[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('INVALID_' . strtoupper($key));
        return (int) $id;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function data(array $input, bool $required = true): array
    {
        if (!array_key_exists('data', $input)) {
            if (!$required) return [];
            throw new InvalidArgumentException('INVALID_DATA');
        }
        if (!is_array($input['data']) || array_is_list($input['data'])) throw new InvalidArgumentException('INVALID_DATA');
        return $input['data'];
    }

    /** @param array<string,mixed> $source */
    private function stringField(array $source, string $key): string
    {
        if (!isset($source[$key]) || !is_string($source[$key]) || trim($source[$key]) === '') {
            throw new InvalidArgumentException('INVALID_' . strtoupper($key));
        }
        return trim($source[$key]);
    }

    /** @param array<string,mixed> $source @return array<int,array<string,mixed>> */
    private function listField(array $source, string $key): array
    {
        if (!isset($source[$key]) || !is_array($source[$key]) || !array_is_list($source[$key])) {
            throw new InvalidArgumentException('INVALID_' . strtoupper($key));
        }
        foreach ($source[$key] as $row) if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException('INVALID_' . strtoupper($key));
        return $source[$key];
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function success(mixed $data): array { return ['status' => 200, 'body' => ['ok' => true, 'data' => $data]]; }

    /** @return array{status:int,body:array<string,mixed>} */
    private function error(int $status, string $code, string $message, ?array $details = null): array
    {
        $body = ['ok' => false, 'error' => $code, 'message' => $message];
        if ($details !== null) $body['details'] = $details;
        return ['status' => $status, 'body' => $body];
    }

    private function code(Throwable $e, string $fallback = 'INVALID_REQUEST'): string
    {
        $code = strtoupper(trim(explode(':', $e->getMessage(), 2)[0]));
        return preg_match('/^[A-Z][A-Z0-9_]*$/', $code) ? $code : $fallback;
    }

    private function validationStatus(string $code): int
    {
        if ($code === 'UNKNOWN_ACTION' || str_ends_with($code, '_NOT_FOUND')) return 404;
        foreach (['ALREADY','DUPLICATE','IMMUTABLE','NOT_DRAFT','NOT_ACTIVE','OVERLAP','INVARIANT',
                  'UNCHANGED','MUST_BE_DEACTIVATED','REQUIRES_REPLACEMENT','REQUIRES_LATER_SERVICE',
                  'MULTIPLE_CURRENT','CANNOT_BE_CANCELLED'] as $marker) {
            if (str_contains($code, $marker)) return 409;
        }
        return 422;
    }

    private function safeValidationMessage(string $code): string
    {
        return match ($code) {
            'UNKNOWN_ACTION' => 'Unknown School Sales catalog action.',
            'ITEM_NOT_FOUND', 'VARIANT_NOT_FOUND', 'PRICE_NOT_FOUND', 'APPLICABILITY_ASSIGNMENT_NOT_FOUND', 'BOOK_DETAILS_NOT_FOUND' => 'The requested catalog record was not found.',
            'ITEM_ACTIVATION_PREREQUISITES_FAILED' => 'The item does not meet its activation prerequisites.',
            default => 'The catalog request could not be applied.',
        };
    }

    private function isConcurrencyFailure(PDOException $e): bool
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        return $e->getCode() === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}
