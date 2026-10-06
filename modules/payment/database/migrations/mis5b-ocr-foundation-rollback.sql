-- Destructive rollback; requires separate authorization. Removes MIS-5B additions only.
DROP TABLE IF EXISTS ocr_image_cache;
DROP TABLE IF EXISTS ocr_scan_attempts;
DROP TABLE IF EXISTS ocr_usage_ledger;
DROP TABLE IF EXISTS ocr_usage_months;
