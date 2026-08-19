<?php
/**
 * SMS 2 - Payment User Management
 * Allows Finance Admin to manage Cashier and Finance accounts directly.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

// Ensure user has the specific payment user management permission
if (!userCanAccessModule('payment.user_management')) {
    header('Location: ' . BASE_URL . '/modules/payment/index.php');
    exit;
}

$pageTitle = 'Cashier Accounts';
$activeModule = 'payment';
$activePage = 'admin/payment-users';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Admin Portal', 'url' => null],
    ['label' => 'Cashier Accounts', 'url' => null],
];

$pdo = db();
$message = '';
$messageType = '';

// Handle form submission for Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $userId = $_POST['user_id'] ?? null;
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roleKey = $_POST['role_key'] ?? 'cashier';
    $password = $_POST['password'] ?? '';

    // Only allow cashier and finance roles to be managed here
    if (!in_array($roleKey, ['cashier', 'finance'])) {
        $roleKey = 'cashier'; 
    }

    try {
        if ($action === 'add') {
            if (empty($fullName) || empty($username) || empty($email) || empty($password)) {
                throw new Exception("All fields are required for new accounts.");
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, role_key, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->execute([$fullName, $username, $email, $hash, $roleKey]);
            $message = "User '$fullName' added successfully.";
            $messageType = 'success';
        } elseif ($action === 'edit' && $userId) {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, role_key = ?, password_hash = ? WHERE id = ? AND role_key IN ('cashier', 'finance')");
                $stmt->execute([$fullName, $username, $email, $roleKey, $hash, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, role_key = ? WHERE id = ? AND role_key IN ('cashier', 'finance')");
                $stmt->execute([$fullName, $username, $email, $roleKey, $userId]);
            }
            $message = "User '$fullName' updated successfully.";
            $messageType = 'success';
        } elseif ($action === 'delete' && $userId) {
            // Soft delete or hard delete? User management uses status='inactive'
            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role_key IN ('cashier', 'finance')");
            $stmt->execute([$userId]);
            $message = "User deleted (archived).";
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Fetch users
$stmt = $pdo->query("SELECT id, full_name, username, email, role_key, status, last_seen_at FROM users WHERE role_key IN ('finance', 'cashier') AND status != 'inactive' ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 mb-1 text-gray-800">Cashier & Finance Accounts</h2>
        <p class="text-muted mb-0">Manage access to the Payment Management module.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddModal()">
        <i class="fas fa-plus me-2"></i> Add Account
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-secondary">
                    <tr>
                        <th class="ps-4">Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Seen</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No accounts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td class="ps-4 fw-medium text-dark"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($u['username']) ?></span></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <?php if ($u['role_key'] === 'finance'): ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle">Finance Admin</span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle">Cashier</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['status'] === 'active'): ?>
                                        <span class="text-success"><i class="fas fa-circle fa-xs me-1"></i> Active</span>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-circle fa-xs me-1"></i> <?= ucfirst($u['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small">
                                    <?= $u['last_seen_at'] ? date('M j, Y h:i A', strtotime($u['last_seen_at'])) : 'Never' ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-light text-primary me-1" onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this account?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light text-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header bg-light border-0">
                    <h5 class="modal-title" id="modalTitle">Add Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="user_id" id="formUserId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">FULL NAME</label>
                        <input type="text" class="form-control" name="full_name" id="formFullName" required placeholder="Juan Dela Cruz">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small fw-bold">USERNAME</label>
                            <input type="text" class="form-control" name="username" id="formUsername" required placeholder="jdelacruz">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small fw-bold">ROLE</label>
                            <select class="form-select" name="role_key" id="formRoleKey" required>
                                <option value="cashier">Cashier</option>
                                <option value="finance">Finance Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">EMAIL ADDRESS</label>
                        <input type="email" class="form-control" name="email" id="formEmail" required placeholder="juan@example.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">PASSWORD</label>
                        <input type="password" class="form-control" name="password" id="formPassword" placeholder="Enter password">
                        <div class="form-text text-muted small" id="passwordHelp">Required for new accounts. Leave blank to keep current password when editing.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Add Account';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formUserId').value = '';
    document.getElementById('formFullName').value = '';
    document.getElementById('formUsername').value = '';
    document.getElementById('formEmail').value = '';
    document.getElementById('formRoleKey').value = 'cashier';
    document.getElementById('formPassword').required = true;
    document.getElementById('passwordHelp').style.display = 'block';
}

function openEditModal(user) {
    document.getElementById('modalTitle').innerText = 'Edit Account';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formUserId').value = user.id;
    document.getElementById('formFullName').value = user.full_name;
    document.getElementById('formUsername').value = user.username;
    document.getElementById('formEmail').value = user.email;
    document.getElementById('formRoleKey').value = user.role_key;
    document.getElementById('formPassword').required = false;
    document.getElementById('formPassword').value = '';
    document.getElementById('passwordHelp').style.display = 'block';
    
    var modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
}
</script>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
