<?php
declare(strict_types=1);
// Compatibility URL now serves only the guarded technical MIS overview.
// Stale clients must never retain access to the retired financial payload.
require __DIR__ . '/mis_admin/overview.php';