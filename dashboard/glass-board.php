<?php
/**
 * SMS 2 – Glass analytics board (staff dashboard)
 * Expects: $roleKey, $statCards, $visibleModules
 */

$perfColors = ['blue', 'purple', 'green', 'orange'];
$sourceLegend = [
    ['label' => 'BSIT', 'pct' => '21%', 'color' => '#3b82f6'],
    ['label' => 'BSBA', 'pct' => '19%', 'color' => '#8b5cf6'],
    ['label' => 'BSEd', 'pct' => '17%', 'color' => '#22c55e'],
    ['label' => 'BSN', 'pct' => '14%', 'color' => '#f59e0b'],
    ['label' => 'Others', 'pct' => '29%', 'color' => '#06b6d4'],
];
$donutCenterValue = '2,893';
$donutCenterLabel = 'Students';
$trendBig = '₱98,750';
$trendDelta = '+8.2%';
$trendTitle = 'Collections overview';
$trendSub = 'Month-to-date revenue trend';
$sourceTitle = 'Students by program';
$sourceSub = 'Distribution across programs';
$inflow = '₱98,750';
$outflow = '₱63,250';
$netFlow = '+35.6%';

$tableRows = [
    ['name' => 'Ana Reyes', 'initial' => 'A', 'role' => 'Registrar', 'status' => 'active', 'statusLabel' => 'Active', 'when' => '2 min ago'],
    ['name' => 'Carlo Lim', 'initial' => 'C', 'role' => 'Faculty', 'status' => 'active', 'statusLabel' => 'Active', 'when' => '12 min ago'],
    ['name' => 'Mia Santos', 'initial' => 'M', 'role' => 'Finance', 'status' => 'away', 'statusLabel' => 'Away', 'when' => '28 min ago'],
    ['name' => 'Jon Cruz', 'initial' => 'J', 'role' => 'IT Office', 'status' => 'offline', 'statusLabel' => 'Offline', 'when' => '1 hr ago'],
    ['name' => 'Liza Ong', 'initial' => 'L', 'role' => 'OSA', 'status' => 'active', 'statusLabel' => 'Active', 'when' => '2 hr ago'],
];

$progressItems = [
    ['label' => 'Enrollment completion', 'pct' => 72, 'tone' => 'blue'],
    ['label' => 'Document processing', 'pct' => 58, 'tone' => 'green'],
    ['label' => 'Payment collection', 'pct' => 84, 'tone' => 'orange'],
    ['label' => 'Schedule conflicts cleared', 'pct' => 41, 'tone' => 'red'],
    ['label' => 'LMS module progress', 'pct' => 89, 'tone' => 'gradient'],
];

$activities = [
    ['icon' => 'fa-user-plus', 'tone' => 'blue', 'text' => 'New student enrolled in BSIT', 'when' => '2 min ago'],
    ['icon' => 'fa-file-alt', 'tone' => 'orange', 'text' => 'Transcript request submitted', 'when' => '9 min ago'],
    ['icon' => 'fa-peso-sign', 'tone' => 'green', 'text' => 'Tuition payment recorded', 'when' => '18 min ago'],
    ['icon' => 'fa-chalkboard-teacher', 'tone' => 'purple', 'text' => 'Faculty schedule updated', 'when' => '34 min ago'],
    ['icon' => 'fa-exclamation-circle', 'tone' => 'red', 'text' => 'Overdue account flagged', 'when' => '1 hr ago'],
];

$badges = [
    ['icon' => 'fa-star', 'class' => 'b1', 'label' => 'New', 'state' => 'Unlocked'],
    ['icon' => 'fa-shield-alt', 'class' => 'b2', 'label' => 'Active', 'state' => 'Unlocked'],
    ['icon' => 'fa-fire', 'class' => 'b3', 'label' => 'Popular', 'state' => 'Unlocked'],
    ['icon' => 'fa-crown', 'class' => 'b4', 'label' => 'Premium', 'state' => 'Unlocked'],
    ['icon' => 'fa-user-shield', 'class' => 'b5', 'label' => 'Admin', 'state' => 'Unlocked'],
];

$tableTitle = 'Active staff';
$tableSub = 'Recent presence across offices';
$progressTitle = 'Progress overview';
$progressSub = 'Operational completion rates';
$pipelineTitle = 'Cash flow';
$pipelineSub = 'Inflow vs outflow this month';
$pipelineInLabel = 'Total inflow';
$pipelineOutLabel = 'Total outflow';
$pipelineGaugeLabel = 'Net flow';
$activityTitle = 'Recent activity';
$activitySub = 'Latest system events';

