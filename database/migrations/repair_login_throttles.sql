-- Repairs older production installs where login_throttles.id is required
-- but is not AUTO_INCREMENT. Run once against the main SMS2/payment database.

ALTER TABLE login_throttles
    ADD COLUMN migration_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;

DELETE older
FROM login_throttles AS older
JOIN login_throttles AS newer
  ON newer.throttle_key = older.throttle_key
 AND newer.migration_id > older.migration_id;

ALTER TABLE login_throttles
    DROP COLUMN id,
    CHANGE COLUMN migration_id id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD UNIQUE KEY uq_login_throttle_key (throttle_key),
    ADD KEY idx_login_throttle_ip (ip_address),
    ADD KEY idx_login_throttle_locked (locked_until);
