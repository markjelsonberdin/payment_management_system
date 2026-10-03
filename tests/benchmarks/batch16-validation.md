# Batch 16 validation

Production change: SimpleXlsxWriter.php only. Accounting endpoint and both reporting services retain their Batch 15 SHA256 values.

Test-only namespace doubles evaluate the actual writer source, without changing its statements or adding production hooks. Run each scenario in a separate PHP process:

`php tests/payment-xlsx-failure-regression.php SCENARIO`

Scenarios: temp, open, entry1 through entry5, close, size, empty, read, readthrow, success. All 13 passed; results are in batch16-failure-results.json. The baseline text file is a frozen pre-hardening source fixture used with `success baseline` to compare all five package XML hashes against `success`. All entries match exactly. Doubles establish control flow, not physical disk-failure behavior. Real ZIP/XML/content validation uses the existing Batch 15 benchmark harness.

Representative real writer runs: 100 rows, 2 MiB peak, 3.926 ms, 4252 bytes; 5000 rows, 8 MiB peak, 117.148 ms, 112864 bytes. Valid ZIP, exact cells, inline strings, no formulas, zero temporary orphans in both runs. Memory excludes native libzip RSS. Timing is a single observed run, not a statistical performance claim. Batch 15 5000-row baseline: 8 MiB, 116.382 ms.

Real reporting pipeline: 100 matching rows, 2 MiB peak, 291.195 ms, 4539 bytes. ZIP, XML, headings, all values, allocation amounts and stable ordering passed. Page size 3 did not truncate export. Transaction rollback passed. Accounting regression: 86 passed. Canonical RBAC: 91 passed (existing sandbox session-directory warnings). Seven dedicated test tables independently counted zero after both database tests. No production database writes, MariaDB configuration or server changes.

Real injected conversion exception retained its original message and left zero orphans. Libzip removes an empty archive on close; cleanup handles this idempotently. Deletion failures are logged; cleanup is attempted on retained PHP control paths. Forced termination, process kill, fatal shutdown, disk permissions and OS failure cannot be guaranteed away. Post-header failures log DOWNLOAD TRANSPORT FAILURE and terminate after cleanup without appending JSON. Client delivery is not provable from readfile success.

Active callers: export-accounting-collections.php, export-history.php, export-cashier-history.php. Test callers: existing benchmark and new namespace regression. No other callers found. The ledger caller's pre-existing writer call outside its query catch remains unchanged; successful download/exit contracts remain intact.

Deferred: streaming, DB chunking, sheet rename, infrastructure hardening, background exports. BATCH 16 PASS.
