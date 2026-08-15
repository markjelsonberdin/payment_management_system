<?php
// ==========================================
// 1. CALL THE GLOBAL SYSTEM FILES
// ==========================================
// (I-adjust mo yung '../' depende kung gaano kalalim ang folder mo)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/authentication.php'; 
// Note: Isama mo rin dito yung database connection file niyo kung hiwalay siya

// ==========================================
// 2. ENFORCE SECURITY & RBAC (Your Job)
// ==========================================
// Siguraduhing naka-login ang user
requireAuth();

// I-lock ang module na ito para sa Admin (MIS) at Finance (Accounting) lang
$allowed_roles = ['admin', 'finance']; 
$current_role = getCurrentUserRoleKey(); 

if (!in_array($current_role, $allowed_roles)) {
    // Kapag student o unauthorized user, ibalik sa dashboard
    header('Location: ' . BASE_URL . '/dashboard/index.php?error=unauthorized_access');
    exit();
}

// ==========================================
// 3. MODULE-SPECIFIC SETTINGS
// ==========================================
// Mga variables na gagamitin mo sa buong Fee Setup pages
define('MODULE_TITLE', 'Fee Setup & Configuration');
define('MODULE_ICON', 'fas fa-money-check-alt');
define('FEE_TABLE', 'fees');

// (Optional) Pwede ka ring maglagay ng helper functions mo dito
// Halimbawa: Function para kunin agad lahat ng active fees
function getActiveFees($conn) {
    $stmt = $conn->prepare("SELECT * FROM " . FEE_TABLE . " WHERE status = 'Active' ORDER BY priority_order ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>