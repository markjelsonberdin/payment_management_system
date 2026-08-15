<?php
/**
 * SMS 2 – User Management – Overview
 * Superadmin only.
 */
$pageTitle    = 'User Management';
$activeModule = 'user-management';
$activePage   = '';
$breadcrumbs  = [
    ['label' => 'User Management', 'url' => null],
];

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../includes/layout-start.php';

/* requireSuperAdmin() is available after layout-start loads authentication.php */
requireSuperAdmin();

$stats = [
    ['label' => 'Total Users',    'value' => '10',  'icon' => 'fa-users',       'type' => 'primary'],
    ['label' => 'Active',         'value' => '8',   'icon' => 'fa-user-check',  'type' => 'success'],
    ['label' => 'Inactive',       'value' => '1',   'icon' => 'fa-user-slash',  'type' => 'warning'],
    ['label' => 'Locked Out',     'value' => '1',   'icon' => 'fa-user-lock',   'type' => 'info'],
];

$subpages = [
    [
        'slug'  => 'user-accounts',
        'title' => 'User Accounts',
        'icon'  => 'fa-user-cog',
        'desc'  => 'Create and manage accounts. Use the User Archive button on that page for archived users.',
    ],
    [
        'slug'  => 'role-permissions',
        'title' => 'Role & Permissions',
        'icon'  => 'fa-shield-alt',
        'desc'  => 'View the role–module permission matrix and configure access per role.',
    ],
    [
        'slug'  => 'module-security',
        'title' => 'Module Security',
        'icon'  => 'fa-lock',
        'desc'  => 'Open a module (e.g. CRAD), then use Activity Logs or Password Management (requests + reset).',
    ],
    [
        'slug'  => 'activity-logs',
        'title' => 'Activity Logs',
        'icon'  => 'fa-history',
        'desc'  => 'Audit trail of all user logins, actions, and system events.',
    ],
    [
        'slug'  => 'system-settings',
        'title' => 'System Settings',
        'icon'  => 'fa-sliders-h',
        'desc'  => 'Configure application name, school year, session rules, and global toggles.',
    ],
];
?>

<link href="<?= BASE_URL ?>/modules/user-management/assets/css/user-management.css" rel="stylesheet">

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1><i class="fas fa-users-cog text-sms-primary me-2"></i>User Management</h1>
        <p>Manage system users, roles, access permissions, activity logs, and global settings.</p>
    </div>
    <span class="placeholder-badge"><i class="fas fa-lock me-1"></i>Superadmin Only</span>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4 dashboard-stats">
    <?php foreach ($stats as $stat): ?>
        <div class="col-6 col-xl-3">
            <section class="card stat-card <?= $stat['type'] ?>">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon me-3"><i class="fas <?= $stat['icon'] ?>"></i></div>
                    <div>
                        <h6 class="text-muted mb-0 small"><?= $stat['label'] ?></h6>
                        <h4 class="mb-0 fw-bold"><?= $stat['value'] ?></h4>
                    </div>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<!-- Submodule cards -->
<div class="row g-3 module-button-grid mb-4">
    <?php foreach ($subpages as $page): ?>
        <?php
        $pageHref = BASE_URL . '/modules/user-management/pages/' . $page['slug'] . '.php';
        // Module Security hub: always open the All Modules picker
        if ($page['slug'] === 'module-security') {
            $pageHref .= '?picker=1';
        }
        ?>
        <div class="col-md-6">
            <a href="<?= htmlspecialchars($pageHref) ?>"
               class="text-decoration-none d-block h-100">
                <div class="card module-card hover-card h-100">
                    <div class="card-body d-flex align-items-start gap-3 p-4">
                        <div class="card-icon flex-shrink-0">
                            <i class="fas <?= $page['icon'] ?>" aria-hidden="true"></i>
                        </div>
                        <div class="min-w-0">
                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($page['title']) ?></h6>
                            <small class="text-muted" style="font-size:.78rem;line-height:1.4;">
                                <?= htmlspecialchars($page['desc']) ?>
                            </small>
                        </div>
                        <i class="fas fa-chevron-right text-sms-primary ms-auto mt-1 flex-shrink-0" style="font-size:.7rem;opacity:.6;"></i>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<!-- Quick-access recent users -->
<section class="card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="card-title fw-semibold mb-0">
                <i class="fas fa-users text-sms-primary me-2"></i>System Users
            </h5>
            <a href="<?= BASE_URL ?>/modules/user-management/pages/user-accounts.php"
               class="btn btn-sm btn-outline-primary">
                <i class="fas fa-arrow-right me-1"></i>Manage All
            </a>
        </div>
        <div class="table-responsive">
            <table class="table submodule-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $previewUsers = [
                        ['name'=>'Super Admin',       'email'=>'superadmin@bestlink.edu.ph',     'role'=>'admin',     'roleLabel'=>'Super Admin',       'status'=>'active',   'last'=>'Just now'],
                        ['name'=>'Registrar',         'email'=>'registrar@bestlink.edu.ph',      'role'=>'registrar', 'roleLabel'=>'Registrar',         'status'=>'active',   'last'=>'5 min ago'],
                        ['name'=>'Finance',           'email'=>'finance@bestlink.edu.ph',        'role'=>'finance',   'roleLabel'=>'Finance',           'status'=>'active',   'last'=>'1 hr ago'],
                        ['name'=>'HR',                'email'=>'hr@bestlink.edu.ph',             'role'=>'hr',        'roleLabel'=>'HR',                'status'=>'active',   'last'=>'2 hr ago'],
                        ['name'=>'IT Officer',        'email'=>'itofficer@bestlink.edu.ph',      'role'=>'it_office', 'roleLabel'=>'IT Office',         'status'=>'active',   'last'=>'Yesterday'],
                    ];
                    $colors = ['a','b','c','d','e','f'];
                    foreach ($previewUsers as $i => $u):
                        $col = $colors[$i % count($colors)];
                    ?>
                    <tr>
                        <td>
                            <div class="um-user-cell">
                                <span class="um-avatar <?= $col ?>"><?= strtoupper($u['name'][0]) ?></span>
                                <div class="min-w-0">
                                    <span class="um-user-name"><?= htmlspecialchars($u['name']) ?></span>
                                    <span class="um-user-email"><?= htmlspecialchars($u['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><span class="role-badge <?= $u['role'] ?>"><?= htmlspecialchars($u['roleLabel']) ?></span></td>
                        <td><span class="user-status <?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span></td>
                        <td class="text-muted" style="font-size:.8rem;"><?= htmlspecialchars($u['last']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