if ($roleKey === 'finance') {
    $sourceTitle = 'Collections by fee type';
    $sourceSub = 'Share of month-to-date intake';
    $donutCenterValue = '₱1.2M';
    $donutCenterLabel = 'Collected';
    $sourceLegend = [
        ['label' => 'Tuition', 'pct' => '68%', 'color' => '#3b82f6'],
        ['label' => 'Misc', 'pct' => '12%', 'color' => '#8b5cf6'],
        ['label' => 'Lab', 'pct' => '8%', 'color' => '#22c55e'],
        ['label' => 'Registration', 'pct' => '7%', 'color' => '#f59e0b'],
        ['label' => 'Others', 'pct' => '5%', 'color' => '#06b6d4'],
    ];
} elseif ($roleKey === 'hr') {
    $sourceTitle = 'Faculty by department';
    $sourceSub = 'Headcount distribution';
    $donutCenterValue = '102';
    $donutCenterLabel = 'Faculty';
    $trendTitle = 'Leave applications';
    $trendSub = 'Monthly leave trend';
    $trendBig = '48';
    $trendDelta = '+6.1%';
} elseif ($roleKey === 'it_office') {
    $sourceTitle = 'LMS completion mix';
    $sourceSub = 'Module completion by subject group';
    $donutCenterValue = '68%';
    $donutCenterLabel = 'Avg complete';
    $trendTitle = 'LMS activity';
    $trendSub = 'Logins over the term';
    $trendBig = '2,104';
    $trendDelta = '+7.3%';
} elseif ($roleKey === 'crad_officer') {
    $sourceTitle = 'Proposals by college';
    $sourceSub = 'Title submissions across departments';
    $donutCenterValue = '24';
    $donutCenterLabel = 'Proposals';
    $sourceLegend = [
        ['label' => 'CCS', 'pct' => '32%', 'color' => '#3b82f6'],
        ['label' => 'CBA', 'pct' => '22%', 'color' => '#8b5cf6'],
        ['label' => 'Education', 'pct' => '18%', 'color' => '#22c55e'],
        ['label' => 'Criminology', 'pct' => '14%', 'color' => '#f59e0b'],
        ['label' => 'Others', 'pct' => '14%', 'color' => '#06b6d4'],
    ];
    $trendTitle = 'Submission trend';
    $trendSub = 'Title proposals this month';
    $trendBig = '24';
    $trendDelta = '+12.5%';
    $tableTitle = 'Active advisers';
    $tableSub = 'Research adviser assignments';
    $tableRows = [
        ['name' => 'Dr. Roberto Santos', 'initial' => 'R', 'role' => 'Research Adviser', 'status' => 'active', 'statusLabel' => 'Active', 'when' => '5 min ago'],
        ['name' => 'Prof. Clara Reyes', 'initial' => 'C', 'role' => 'Program Coordinator', 'status' => 'active', 'statusLabel' => 'Active', 'when' => '18 min ago'],
        ['name' => 'Dr. Ana Mendoza', 'initial' => 'A', 'role' => 'Research Adviser', 'status' => 'away', 'statusLabel' => 'Away', 'when' => '40 min ago'],
        ['name' => 'Prof. Joel Cruz', 'initial' => 'J', 'role' => 'Research Adviser', 'status' => 'active', 'statusLabel' => 'Active', 'when' => '1 hr ago'],
        ['name' => 'CRAD Desk', 'initial' => 'C', 'role' => 'CRAD Officer', 'status' => 'active', 'statusLabel' => 'Active', 'when' => 'Just now'],
    ];
    $progressTitle = 'Module progress';
    $progressSub = 'CRAD process completion rates';
    $progressItems = [
        ['label' => 'Proposal screening', 'pct' => 78, 'tone' => 'blue'],
        ['label' => 'Adviser assignment', 'pct' => 65, 'tone' => 'green'],
        ['label' => 'Grants evaluation', 'pct' => 52, 'tone' => 'orange'],
        ['label' => 'Publication endorsement', 'pct' => 41, 'tone' => 'red'],
        ['label' => 'Repository cataloguing', 'pct' => 88, 'tone' => 'gradient'],
    ];
    $pipelineTitle = 'Approval pipeline';
    $pipelineSub = 'Approved vs for-revision this month';
    $pipelineInLabel = 'Approved titles';
    $pipelineOutLabel = 'For revision';
    $inflow = '11';
    $outflow = '5';
    $netFlow = '68.8%';
    $pipelineGaugeLabel = 'Approval rate';
    $activityTitle = 'Recent CRAD activity';
    $activitySub = 'Latest research office events';
    $activities = [
        ['icon' => 'fa-file-alt', 'tone' => 'blue', 'text' => 'New title proposal submitted from CCS', 'when' => '4 min ago'],
        ['icon' => 'fa-user-tie', 'tone' => 'purple', 'text' => 'Adviser assigned to SUB-2026-004', 'when' => '22 min ago'],
        ['icon' => 'fa-check-circle', 'tone' => 'green', 'text' => 'Title approved: Flood Monitoring Study', 'when' => '1 hr ago'],
        ['icon' => 'fa-hand-holding-usd', 'tone' => 'orange', 'text' => 'Grant application under evaluation', 'when' => '2 hr ago'],
        ['icon' => 'fa-archive', 'tone' => 'blue', 'text' => 'Study archived in research repository', 'when' => '3 hr ago'],
    ];
    $badges = [
        ['icon' => 'fa-clipboard-list', 'class' => 'b1', 'label' => 'Tracking', 'state' => 'Active'],
        ['icon' => 'fa-user-tie', 'class' => 'b2', 'label' => 'Advisers', 'state' => 'Active'],
        ['icon' => 'fa-hand-holding-usd', 'class' => 'b3', 'label' => 'Grants', 'state' => 'Active'],
        ['icon' => 'fa-book', 'class' => 'b4', 'label' => 'Publish', 'state' => 'Active'],
        ['icon' => 'fa-archive', 'class' => 'b5', 'label' => 'Repository', 'state' => 'Active'],
    ];
}
?>

