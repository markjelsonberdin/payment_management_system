# Batch 15 benchmark evidence

One fresh PHP process per tier calls production classes unchanged.
Modes: writer, pipeline, failure. Row count: 1–10,000.

Before launching PHP, set TMP and TEMP to a new empty dedicated directory.
Set B15_OUTPUT to output.xlsx in that directory. The harness rejects any other
PHP temporary directory. Never use a shared temp directory.

Pipeline requires ACCOUNTING_REPORTING_TEST_DSN/USER/PASS environment variables,
exact database payment_accounting_reporting_test, and seven empty InnoDB tables.
No root fallback. Credentials must be supplied privately.

Example:
  php tests/benchmarks/payment-accounting-xlsx-benchmark.php writer 100
  php tests/benchmarks/payment-accounting-xlsx-benchmark.php pipeline 100

JSON metrics go to stderr. An 8 KiB output-buffer callback captures the unchanged
writer's download to a temporary file. A shutdown handler runs despite exit,
validates the workbook, rolls back fixtures, and removes generated files.

Memory uses memory_get_usage(true) and memory_get_peak_usage(true), including
PHP allocator slack. It excludes native library process RSS. Peak and hrtime
are recorded before validation and rollback. Writer timing includes synthetic
row construction and output. Pipeline timing starts just before loadForExport,
includes reporting, functional assertions, endpoint-equivalent transformation,
writer and output. Fixture setup is timed separately. Limits remain unchanged.

Stop further tiers if allocator peak exceeds 256 MiB or export exceeds 60s,
or validation/rollback/cleanup fails. These half-limit tripwires preserve
headroom relative to 512 MiB / 120s; they are not production capacity claims.

Writer tiers: 100, 500, 1000, 2500, 5000, 10000.
Pipeline tiers: 100, 500, 1000, 2500, 5000.
Exact measurements and stage memory are retained in JSON files.
An initial pipeline attempt used 2041 beyond MariaDB TIMESTAMP range and failed
completeness. It rolled back; only synthetic fixture dates were corrected.

Validation: ZIP entries, DOM XML parsing, exact cells/row counts, no formulas,
explicit inline strings including =, +, -, @. No interactive Excel-opening
validation was performed. Pipeline verifies completeness, uniqueness, descending
timestamp/ID order, allocation-backed amounts, category/search filters,
start/last-second/end boundaries, statuses, and non-live Online exclusion.

Injected row-conversion failure was observed; no orphan temporary file remained
after unwinding. This does not prove all failure paths are safe. Production
writer still lacks a finally cleanup guarantee.

Decision: SAFE AS-IS FOR OBSERVED RANGE. Pipeline 5000 rows: 16 MiB peak,
492.245ms. Writer 10000 rows: 16 MiB peak, 229.790ms. Multiple stages contribute
growth. No streaming implementation is justified by these measurements alone.
Single synthetic runs are not a guaranteed production capacity.

Reporting regression: 86 PASS. RBAC: 91 PASS, with existing nonfatal session
directory warnings. All fixture tables are zero. Production file hashes match.
No production financial data was read. Only benchmark tooling/results added.
MariaDB was found offline and started with existing configuration. No manual
system-table, grant, schema, log, or configuration changes were made.
