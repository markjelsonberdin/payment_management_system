<?php
declare(strict_types=1);
require_once __DIR__ . '/BillingStudentContext.php';

interface BillingStudentContextProvider { /** @param array<string,mixed> $input */ public function resolve(array $input): BillingStudentContext; }
final class AcademicContextUnavailableException extends RuntimeException { public function __construct() { parent::__construct('Authoritative academic billing context is unavailable.', 0); } public function codeName(): string { return 'ACADEMIC_CONTEXT_UNAVAILABLE'; } }
final class ProductionBillingStudentContextProvider implements BillingStudentContextProvider { public function resolve(array $input): BillingStudentContext { throw new AcademicContextUnavailableException(); } }
final class DevelopmentManualBillingStudentContextProvider implements BillingStudentContextProvider {
    public function resolve(array $input): BillingStudentContext {
        if (!BillingStudentContextProviderFactory::developmentEnabled()) throw new AcademicContextUnavailableException();
        $input['eligibility_source'] = 'development_manual';
        $input['metadata'] = array_merge(is_array($input['metadata'] ?? null) ? $input['metadata'] : [], ['program_code_canonical' => true, 'development_context' => true]);
        return BillingStudentContext::fromArray($input);
    }
}
final class BillingStudentContextProviderFactory {
    public static function developmentEnabled(): bool {
        $environment = strtolower((string) (getenv('APP_ENV') ?: getenv('PAYMENT_APP_ENV') ?: 'production'));
        return getenv('PAYMENT_MANAGED_BILLING_DEV_CONTEXT_ENABLED') === 'true' && in_array($environment, ['local', 'dev', 'development', 'test', 'testing'], true);
    }
    public static function current(): BillingStudentContextProvider { return self::developmentEnabled() ? new DevelopmentManualBillingStudentContextProvider() : new ProductionBillingStudentContextProvider(); }
}
