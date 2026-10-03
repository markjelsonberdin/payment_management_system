<?php
declare(strict_types=1);

/**
 * Normalized, source-agnostic input for the managed assessment preview.
 * Production callers must provide an authoritative context provider; this
 * value object deliberately does not fall back to students.course/year_level.
 */
final class BillingStudentContext
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly int $studentId,
        public readonly string $studentNumber,
        public readonly string $fullName,
        public readonly string $academicYear,
        public readonly string $semester,
        public readonly ?string $programCode,
        public readonly ?string $yearLevel,
        public readonly ?string $section,
        public readonly bool $financiallyEligible,
        public readonly string $eligibilitySource,
        public readonly array $metadata = [],
    ) {}

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $required = ['student_id', 'student_number', 'full_name', 'academic_year', 'semester', 'financially_eligible', 'eligibility_source'];
        foreach ($required as $field) if (!array_key_exists($field, $input)) throw new InvalidArgumentException("Billing student context is missing {$field}.");
        $year = trim((string) $input['academic_year']);
        $semester = trim((string) $input['semester']);
        if ((int) $input['student_id'] <= 0 || !preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, ['1st', '2nd', 'Summer'], true)) {
            throw new InvalidArgumentException('Billing student context has an invalid student or term.');
        }
        return new self((int) $input['student_id'], trim((string) $input['student_number']), trim((string) $input['full_name']), $year, $semester,
            self::nullable($input['program_code'] ?? null), self::nullable($input['year_level'] ?? null), self::nullable($input['section'] ?? null),
            (bool) $input['financially_eligible'], trim((string) $input['eligibility_source']), is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
    }

    public function hasCanonicalProgramCode(): bool
    {
        return $this->programCode !== null && ($this->metadata['program_code_canonical'] ?? false) === true;
    }

    private static function nullable(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
}
