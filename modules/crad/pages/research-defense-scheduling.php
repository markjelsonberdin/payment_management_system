<?php
/**
 * SMS 2 - Research Defense Scheduling
 * Module: CRAD
 */
require_once __DIR__ . '/../../../config/config.php';

$pageTitle    = 'Research Defense Scheduling';
$activeModule = 'crad';
$activePage   = 'research-defense-scheduling';
$breadcrumbs  = [
    ['label' => 'CRAD', 'url' => BASE_URL . '/modules/crad/index.php'],
    ['label' => 'Research Defense Scheduling', 'url' => null],
];

$cradProcess = [
    'kicker' => 'CRAD Officer · Defense Workflow',
    'description' => 'Schedule research defense hearings, assign panel members, manage room availability, and track defense results.',
    'metrics' => [
        ['label' => 'Scheduled Defenses', 'value' => '5', 'icon' => 'fa-calendar-check', 'tone' => 'blue'],
        ['label' => 'Today', 'value' => '2', 'icon' => 'fa-clock', 'tone' => 'amber'],
        ['label' => 'Completed', 'value' => '2', 'icon' => 'fa-check-circle', 'tone' => 'green'],
        ['label' => 'Postponed', 'value' => '1', 'icon' => 'fa-calendar-times', 'tone' => 'purple'],
    ],
    'steps' => [
        ['Schedule Defense Date', 'Select proposal or final defense date and time slot with the research group and panel.'],
        ['Assign Panel & Venue', 'Confirm panel members and reserve an available room or venue for the defense.'],
        ['Send Notifications', 'Notify the research group, adviser, and panel of the confirmed schedule.'],
        ['Record Defense Result', 'Capture passed, revise, or failed outcome and update research progression.'],
    ],
    'columns' => ['Reference', 'Research Title / Group', 'Panel Chair', 'Status', 'Updated'],
    'fields' => ['reference', 'title', 'owner', 'status', 'updated'],
    'records' => [
        [
            'reference' => 'DEF-2026-051',
            'title' => 'IoT Flood Monitoring Final Defense',
            'owner' => 'Dr. Roberto M. Santos',
            'detail' => 'CRAD Hall A',
            'status' => 'Scheduled',
            'status_class' => 'scheduled',
            'updated' => 'Jul 25, 2026 09:00',
        ],
        [
            'reference' => 'DEF-2026-050',
            'title' => 'AI-Based Enrollment Prediction Proposal Defense',
            'owner' => 'Dr. Liza M. Torres',
            'detail' => 'CRAD Hall B',
            'status' => 'Scheduled',
            'status_class' => 'scheduled',
            'updated' => 'Jul 22, 2026 14:00',
        ],
        [
            'reference' => 'DEF-2026-049',
            'title' => 'Marketing Adaptability Final Defense',
            'owner' => 'Dr. Jose B. Tan',
            'detail' => 'Conference Room 2',
            'status' => 'Passed',
            'status_class' => 'passed',
            'updated' => 'Jul 18, 2026 10:00',
        ],
        [
            'reference' => 'DEF-2026-048',
            'title' => 'Mental Health Literacy Proposal Defense',
            'owner' => 'Dr. Ana L. Mendoza',
            'detail' => 'COE AVR',
            'status' => 'Passed',
            'status_class' => 'passed',
            'updated' => 'Jul 15, 2026 13:30',
        ],
        [
            'reference' => 'DEF-2026-047',
            'title' => 'Waste Segregation Final Defense',
            'owner' => 'Prof. Joel R. Cruz',
            'detail' => 'CRAD Hall A',
            'status' => 'Postponed',
            'status_class' => 'cancelled',
            'updated' => 'Jul 11, 2026 09:30',
        ],
    ],
    'actions' => [
        ['label' => 'New Defense Schedule', 'process' => 'new', 'icon' => 'fa-plus', 'class' => 'primary'],
        ['label' => 'Check Room Availability', 'process' => 'validate', 'icon' => 'fa-door-open', 'class' => 'ghost'],
        ['label' => 'Confirm Defense', 'process' => 'approve', 'icon' => 'fa-check', 'class' => 'ghost'],
        ['label' => 'Defense Report', 'process' => 'report', 'icon' => 'fa-file-export', 'class' => 'ghost'],
    ],
    'form' => [
        ['label' => 'Research Reference', 'type' => 'text', 'name' => 'reference', 'placeholder' => 'RES-2026-00X'],
        ['label' => 'Research Title / Group', 'type' => 'text', 'name' => 'title', 'placeholder' => 'Research group title'],
        ['label' => 'Defense Type', 'type' => 'select', 'name' => 'defense_type', 'options' => [
            'Proposal Defense',
            'Final Defense',
        ]],
        ['label' => 'Panel Chair', 'type' => 'select', 'name' => 'panel_chair', 'options' => [
            'Dr. Roberto M. Santos',
            'Dr. Liza M. Torres',
            'Dr. Jose B. Tan',
            'Dr. Ana L. Mendoza',
        ]],
        ['label' => 'Venue / Room', 'type' => 'select', 'name' => 'venue', 'options' => [
            'CRAD Hall A',
            'CRAD Hall B',
            'Conference Room 2',
            'COE AVR',
        ]],
        ['label' => 'Date & Time', 'type' => 'text', 'name' => 'datetime', 'placeholder' => 'YYYY-MM-DD HH:MM'],
        ['label' => 'Defense Remarks', 'type' => 'textarea', 'name' => 'remarks', 'placeholder' => 'Schedule notes, panel availability, follow-up...'],
    ],
    'notice' => 'Defenses may only be scheduled after adviser and panel assignment is confirmed. Room changes must be announced to all parties at least 24 hours in advance.',
];

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php require_once ROOT_PATH . '/includes/crad-module-process.php'; ?>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>