<div class="dashboard-shell glass-dashboard">
    <div class="page-header dashboard-page-header">
        <div>
            <span class="dash-kicker">Analytics</span>
            <h1>Dashboard</h1>
            <p>Welcome back, <?= htmlspecialchars(getCurrentUserName()) ?>. <?= $roleKey === 'crad_officer' ? 'Live CRAD research performance board.' : 'Live institutional performance board.' ?></p>
        </div>
        <div class="dash-period">
            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
            <span>SY 2025–2026 · This month</span>
        </div>
    </div>

    <div class="glass-board" id="glassBoard" data-role="<?= htmlspecialchars($roleKey) ?>">

        <!-- TOP: Performance + Source + Trend -->
        <div class="glass-row glass-row-top">
            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title">Performance overview</h2>
                            <p class="glass-panel-sub">Key metrics for your workspace</p>
                        </div>
                        <span class="glass-chip"><i class="fas fa-filter" aria-hidden="true"></i> This month</span>
                    </div>
                    <div class="perf-grid">
                        <?php foreach ($statCards as $i => $card): ?>
                            <?php
                            $tone = $perfColors[$i % count($perfColors)];
                            $dir = $card['deltaDir'] ?? 'neutral';
                            $arrow = $dir === 'down' ? 'fa-arrow-down' : ($dir === 'up' ? 'fa-arrow-up' : 'fa-minus');
                            ?>
                            <article class="perf-item">
                                <div class="perf-icon <?= $tone ?>"><i class="fas <?= htmlspecialchars($card['icon']) ?>" aria-hidden="true"></i></div>
                                <p class="perf-label"><?= htmlspecialchars($card['label']) ?></p>
                                <p class="perf-value"><?= htmlspecialchars($card['value']) ?></p>
                                <p class="perf-trend <?= htmlspecialchars($dir) ?>">
                                    <i class="fas <?= $arrow ?>" aria-hidden="true"></i>
                                    <?= htmlspecialchars($card['delta'] ?? '') ?>
                                    <span style="font-weight:500;opacity:.8"><?= htmlspecialchars($card['deltaLabel'] ?? '') ?></span>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title"><?= htmlspecialchars($sourceTitle) ?></h2>
                            <p class="glass-panel-sub"><?= htmlspecialchars($sourceSub) ?></p>
                        </div>
                    </div>
                    <div class="donut-layout">
                        <div class="donut-wrap">
                            <canvas id="glassDonut" aria-label="Distribution chart"></canvas>
                            <div class="donut-center">
                                <strong><?= htmlspecialchars($donutCenterValue) ?></strong>
                                <span><?= htmlspecialchars($donutCenterLabel) ?></span>
                            </div>
                        </div>
                        <ul class="glass-legend">
                            <?php foreach ($sourceLegend as $item): ?>
                                <li>
                                    <span class="leg-left">
                                        <span class="dot" style="background:<?= htmlspecialchars($item['color']) ?>"></span>
                                        <span class="name"><?= htmlspecialchars($item['label']) ?></span>
                                    </span>
                                    <span class="pct"><?= htmlspecialchars($item['pct']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title"><?= htmlspecialchars($trendTitle) ?></h2>
                            <p class="glass-panel-sub"><?= htmlspecialchars($trendSub) ?></p>
                        </div>
                    </div>
                    <div class="glass-metric-row">
                        <span class="big"><?= htmlspecialchars($trendBig) ?></span>
                        <span class="up"><i class="fas fa-arrow-up" aria-hidden="true"></i> <?= htmlspecialchars($trendDelta) ?></span>
                    </div>
                    <div class="glass-chart-stage">
                        <canvas id="glassTrend" aria-label="Trend chart"></canvas>
                    </div>
                </div>
            </section>
        </div>

        <!-- MID: Table + Progress + Cash flow -->
        <div class="glass-row glass-row-mid">
            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title"><?= htmlspecialchars($tableTitle) ?></h2>
                            <p class="glass-panel-sub"><?= htmlspecialchars($tableSub) ?></p>
                        </div>
                        <a href="#" class="glass-chip">View all</a>
                    </div>
                    <div class="table-responsive">
                        <table class="glass-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last active</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableRows as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="glass-user">
                                                <span class="glass-avatar"><?= htmlspecialchars($row['initial']) ?></span>
                                                <strong><?= htmlspecialchars($row['name']) ?></strong>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($row['role']) ?></td>
                                        <td><span class="glass-status <?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['statusLabel']) ?></span></td>
                                        <td><?= htmlspecialchars($row['when']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title"><?= htmlspecialchars($progressTitle) ?></h2>
                            <p class="glass-panel-sub"><?= htmlspecialchars($progressSub) ?></p>
                        </div>
                    </div>
                    <div class="glass-progress-list">
                        <?php foreach ($progressItems as $item): ?>
                            <div class="glass-progress-row">
                                <div class="glass-progress-meta">
                                    <span><?= htmlspecialchars($item['label']) ?></span>
                                    <strong><?= (int) $item['pct'] ?>%</strong>
                                </div>
                                <div class="glass-bar <?= htmlspecialchars($item['tone']) ?>" style="--pct: <?= (int) $item['pct'] ?>%"><i></i></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title"><?= htmlspecialchars($pipelineTitle) ?></h2>
                            <p class="glass-panel-sub"><?= htmlspecialchars($pipelineSub) ?></p>
                        </div>
                    </div>
                    <div class="cash-layout">
                        <div>
                            <div class="cash-stats">
                                <div class="in"><span><?= htmlspecialchars($pipelineInLabel) ?></span><strong><?= htmlspecialchars($inflow) ?></strong></div>
                                <div class="out"><span><?= htmlspecialchars($pipelineOutLabel) ?></span><strong><?= htmlspecialchars($outflow) ?></strong></div>
                            </div>
                            <div class="glass-chart-stage" style="min-height:150px;height:150px;">
                                <canvas id="glassCash" aria-label="Pipeline chart"></canvas>
                            </div>
                        </div>
                        <div class="cash-gauge">
                            <canvas id="glassNet" aria-label="Approval rate gauge"></canvas>
                            <div class="cash-gauge-center">
                                <strong><?= htmlspecialchars($netFlow) ?></strong>
                                <span><?= htmlspecialchars($pipelineGaugeLabel) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- BOT: Activity + Badges -->
        <div class="glass-row glass-row-bot">
            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title"><?= htmlspecialchars($activityTitle) ?></h2>
                            <p class="glass-panel-sub"><?= htmlspecialchars($activitySub) ?></p>
                        </div>
                        <a href="#" class="glass-chip">View all</a>
                    </div>
                    <ul class="glass-activity">
                        <?php foreach ($activities as $act): ?>
                            <li>
                                <span class="act-icon <?= htmlspecialchars($act['tone']) ?>"><i class="fas <?= htmlspecialchars($act['icon']) ?>" aria-hidden="true"></i></span>
                                <div class="act-body"><strong><?= htmlspecialchars($act['text']) ?></strong></div>
                                <span class="act-time"><?= htmlspecialchars($act['when']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <section class="glass-panel">
                <div class="glass-panel-body">
                    <div class="glass-panel-head">
                        <div>
                            <h2 class="glass-panel-title">Badges &amp; achievements</h2>
                            <p class="glass-panel-sub">Unlocked milestones</p>
                        </div>
                    </div>
                    <div class="glass-badges">
                        <?php foreach ($badges as $b): ?>
                            <div class="glass-badge">
                                <div class="ico <?= htmlspecialchars($b['class']) ?>"><i class="fas <?= htmlspecialchars($b['icon']) ?>" aria-hidden="true"></i></div>
                                <strong><?= htmlspecialchars($b['label']) ?></strong>
                                <span><?= htmlspecialchars($b['state']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- Modules -->
        <section class="glass-panel glass-modules">
            <div class="glass-panel-body">
                <div class="glass-panel-head">
                    <div>
                        <h2 class="glass-panel-title">System modules</h2>
                        <p class="glass-panel-sub">Jump into a workspace</p>
                    </div>
                </div>
                <div class="row g-3 module-grid">
                    <?php foreach ($visibleModules as $moduleKey => $module): ?>
                        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                            <a href="<?= BASE_URL ?>/modules/<?= $moduleKey ?>/index.php" class="quick-module">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <i class="fas <?= htmlspecialchars($module['icon']) ?>" aria-hidden="true"></i>
                                        <p class="small mb-0 fw-medium"><?= htmlspecialchars($module['label']) ?></p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</div>
