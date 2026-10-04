<?php

declare(strict_types=1);

require_once __DIR__ . '/CashierSchoolSalesCatalogService.php';

final class CashierSchoolSalesCatalogApiController
{
    public function __construct(private readonly CashierSchoolSalesCatalogService $service) {}

    /** @param array<string,mixed> $query @return array{status:int,body:array<string,mixed>} */
    public function handle(string $method, array $query, bool $authenticated, bool $authorized): array
    {
        try {
            if (!$authenticated) return $this->error(401, 'AUTHENTICATION_REQUIRED', 'Authentication is required.');
            if (!$authorized) return $this->error(403, 'CASHIER_CATALOG_READ_FORBIDDEN', 'You do not have permission to read the Cashier catalog.');
            if (strtoupper($method) !== 'GET') return $this->error(405, 'METHOD_NOT_ALLOWED', 'Use GET for this read-only endpoint.');
            $studentId = $this->positiveInt($query, 'student_id');
            $action = trim((string) ($query['action'] ?? 'items'));
            $data = match ($action) {
                'categories' => $this->service->categoriesForStudent($studentId),
                'items' => $this->service->listSellableItems($studentId, $this->search($query), $this->optionalId($query, 'category_id'), $this->optionalId($query, 'item_type_id'), $this->page($query), $this->perPage($query)),
                'item_details' => $this->service->sellableItemDetails($studentId, $this->positiveInt($query, 'item_id')),
                default => throw new CashierSchoolSalesCatalogException('UNKNOWN_ACTION'),
            };
            return ['status' => 200, 'body' => ['ok' => true, 'data' => $data]];
        } catch (CashierSchoolSalesCatalogException $e) {
            $status = $e->errorCode === 'STUDENT_NOT_FOUND' ? 404 : ($e->errorCode === 'UNKNOWN_ACTION' ? 404 : 422);
            return $this->error($status, $e->errorCode, 'The requested Cashier catalog record is not available for sale.');
        } catch (InvalidArgumentException) { return $this->error(422, 'INVALID_REQUEST', 'The request is malformed or incomplete.'); }
        catch (Throwable $e) { error_log('Cashier catalog read failure: ' . $e->getMessage()); return $this->error(503, 'CASHIER_CATALOG_UNAVAILABLE', 'The Cashier catalog is temporarily unavailable.'); }
    }
    /** @param array<string,mixed> $source */ private function positiveInt(array $source, string $key): int { $id = filter_var($source[$key] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]); if ($id === false) throw new InvalidArgumentException(); return (int) $id; }
    /** @param array<string,mixed> $source */ private function optionalId(array $source, string $key): ?int { if (!isset($source[$key]) || $source[$key] === '') return null; return $this->positiveInt($source, $key); }
    /** @param array<string,mixed> $source */ private function search(array $source): ?string { $value = trim((string) ($source['q'] ?? '')); if (mb_strlen($value) > 100) throw new InvalidArgumentException(); return $value === '' ? null : $value; }
    /** @param array<string,mixed> $source */ private function page(array $source): int { return isset($source['page']) ? $this->positiveInt($source, 'page') : 1; }
    /** @param array<string,mixed> $source */ private function perPage(array $source): int { return isset($source['per_page']) ? $this->positiveInt($source, 'per_page') : 25; }
    /** @return array{status:int,body:array<string,mixed>} */ private function error(int $status, string $error, string $message): array { return ['status'=>$status,'body'=>['ok'=>false,'error'=>$error,'message'=>$message]]; }
}
