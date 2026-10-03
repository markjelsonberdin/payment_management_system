<?php

declare(strict_types=1);

require_once __DIR__ . '/RegistrarCohortClient.php';

interface SchoolSalesApplicabilityScopeProvider
{
    public function isCanonicalProgram(string $programCode): bool;
    public function isCanonicalYearLevel(string $yearLevel): bool;
    public function isCanonicalProgramYear(string $programCode, string $yearLevel): bool;
}

/** Uses the existing authoritative Registrar cohort boundary; no local program list is invented. */
final class RegistrarSchoolSalesApplicabilityScopeProvider implements SchoolSalesApplicabilityScopeProvider
{
    /** @var array<int,array{program_code:string,year_level:string}>|null */
    private ?array $scopes = null;

    public function __construct(private readonly RegistrarCohortClient $client = new RegistrarCohortClient())
    {
    }

    public function isCanonicalProgram(string $programCode): bool
    {
        foreach ($this->scopes() as $scope) if ($scope['program_code'] === $programCode) return true;
        return false;
    }

    public function isCanonicalYearLevel(string $yearLevel): bool
    {
        if (!in_array($yearLevel, ['1', '2', '3', '4'], true)) return false;
        foreach ($this->scopes() as $scope) if ($scope['year_level'] === $yearLevel) return true;
        return false;
    }

    public function isCanonicalProgramYear(string $programCode, string $yearLevel): bool
    {
        foreach ($this->scopes() as $scope) {
            if ($scope['program_code'] === $programCode && $scope['year_level'] === $yearLevel) return true;
        }
        return false;
    }

    /** @return array<int,array{program_code:string,year_level:string}> */
    private function scopes(): array
    {
        if ($this->scopes !== null) return $this->scopes;
        if (!$this->client->configured()) {
            throw new SchoolSalesCatalogValidationException('CANONICAL_APPLICABILITY_SOURCE_UNAVAILABLE');
        }
        try {
            $context = $this->client->getActiveEnrollmentContext();
            $rows = $this->client->listAvailableCohorts($context);
        } catch (RegistrarCohortException $e) {
            throw new SchoolSalesCatalogValidationException('CANONICAL_APPLICABILITY_SOURCE_UNAVAILABLE', 0, $e);
        }
        $normalized = [];
        foreach ($rows as $row) {
            $program = strtoupper(trim((string) $row['program_code']));
            $year = trim((string) $row['year_level']);
            if ($program !== '' && in_array($year, ['1', '2', '3', '4'], true)) {
                $normalized[$program . '|' . $year] = ['program_code' => $program, 'year_level' => $year];
            }
        }
        if ($normalized === []) throw new SchoolSalesCatalogValidationException('CANONICAL_APPLICABILITY_SOURCE_UNAVAILABLE');
        return $this->scopes = array_values($normalized);
    }
}
