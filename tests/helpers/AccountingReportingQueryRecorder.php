<?php
/** Test-only execution recorder; fixture queries are excluded by resetting the log. */
final class AccountingReportingQueryRecorder extends PDO
{
    public array $executed = [];
    public bool $failCategoryLookup = false;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failCategoryLookup && str_contains($query, 'SELECT category_id,category_name FROM fee_categories WHERE')) {
            throw new PDOException('Injected category metadata failure');
        }
        return parent::prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        $this->executed[] = $query;
        return $result;
    }
}
final class AccountingReportingRecordedStatement extends PDOStatement
{
    protected function __construct(private AccountingReportingQueryRecorder $owner) {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        $this->owner->executed[] = $this->queryString;
        return $result;
    }
}
