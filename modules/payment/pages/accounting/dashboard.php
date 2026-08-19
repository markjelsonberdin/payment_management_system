<?php
/**
 * Finance Dashboard
 * Live metrics and charts using data from payment_db
 */
if (!isset($MODULES)) {
    require_once __DIR__ . '/../../../../config/config.php';
}

$pageTitle = 'Finance Dashboard';
$activeModule = 'payment';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Dashboard', 'url' => null]
];

$roleKey = getCurrentUserRoleKey();

require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 mb-1 text-gray-800">Finance Dashboard</h2>
        <p class="text-muted mb-0">Real-time payment analytics and collection tracking.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-light shadow-sm" onclick="fetchDashboardData()">
            <i class="fas fa-sync-alt me-2 text-primary"></i> Refresh
        </button>
    </div>
</div>

<!-- KPI Cards Row -->
<div class="row g-4 mb-4">
    <!-- Collections Today -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-success">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Collections Today</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="kpi-collections-today">₱0.00</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-peso-sign fa-2x text-gray-300 opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Collected This Month -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-primary">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Collected This Month</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="kpi-collected-month">₱0.00</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-wallet fa-2x text-gray-300 opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Payments -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-warning">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Payments</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="kpi-pending-payments">0</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-clock fa-2x text-gray-300 opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Outstanding / Overdue -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 py-2 border-start border-4 border-danger">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Outstanding Balance</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="kpi-outstanding-balance">₱0.00</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-file-invoice-dollar fa-2x text-gray-300 opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Analytics Row -->
<div class="row mb-4">
    <div class="col-lg-4 mb-4 mb-lg-0">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h6 class="m-0 font-weight-bold text-dark">Collection by Category</h6>
            </div>
            <div class="card-body">
                <div class="chart-pie pt-4 pb-2" style="position: relative; height: 300px; width: 100%;">
                    <canvas id="categoryChart"></canvas>
                </div>
                <div class="mt-4 text-center small" id="categoryLegend">
                    <!-- Legend injected via JS -->
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h6 class="m-0 font-weight-bold text-dark">Collection Trend (Last 7 Days)</h6>
            </div>
            <div class="card-body">
                <div class="chart-area" style="position: relative; height: 320px; width: 100%;">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Analytics Row -->
<div class="row mb-4">
    <div class="col-lg-6 mb-4 mb-lg-0">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h6 class="m-0 font-weight-bold text-dark">Payment Status</h6>
            </div>
            <div class="card-body">
                <div class="chart-bar" style="position: relative; height: 250px; width: 100%;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h6 class="m-0 font-weight-bold text-dark">Payment Channel</h6>
            </div>
            <div class="card-body">
                <div class="chart-pie pt-4 pb-2" style="position: relative; height: 250px; width: 100%;">
                    <canvas id="channelChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-3">
                <h6 class="m-0 font-weight-bold text-dark">Recent Payment Activity</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th class="ps-4">Activity</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody id="recentActivityTable">
                            <tr>
                                <td colspan="2" class="text-center py-4 text-muted">Loading activity...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const API_URL = '<?= BASE_URL ?>/modules/payment/api/dashboard-data.php';
</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/finance-dashboard.js"></script>
