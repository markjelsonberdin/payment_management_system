-- Read-only preflight. This must return zero rows before applying the constraint migration.
SELECT fee_id, academic_year, semester, COUNT(*) AS version_count,
       GROUP_CONCAT(fee_version_id ORDER BY fee_version_id) AS fee_version_ids,
       GROUP_CONCAT(effective_status ORDER BY fee_version_id) AS statuses
FROM fee_versions
GROUP BY fee_id, academic_year, semester
HAVING COUNT(*) > 1
ORDER BY fee_id, academic_year, semester;
