-- Phase 5B School Sales foundation validation (READ ONLY).
-- Run on Hostforge production Payment DB hf_db_bfim0j6t after migration + seeds.
-- SQL proves schema/current-data properties only. Service behavior is out of scope.

SELECT 'production target' AS check_name,
       CASE WHEN DATABASE() = 'hf_db_bfim0j6t' THEN 'PASS' ELSE 'FAIL' END AS result,
       DATABASE() AS observed_value;

SELECT 'MariaDB production version' AS check_name,
       CASE WHEN VERSION() LIKE '11.8.8-MariaDB%' THEN 'PASS' ELSE 'FAIL' END AS result,
       VERSION() AS observed_value;

SELECT 'Phase 5B tables present with required engine and collation' AS check_name,
       CASE WHEN COUNT(*) = 4
                  AND SUM(engine = 'InnoDB') = 4
                  AND SUM(table_collation = 'utf8mb4_uca1400_ai_ci') = 4
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, ':', engine, ':', table_collation)
                    ORDER BY table_name SEPARATOR ', ') AS observed_value
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices',
                     'school_sale_item_applicability', 'payment_audit_outbox');

SELECT 'Phase 5B primary and foreign key datatype compatibility' AS check_name,
       CASE WHEN COUNT(*) = 7
                  AND SUM(column_type = 'int(10) unsigned') = 5
                  AND SUM(column_type = 'bigint(20) unsigned') = 2
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(table_name, '.', column_name, ':', column_type)
                    ORDER BY table_name, ordinal_position SEPARATOR ', ') AS observed_value
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'school_sale_items' AND column_name = 'sale_item_id')
    OR (table_name = 'school_sale_item_variants' AND column_name IN ('sale_variant_id', 'sale_item_id'))
    OR (table_name = 'school_sale_variant_prices' AND column_name IN ('sale_variant_price_id', 'sale_variant_id'))
    OR (table_name = 'school_sale_item_applicability' AND column_name IN ('sale_item_applicability_id', 'sale_item_id')));

SELECT 'required foreign keys present' AS check_name,
       CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME) AS observed_value
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND constraint_name IN ('fk_school_sale_variant_item',
                          'fk_school_sale_variant_price_variant',
                          'fk_school_sale_applicability_item');

SELECT 'required generated applicability columns present' AS check_name,
       CASE WHEN COUNT(*) = 2
                  AND SUM(extra LIKE '%STORED GENERATED%') = 2
            THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(column_name, ':', extra) ORDER BY column_name) AS observed_value
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'school_sale_item_applicability'
  AND column_name IN ('active_program_scope', 'active_year_scope');

SELECT 'Phase 5B CHECK constraints present' AS check_name,
       CASE WHEN COUNT(*) = 14 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_value
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE()
  AND constraint_type = 'CHECK'
  AND table_name IN ('school_sale_item_variants', 'school_sale_variant_prices',
                     'school_sale_item_applicability', 'payment_audit_outbox');

SELECT 'controlled item types exact' AS check_name,
       CASE WHEN COUNT(*) = 6 AND SUM(
           (type_code = 'UNIFORM' AND type_name = 'Uniform' AND metadata_profile = 'UNIFORM_VARIANT' AND status = 'Active' AND sort_order = 10)
           OR (type_code = 'BOOK' AND type_name = 'Book' AND metadata_profile = 'BOOK_REQUIRED' AND status = 'Active' AND sort_order = 20)
           OR (type_code = 'LEARNING_MATERIAL' AND type_name = 'Learning Material' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 30)
           OR (type_code = 'ACCESSORY' AND type_name = 'Accessory' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 40)
           OR (type_code = 'GRADUATION_ITEM' AND type_name = 'Graduation Item' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 50)
           OR (type_code = 'OTHER_MERCHANDISE' AND type_name = 'Other Merchandise' AND metadata_profile = 'STANDARD_PRODUCT' AND status = 'Active' AND sort_order = 60)
       ) = 6 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_value
FROM school_sale_item_types;

