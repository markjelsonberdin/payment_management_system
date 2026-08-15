<?php
/**
 * SMS 2 - Adviser & Panel Assignment
 * Module: CRAD
 */
require_once __DIR__ . '/../../../config/config.php';

$pageTitle    = 'Adviser & Panel Assignment';
$activeModule = 'crad';
$activePage   = 'adviser-panel-assignment';
$breadcrumbs  = [
    ['label' => 'CRAD', 'url' => BASE_URL . '/modules/crad/index.php'],
    ['label' => 'Adviser & Panel Assignment', 'url' => null],
];

$cradProcess = [
    'kicker' => 'CRAD Officer · Adviser & Panel Workflow',
    'description' => 'Match approved research groups with qualified faculty advisers and defense panel members based on expertise, load, and college alignment.',
    'metrics' => [
        ['label' => 'Pending Assignment', 'value' => '6', 'icon' => 'fa-user-clock', 'tone' => 'amber'],
        ['label' => 'Assigned Groups', 'value' => '22', 'icon' => 'fa-user-check', 'tone' => 'green'],
        ['label' => 'Available Advisers', 'value' => '12', 'icon' => 'fa-chalkboard-teacher', 'tone' => 'blue'],
        ['label' => 'Available Panelists', 'value' => '18', 'icon' => 'fa-users', 'tone' => 'purple'],
    ],
    'steps' => [
        ['Receive Assignment Request', 'Open approved title groups that still need a research adviser and defense panel.'],
        ['Match Faculty Expertise', 'Filter advisers and panelists by discipline cluster, college, and research agenda fit.'],
        ['Check Advising Load', 'Validate current advisee and panel count before confirming assignment.'],
        ['Confirm & Notify Parties', 'Save adviser and panel assignment, then notify the student group and faculty.'],
    ],
    'columns' => ['Reference', 'Research Group / Title', 'Adviser / Panel', 'Status', 'Updated'],
    'fields' => ['reference', 'title', 'owner', 'status', 'updated'],
    'records' => [
        [
            'reference' => 'ADV-2026-016',
            'title' => 'AI-Based Enrollment Prediction Model',
            'owner' => 'Dr. Liza M. Torres (Adviser) · Dr. Art C. Lim (Panel)',
            'detail' => 'CCS',
            'status' => 'For Assignment',
            'status_class' => 'pending',
            'updated' => 'Jul 18, 2026',
        ],
        [
            'reference' => 'ADV-2026-015',
            'title' => 'IoT Flood Monitoring — CCS Group A',
            'owner' => 'Dr. Roberto M. Santos (Adviser) · Prof. Nina G. Cruz (Panel)',
            'detail' => 'CCS',
            'status' => 'Assigned',
            'status_class' => 'assigned',
            'updated' => 'Jul 16, 2026',
        ],
        [
            'reference' => 'ADV-2026-014',
            'title' => 'Micro-Enterprise Marketing Adaptability',
            'owner' => 'Prof. Clara T. Reyes (Adviser) · Dr. Jose B. Tan (Panel)',
            'detail' => 'CBA',
            'status' => 'Assigned',
            'status_class' => 'assigned',
            'updated' => 'Jul 14, 2026',
        ],
        [
            'reference' => 'ADV-2026-013',
            'title' => 'Community Mental Health Literacy Study',
            'owner' => 'Dr. Ana L. Mendoza (Adviser) · Prof. Rhea D. Santos (Panel)',
            'detail' => 'COE',
            'status' => 'Under Review',
            'status_class' => 'review',
            'updated' => 'Jul 12, 2026',
        ],
    ],
    'actions' => [
        ['label' => 'New Assignment Request', 'process' => 'new', 'icon' => 'fa-plus', 'class' => 'primary'],
        ['label' => 'Match Adviser & Panel', 'process' => 'validate', 'icon' => 'fa-user-plus', 'class' => 'ghost'],
        ['label' => 'Confirm Assignment', 'process' => 'approve', 'icon' => 'fa-check', 'class' => 'ghost'],
        ['label' => 'Assignment Report', 'process' => 'report', 'icon' => 'fa-file-export', 'class' => 'ghost'],
    ],
    'form' => [
        ['label' => 'Research Reference', 'type' => 'text', 'name' => 'reference', 'placeholder' => 'SUB-2026-00X'],
        ['label' => 'Student Group / Leader', 'type' => 'text', 'name' => 'group', 'placeholder' => 'Lastname, Firstname'],
        ['label' => 'College', 'type' => 'select', 'name' => 'college', 'options' => [
            'College of Computer Studies',
            'College of Business Administration',
            'College of Criminology',
            'College of Education',
            'College of Hospitality & Tourism Management',
        ]],
        ['label' => 'Recommended Adviser', 'type' => 'select', 'name' => 'adviser', 'options' => [
            'Dr. Roberto M. Santos',
            'Prof. Clara T. Reyes',
            'Dr. Ana L. Mendoza',
            'Prof. Joel R. Cruz',
            'Dr. Liza M. Torres',
        ]],
        ['label' => 'Recommended Panel Members', 'type' => 'select', 'name' => 'panel', 'options' => [
            'Dr. Jose B. Tan',
            'Prof. Nina G. Cruz',
            'Dr. Art C. Lim',
            'Prof. Rhea D. Santos',
        ]],
        ['label' => 'Discipline Fit', 'type' => 'select', 'name' => 'discipline', 'options' => [
            'Engineering, Information Technology, and Computing',
            'Business, Entrepreneurship, Hospitality, and Tourism Management',
            'Education, Social Sciences, and Humanities',
            'Health, Allied Sciences, and Community Development',
        ]],
        ['label' => 'Assignment Remarks', 'type' => 'textarea', 'name' => 'remarks', 'placeholder' => 'Expertise match notes, load considerations, follow-up...'],
    ],
    'notice' => 'Only groups with approved research titles may be assigned an adviser and defense panel. Advisers and panelists must not exceed the approved maximum load for the active term.',
];

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<?php require_once ROOT_PATH . '/includes/crad-module-process.php'; ?>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>