-- Phase 2 managed billing schema. PREPARE ONLY: do not execute without approval.
-- Ordered MariaDB DDL; do not rerun blindly after a failure.

-- Step 1: link future managed items to immutable fee versions. Legacy rows remain NULL.
ALTER TABLE billing_items
  ADD COLUMN fee_version_id BIGINT UNSIGNED NULL AFTER fee_id,
  ADD KEY idx_billing_items_fee_version (fee_version_id),
  ADD UNIQUE KEY uq_billing_items_billing_fee_version (billing_id, fee_version_id),
  ADD CONSTRAINT fk_billing_items_fee_version
    FOREIGN KEY (fee_version_id) REFERENCES fee_versions (fee_version_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT;

-- Step 2: one canonical managed Standard Assessment billing per student term.
CREATE TABLE managed_assessment_headers (
  managed_header_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  semester ENUM('1st','2nd','Summer') NOT NULL,
  billing_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (managed_header_id),
  UNIQUE KEY uq_managed_assessment_student_term (student_id, academic_year, semester),
  UNIQUE KEY uq_managed_assessment_billing (billing_id),
  CONSTRAINT fk_managed_assessment_header_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_managed_assessment_header_billing
    FOREIGN KEY (billing_id) REFERENCES billing (billing_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Step 3: durable managed billing-run control record.
CREATE TABLE billing_runs (
  run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year VARCHAR(20) NOT NULL,
  semester ENUM('1st','2nd','Summer') NOT NULL,
  course VARCHAR(100) DEFAULT NULL,
  year_level VARCHAR(20) DEFAULT NULL,
  status ENUM('Draft','Approved','Running','Completed','CompletedWithExceptions','Failed') NOT NULL DEFAULT 'Draft',
  initiated_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED DEFAULT NULL,
  projected_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  projected_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  actual_added_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_partially_added_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_already_assessed_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_no_applicable_fee_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_review_required_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_failed_student_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_added_item_count INT UNSIGNED NOT NULL DEFAULT 0,
  actual_added_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  processing_cursor INT UNSIGNED NOT NULL DEFAULT 0,
  runner_token CHAR(32) DEFAULT NULL,
  lease_expires_at TIMESTAMP NULL DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id),
  KEY idx_billing_runs_status (status, run_id),
  KEY idx_billing_runs_scope (academic_year, semester, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Step 4: fee-version decision/snapshot record for each run.
CREATE TABLE billing_run_fee_versions (
  run_fee_version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  fee_version_id BIGINT UNSIGNED NOT NULL,
  behavior ENUM('Standard','One-Time','Optional','Manual') NOT NULL,
  is_required TINYINT(1) NOT NULL,
  selection_state ENUM('Selected','Excluded') NOT NULL,
  preview_name_snapshot VARCHAR(100) NOT NULL,
  preview_amount_snapshot DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (run_fee_version_id),
  UNIQUE KEY uq_billing_run_fee_version (run_id, fee_version_id),
  KEY idx_billing_run_fee_versions_version (fee_version_id),
  CONSTRAINT chk_billing_run_fee_version_standard_required_selected
    CHECK (behavior <> 'Standard' OR is_required = 0 OR selection_state = 'Selected'),
  CONSTRAINT fk_billing_run_fee_versions_run
    FOREIGN KEY (run_id) REFERENCES billing_runs (run_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_billing_run_fee_versions_fee_version
    FOREIGN KEY (fee_version_id) REFERENCES fee_versions (fee_version_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Step 5: assignment detail is the source of truth; billing_runs fields are reconciled caches.
CREATE TABLE billing_run_assignments (
  assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  billing_id INT UNSIGNED DEFAULT NULL,
  status ENUM('Pending','Processing','Added','PartiallyAdded','AlreadyAssessed','NoApplicableFees','ReviewRequired','Failed') NOT NULL DEFAULT 'Pending',
  added_item_count INT UNSIGNED NOT NULL DEFAULT 0,
  added_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  excluded_count INT UNSIGNED NOT NULL DEFAULT 0,
  exception_code VARCHAR(100) DEFAULT NULL,
  exception_message VARCHAR(500) DEFAULT NULL,
  notification_state ENUM('NotRequired','Pending','PendingAccountLink','Sent','Failed') NOT NULL DEFAULT 'NotRequired',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  started_at TIMESTAMP NULL DEFAULT NULL,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  UNIQUE KEY uq_billing_run_assignment_student (run_id, student_id),
  KEY idx_billing_run_assignments_student (student_id),
  KEY idx_billing_run_assignments_run_status (run_id, status, assignment_id),
  KEY idx_billing_run_assignments_billing (billing_id),
  CONSTRAINT fk_billing_run_assignments_run
    FOREIGN KEY (run_id) REFERENCES billing_runs (run_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_billing_run_assignments_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_billing_run_assignments_billing
    FOREIGN KEY (billing_id) REFERENCES billing (billing_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Step 6: durable intent; payment_notifications remains student-facing delivery storage.
CREATE TABLE billing_notification_outbox (
  outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  billing_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(120) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  semester ENUM('1st','2nd','Summer') NOT NULL,
  new_assessment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  term_remaining_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  delivery_status ENUM('Pending','Processing','PendingAccountLink','Sent','Failed') NOT NULL DEFAULT 'Pending',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (outbox_id),
  UNIQUE KEY uq_billing_notification_outbox_event (event_key),
  UNIQUE KEY uq_billing_notification_outbox_run_student (run_id, student_id),
  KEY idx_billing_notification_outbox_delivery (delivery_status, outbox_id),
  KEY idx_billing_notification_outbox_run_status (run_id, delivery_status, outbox_id),
  KEY idx_billing_notification_outbox_student (student_id),
  CONSTRAINT fk_billing_notification_outbox_run
    FOREIGN KEY (run_id) REFERENCES billing_runs (run_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_billing_notification_outbox_student
    FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_billing_notification_outbox_billing
    FOREIGN KEY (billing_id) REFERENCES billing (billing_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