SELECT 'no catalog business rows seeded' AS check_name,
       CASE WHEN
           (SELECT COUNT(*) FROM school_sale_items) = 0
           AND (SELECT COUNT(*) FROM school_sale_item_variants) = 0
           AND (SELECT COUNT(*) FROM school_sale_variant_prices) = 0
           AND (SELECT COUNT(*) FROM school_sale_item_applicability) = 0
           AND (SELECT COUNT(*) FROM payment_audit_outbox) = 0
       THEN 'PASS' ELSE 'FAIL' END AS result,
       CONCAT('items=', (SELECT COUNT(*) FROM school_sale_items),
              ', variants=', (SELECT COUNT(*) FROM school_sale_item_variants),
              ', prices=', (SELECT COUNT(*) FROM school_sale_variant_prices),
              ', applicability=', (SELECT COUNT(*) FROM school_sale_item_applicability),
              ', outbox=', (SELECT COUNT(*) FROM payment_audit_outbox)) AS observed_value;

SELECT 'active items missing an active variant' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_value
FROM school_sale_items i
WHERE i.status = 'Active'
  AND NOT EXISTS (
      SELECT 1 FROM school_sale_item_variants v
      WHERE v.sale_item_id = i.sale_item_id AND v.status = 'Active'
  );

SELECT 'active variants without exactly one current active price' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_value
FROM school_sale_item_variants v
JOIN school_sale_items i ON i.sale_item_id = v.sale_item_id AND i.status = 'Active'
WHERE v.status = 'Active'
  AND (SELECT COUNT(*)
       FROM school_sale_variant_prices p
       WHERE p.sale_variant_id = v.sale_variant_id
         AND p.status = 'Active'
         AND p.effective_from <= UTC_TIMESTAMP()
         AND (p.effective_to IS NULL OR p.effective_to > UTC_TIMESTAMP())) <> 1;

SELECT 'overlapping active price periods' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_value
FROM school_sale_variant_prices p1
JOIN school_sale_variant_prices p2
  ON p2.sale_variant_id = p1.sale_variant_id
 AND p2.sale_variant_price_id > p1.sale_variant_price_id
 AND p2.status = 'Active'
 AND p1.effective_from < COALESCE(p2.effective_to, '9999-12-31 23:59:59')
 AND p2.effective_from < COALESCE(p1.effective_to, '9999-12-31 23:59:59')
WHERE p1.status = 'Active';

SELECT 'applicability mode consistent with active assignments' AS check_name,
       CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END AS result,
       COUNT(*) AS observed_value
FROM school_sale_items i
WHERE (i.applicability_mode = 'ALL' AND EXISTS (
          SELECT 1 FROM school_sale_item_applicability a
          WHERE a.sale_item_id = i.sale_item_id AND a.status = 'Active'))
   OR (i.applicability_mode = 'RESTRICTED' AND NOT EXISTS (
          SELECT 1 FROM school_sale_item_applicability a
          WHERE a.sale_item_id = i.sale_item_id AND a.status = 'Active'));

SELECT 'outbox correlation unique and fingerprint nonunique' AS check_name,
       CASE WHEN
           SUM(index_name = 'uq_payment_audit_outbox_correlation' AND non_unique = 0) = 1
           AND SUM(index_name = 'idx_payment_audit_outbox_fingerprint' AND non_unique = 1) = 1
           AND SUM(column_name = 'request_fingerprint' AND non_unique = 0) = 0
       THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(CONCAT(index_name, ':', column_name, ':non_unique=', non_unique)
                    ORDER BY index_name, seq_in_index SEPARATOR ', ') AS observed_value
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'payment_audit_outbox'
  AND (index_name IN ('uq_payment_audit_outbox_correlation', 'idx_payment_audit_outbox_fingerprint')
       OR column_name = 'request_fingerprint');

SELECT 'protected legacy domains remain present' AS check_name,
       CASE WHEN COUNT(*) = 8 THEN 'PASS' ELSE 'FAIL' END AS result,
       GROUP_CONCAT(table_name ORDER BY table_name) AS observed_value
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('fees', 'fee_categories', 'billing', 'payment_allocations',
                     'cash_sales', 'cash_sale_items', 'official_receipt_sequences',
                     'cashier_receipt_print_events');

-- Not proven by this SQL: transaction locking, API replay/conflict semantics,
-- cross-host delivery/retry, concurrent activation enforcement, BOOK validation,
-- Cashier route fail-closed behavior, or navigation hiding. Those require the
-- later service implementation and automated tests defined by the contracts.
