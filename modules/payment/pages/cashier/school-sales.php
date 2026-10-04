<?php
// Legacy route retained only for existing bookmarks. School Items now lives in the unified cashier portal.
$query = $_GET;
$query['view'] = 'school-items';
header('Location: payment-collection-portal.php?' . http_build_query($query), true, 302);
exit;
