<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.online_payment_config');
$pageTitle = 'Payment Admin Dashboard';
$activeModule = 'payment';
$activePage = 'payment_admin/dashboard';
$breadcrumbs = [['label'=>'Payment Management','url'=>BASE_URL . '/modules/payment/index.php'],['label'=>'Payment Admin Dashboard','url'=>null]];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h2 class="fw-bolder mb-1">Payment Admin Dashboard</h2><p class="text-muted mb-0">Manage payment gateway readiness, online-payment configuration, and the Cashier school-sales catalog.</p></div>
    <div class="d-flex gap-2"><a href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/online-payment-integration.php" class="btn btn-primary"><i class="fas fa-sliders-h me-2"></i>Configure Online Payments</a><a href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/school-sales-catalog.php" class="btn btn-outline-primary"><i class="fas fa-tags me-2"></i>School Sales Catalog</a></div>
  </div>
  <div id="dashboardAlert" class="alert alert-warning d-none" role="alert"></div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="text-muted small text-uppercase fw-semibold">Gateway environment</div><div id="gatewayMode" class="fs-4 fw-bold mt-2">Loading...</div><small class="text-muted">Current PayMongo configuration</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="text-muted small text-uppercase fw-semibold">Enabled channels</div><div id="enabledChannels" class="fs-4 fw-bold mt-2">-</div><small class="text-muted">Configured receiving channels</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="text-muted small text-uppercase fw-semibold">Pending online payments</div><div id="pendingOnline" class="fs-4 fw-bold mt-2">-</div><small class="text-muted">Awaiting payment status update</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="text-muted small text-uppercase fw-semibold">Live payments this month</div><div id="verifiedMonth" class="fs-4 fw-bold mt-2">-</div><small class="text-muted">Verified live online payments</small></div></div></div>
  </div>
  <div class="row g-4">
    <div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="mb-1">Receiving Channel Status</h5><p class="text-muted small mb-0">Only configured channels are shown. Sender banks/e-wallets are not treated as receiving gateways.</p></div><a class="small" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/online-payment-integration.php">Open configuration</a></div><div id="channelList" class="row g-3"><div class="col-12 text-muted">Loading channel status...</div></div></div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">School Sales Catalog</h5><p class="text-muted small">Catalog entries are used only by Cashier for direct school-goods sales. They do not affect academic billing or balance.</p><div class="mt-4"><div class="d-flex justify-content-between border-bottom py-2"><span>Active categories</span><strong id="saleCategories">-</strong></div><div class="d-flex justify-content-between py-2"><span>Active items</span><strong id="saleItems">-</strong></div></div><a class="btn btn-outline-primary w-100 mt-3" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/school-sales-catalog.php">Maintain Catalog</a></div></div></div>
  </div>
</div>
<script>
(() => {
  const api = '<?= BASE_URL ?>/modules/payment/api/payment-admin-dashboard-data.php';
  const set = (id, value) => document.getElementById(id).textContent = value;
  fetch(api, {credentials:'same-origin'}).then(r=>r.json()).then(data => {
    if (!data.ok) throw new Error(data.message || 'Unable to load dashboard data.');
    set('gatewayMode', data.gateway_mode === 'live' ? 'Live' : 'Test mode');
    set('enabledChannels', data.channels.filter(c=>c.enabled).length + ' of ' + data.channels.length);
    set('pendingOnline', data.pending_online.toLocaleString()); set('verifiedMonth', data.verified_month.toLocaleString());
    set('saleCategories', data.active_sale_categories.toLocaleString()); set('saleItems', data.active_sale_items.toLocaleString());
    document.getElementById('channelList').innerHTML = data.channels.map(c => '<div class="col-sm-6"><div class="border rounded p-3 d-flex justify-content-between align-items-center"><span class="fw-semibold">' + c.name + '</span><span class="badge ' + (c.enabled?'bg-success':'bg-secondary') + '">' + (c.enabled?'Enabled':'Disabled') + '</span></div></div>').join('');
  }).catch(error => {
    const alert=document.getElementById('dashboardAlert'); alert.textContent=error.message; alert.classList.remove('d-none');
    ['gatewayMode','enabledChannels','pendingOnline','verifiedMonth','saleCategories','saleItems'].forEach(id=>set(id,'-'));
    document.getElementById('channelList').innerHTML='<div class="col-12 text-muted">No configuration data is available yet.</div>';
  });
})();
</script>
