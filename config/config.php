<?php
/**
 * SMS 2 - Global Configuration
 * Bestlink College of the Philippines
 */

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Student Management System 2');
}
if (!defined('APP_SHORT_NAME')) {
    define('APP_SHORT_NAME', 'SMS 2');
}
if (!defined('INSTITUTION')) {
    define('INSTITUTION', 'Bestlink College of the Philippines');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}

// Adjust if deployed under a different folder name
if (!defined('BASE_URL')) {
    define('BASE_URL', '/SMS2_system');
}
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

date_default_timezone_set('Asia/Manila');

/**
 * Module registry — drives sidebar navigation and subsystem index cards.
 * slug: URL-safe filename (without .php)
 */
$MODULES = [
    'enrollment' => [
        'label' => 'Enrollment Management',
        'icon'  => 'fa-user-graduate',
        'groups' => [
            'Registration' => [
                'online-pre-registration',
                'document-upload-portal',
                'enrollment-validation',
            ],
            'Student Assignment' => [
                'id-number-generation',
                'grade-level-assignment',
                'auto-section-assignment',
            ],
            'Queue & Waitlist' => [
                'waiting-list-queue',
                'cross-enrollment-checker',
            ],
            'Communication' => [
                'parent-notification',
            ],
            'Reports' => [
                'enrollment-dashboard',
            ],
        ],
        'pages' => [
            ['slug' => 'online-pre-registration', 'title' => 'Online Pre-registration'],
            ['slug' => 'document-upload-portal', 'title' => 'Document Upload Portal'],
            ['slug' => 'enrollment-validation', 'title' => 'Enrollment Validation'],
            ['slug' => 'id-number-generation', 'title' => 'ID Number Generation'],
            ['slug' => 'grade-level-assignment', 'title' => 'Grade Level Assignment'],
            ['slug' => 'waiting-list-queue', 'title' => 'Waiting List Queue'],
            ['slug' => 'cross-enrollment-checker', 'title' => 'Cross-enrollment Checker'],
            ['slug' => 'auto-section-assignment', 'title' => 'Auto Section Assignment'],
            ['slug' => 'parent-notification', 'title' => 'Parent Notification'],
            ['slug' => 'enrollment-dashboard', 'title' => 'Enrollment Dashboard'],
        ],
    ],
    'registrar' => [
        'label' => 'Registrar',
        'icon'  => 'fa-folder-open',
        'groups' => [
            'Student Records' => [
                'student-information-system',
                'persona-file-database',
                'guardian-emergency-contact',
                'academic-history',
            ],
            'Health & Compliance' => [
                'health-record-log',
                'rfid-qr-code-integration',
            ],
            'Documents & ID' => [
                'student-id-generation',
                'document-requests',
                'transcript-management',
            ],
            'Digital Files' => [
                'student-status-tracker',
                'digital-file-storage',
            ],
        ],
        'pages' => [
            ['slug' => 'student-information-system', 'title' => 'Student Information System'],
            ['slug' => 'persona-file-database', 'title' => 'Persona File Database'],
            ['slug' => 'guardian-emergency-contact', 'title' => 'Guardian & Emergency Contact'],
            ['slug' => 'academic-history', 'title' => 'Academic History'],
            ['slug' => 'health-record-log', 'title' => 'Health Record Log'],
            ['slug' => 'rfid-qr-code-integration', 'title' => 'RFID/QR Code Integration'],
            ['slug' => 'student-id-generation', 'title' => 'Student ID Generation'],
            ['slug' => 'document-requests', 'title' => 'Document Requests'],
            ['slug' => 'student-status-tracker', 'title' => 'Student Status Tracker'],
            ['slug' => 'digital-file-storage', 'title' => 'Digital File Storage'],
            ['slug' => 'transcript-management', 'title' => 'Transcript Management'],
        ],
    ],
    'curriculum' => [
        'label' => 'Curriculum & Subject Management',
        'icon'  => 'fa-book',
        'groups' => [
            'Curriculum Setup' => [
                'curriculum-builder',
                'subject-mapping',
                'pre-requisite-configuration',
                'electives-manager',
                'academic-strand-assignment',
            ],
            'Subject Management' => [
                'subject-offering-history',
                'subject-equivalency-tool',
                'grade-weighting-setup',
            ],
            'Validation & Export' => [
                'ched-deped-validator',
                'curriculum-export-tool',
            ],
        ],
        'pages' => [
            ['slug' => 'curriculum-builder', 'title' => 'Curriculum Builder'],
            ['slug' => 'subject-mapping', 'title' => 'Subject Mapping'],
            ['slug' => 'pre-requisite-configuration', 'title' => 'Pre-requisite Configuration'],
            ['slug' => 'electives-manager', 'title' => 'Electives Manager'],
            ['slug' => 'academic-strand-assignment', 'title' => 'Academic Strand Assignment'],
            ['slug' => 'subject-offering-history', 'title' => 'Subject Offering History'],
            ['slug' => 'subject-equivalency-tool', 'title' => 'Subject Equivalency Tool'],
            ['slug' => 'grade-weighting-setup', 'title' => 'Grade Weighting Setup'],
            ['slug' => 'ched-deped-validator', 'title' => 'CHED/DepEd Validator'],
            ['slug' => 'curriculum-export-tool', 'title' => 'Curriculum Export Tool'],
        ],
    ],
    'accreditation' => [
        'label' => 'Accreditation Management',
        'icon'  => 'fa-award',
        'groups' => [
            'Documents & Reports' => [
                'accreditation-document-repository',
                'self-assessment-report-builder',
            ],
            'Compliance & Tracking' => [
                'compliance-matrix-criteria-tracking',
                'program-accreditation-tracker',
                'faculty-staff-qualification-tracking',
                'physical-facilities-monitoring',
            ],
            'Quality Audits' => [
                'internal-quality-audit-scheduler',
                'continuous-improvement-action-planning',
            ],
            'Accreditation Visits' => [
                'accreditation-visit-management',
            ],
            'Reports' => [
                'accreditation-reports-analytics',
            ],
        ],
        'pages' => [
            ['slug' => 'accreditation-document-repository', 'title' => 'Accreditation Document Repository'],
            ['slug' => 'self-assessment-report-builder', 'title' => 'Self Assessment Report Builder'],
            ['slug' => 'compliance-matrix-criteria-tracking', 'title' => 'Compliance Matrix & Criteria Tracking'],
            ['slug' => 'internal-quality-audit-scheduler', 'title' => 'Internal Quality Audit Scheduler'],
            ['slug' => 'accreditation-visit-management', 'title' => 'Accreditation Visit Management'],
            ['slug' => 'program-accreditation-tracker', 'title' => 'Program Accreditation Tracker'],
            ['slug' => 'faculty-staff-qualification-tracking', 'title' => 'Faculty & Staff Qualification Tracking'],
            ['slug' => 'physical-facilities-monitoring', 'title' => 'Physical Facilities Monitoring'],
            ['slug' => 'continuous-improvement-action-planning', 'title' => 'Continuous Improvement Action Planning'],
            ['slug' => 'accreditation-reports-analytics', 'title' => 'Accreditation Reports & Analytics'],
        ],
    ],
  'payment' => [
        'label' => 'Payment Management',
        'icon'  => 'fa-credit-card',
        // ETO YUNG NAWAWALA BRO: Ang 'groups' array para sa Sidebar Headers
        'groups' => [
            'Accounting / Cashier' => [
                'accounting/student-billing-invoicing',
                'accounting/payment-collection-portal',
                'accounting/discount-scholarship-application',
                'accounting/payment-history-ledger-system',
                'accounting/collection-reporting-analytics',
                'accounting/payment-concern-portal',
            ],
            'Admin / MIS Setup' => [
                'admin/fee-setup-configuration',
                'admin/online-payment-integration',
            ],
        ],
        'pages' => [
            ['slug' => 'accounting/student-billing-invoicing', 'title' => 'Student Billing & Invoicing'],
            ['slug' => 'accounting/payment-collection-portal', 'title' => 'Payment Collection Portal'],
            ['slug' => 'accounting/discount-scholarship-application', 'title' => 'Discount & Scholarship Application'],
            ['slug' => 'accounting/payment-history-ledger-system', 'title' => 'Payment History & Ledger System'],
            ['slug' => 'accounting/collection-reporting-analytics', 'title' => 'Collection Reporting & Analytics'],
            ['slug' => 'accounting/payment-concern-portal', 'title' => 'Payment Concern Portal'],
            
            ['slug' => 'admin/fee-setup-configuration', 'title' => 'Fee Setup & Configuration'],
            ['slug' => 'admin/online-payment-integration', 'title' => 'Online Payment Integration'],
        ],
    ],
    'faculty' => [
        'label' => 'Faculty Management',
        'icon'  => 'fa-chalkboard-teacher',
        'groups' => [
            'Faculty Profiles' => [
                'faculty-profile',
                'faculty-directory',
                'teaching-history',
            ],
            'Schedule & Load' => [
                'subject-load-tracker',
                'schedule-assignment',
                'attendance-monitoring',
            ],
            'Leave & Payroll' => [
                'leave-application-approval',
                'salary-grade-payroll-setup',
            ],
            'Evaluation & Clearance' => [
                'evaluation-summary',
                'clearance-system',
            ],
        ],
        'pages' => [
            ['slug' => 'faculty-profile', 'title' => 'Faculty Profile'],
            ['slug' => 'subject-load-tracker', 'title' => 'Subject Load Tracker'],
            ['slug' => 'schedule-assignment', 'title' => 'Schedule Assignment'],
            ['slug' => 'attendance-monitoring', 'title' => 'Attendance Monitoring'],
            ['slug' => 'leave-application-approval', 'title' => 'Leave Application & Approval'],
            ['slug' => 'salary-grade-payroll-setup', 'title' => 'Salary Grade & Payroll Setup'],
            ['slug' => 'teaching-history', 'title' => 'Teaching History'],
            ['slug' => 'clearance-system', 'title' => 'Clearance System'],
            ['slug' => 'evaluation-summary', 'title' => 'Evaluation Summary'],
            ['slug' => 'faculty-directory', 'title' => 'Faculty Directory'],
        ],
    ],
    'scheduling' => [
        'label' => 'Class Schedule',
        'icon'  => 'fa-calendar-alt',
        'groups' => [
            'Schedule Setup' => [
                'section-assignment-tool',
                'teacher-schedule-mapping',
                'special-class-scheduler',
                'exam-timetable-generator',
            ],
            'Conflict & Rooms' => [
                'conflict-checker',
                'room-availability-checker',
                'substitute-assignment-tracker',
            ],
            'Tools & Integration' => [
                'schedule-cloning-tool',
                'time-block-generator',
                'calendar-integration',
            ],
        ],
        'pages' => [
            ['slug' => 'section-assignment-tool', 'title' => 'Section Assignment Tool'],
            ['slug' => 'teacher-schedule-mapping', 'title' => 'Teacher Schedule Mapping'],
            ['slug' => 'conflict-checker', 'title' => 'Conflict Checker'],
            ['slug' => 'exam-timetable-generator', 'title' => 'Exam Timetable Generator'],
            ['slug' => 'substitute-assignment-tracker', 'title' => 'Substitute Assignment Tracker'],
            ['slug' => 'special-class-scheduler', 'title' => 'Special Class Scheduler'],
            ['slug' => 'room-availability-checker', 'title' => 'Room Availability Checker'],
            ['slug' => 'schedule-cloning-tool', 'title' => 'Schedule Cloning Tool'],
            ['slug' => 'time-block-generator', 'title' => 'Time Block Generator'],
            ['slug' => 'calendar-integration', 'title' => 'Calendar Integration'],
        ],
    ],
    'cocurricular' => [
        'label' => 'Co-Curricular',
        'icon'  => 'fa-users',
        'groups' => [
            'Clubs & Membership' => [
                'club-registration-portal',
                'student-club-membership',
                'club-officer-elections',
                'club-directory',
            ],
            'Events & Activities' => [
                'event-activity-logs',
                'attendance-tracker',
                'club-achievement-records',
                'inter-school-communication',
            ],
            'Budget & Volunteering' => [
                'budget-requests',
                'volunteer-hour-tracking',
            ],
        ],
        'pages' => [
            ['slug' => 'club-registration-portal', 'title' => 'Club Registration Portal'],
            ['slug' => 'student-club-membership', 'title' => 'Student Club Membership'],
            ['slug' => 'club-officer-elections', 'title' => 'Club Officer Elections'],
            ['slug' => 'event-activity-logs', 'title' => 'Event & Activity Logs'],
            ['slug' => 'attendance-tracker', 'title' => 'Attendance Tracker'],
            ['slug' => 'club-achievement-records', 'title' => 'Club Achievement Records'],
            ['slug' => 'budget-requests', 'title' => 'Budget Requests'],
            ['slug' => 'inter-school-communication', 'title' => 'Inter-school Communication'],
            ['slug' => 'volunteer-hour-tracking', 'title' => 'Volunteer Hour Tracking'],
            ['slug' => 'club-directory', 'title' => 'Club Directory'],
        ],
    ],
    'lms' => [
        'label' => 'Online Learning & LMS',
        'icon'  => 'fa-laptop',
        'groups' => [
            'Class & Materials' => [
                'class-portal',
                'lesson-material-upload',
                'multimedia-support',
                'virtual-classroom-integration',
            ],
            'Assessments' => [
                'assignment-submission',
                'online-quiz',
                'grading-integration',
                'feedback-comments',
            ],
            'Tracking & Analytics' => [
                'module-completion-tracking',
                'lms-analytics',
            ],
        ],
        'pages' => [
            ['slug' => 'class-portal', 'title' => 'Class Portal'],
            ['slug' => 'lesson-material-upload', 'title' => 'Lesson Material Upload'],
            ['slug' => 'assignment-submission', 'title' => 'Assignment Submission'],
            ['slug' => 'online-quiz', 'title' => 'Online Quiz'],
            ['slug' => 'virtual-classroom-integration', 'title' => 'Virtual Classroom Integration'],
            ['slug' => 'grading-integration', 'title' => 'Grading Integration'],
            ['slug' => 'feedback-comments', 'title' => 'Feedback & Comments'],
            ['slug' => 'module-completion-tracking', 'title' => 'Module Completion Tracking'],
            ['slug' => 'multimedia-support', 'title' => 'Multimedia Support'],
            ['slug' => 'lms-analytics', 'title' => 'LMS Analytics'],
        ],
    ],
    'crad' => [
        'label' => 'CRAD',
        'icon'  => 'fa-flask',
        'groups' => [
            'Research Proposal' => [
                'proposal-submission-tracking',
                'register-proposal',
            ],
            'Research Management' => [
                'adviser-panel-assignment',
                'research-defense-scheduling',
            ],
            'Research Funding' => [
                'research-grants-funding-assistance',
            ],
            'Research Documents' => [
                'documentation-publication-management',
            ],
            'Reports' => [
                'research-analytics-reporting',
            ],
        ],
        'pages' => [
            ['slug' => 'proposal-submission-tracking', 'title' => 'Research Proposal Submission & Tracking'],
            ['slug' => 'register-proposal', 'title' => 'Register Proposal'],
            ['slug' => 'adviser-panel-assignment', 'title' => 'Adviser & Panel Assignment'],
            ['slug' => 'research-grants-funding-assistance', 'title' => 'Research Grants & Funding Assistance'],
            ['slug' => 'research-defense-scheduling', 'title' => 'Research Defense Scheduling'],
            ['slug' => 'documentation-publication-management', 'title' => 'Documentation & Publication Management'],
            ['slug' => 'research-analytics-reporting', 'title' => 'Research Analytics & Reporting'],
        ],
    ],
    'reports-analytics' => [
        'label' => 'Reports & Analytics',
        'icon'  => 'fa-chart-bar',
        'pages' => [
            // Pages are filtered per role via includes/reports-catalog.php
            ['slug' => 'performance-trends', 'title' => 'Performance Trends'],
            ['slug' => 'export-center', 'title' => 'Export Center'],
            ['slug' => 'enrollment-analytics', 'title' => 'Enrollment Analytics'],
            ['slug' => 'student-records-report', 'title' => 'Student Records Report'],
            ['slug' => 'document-release-analytics', 'title' => 'Document Release Analytics'],
            ['slug' => 'curriculum-analytics', 'title' => 'Curriculum Analytics'],
            ['slug' => 'class-schedule-analytics', 'title' => 'Class Schedule Analytics'],
            ['slug' => 'research-proposal-analytics', 'title' => 'Research Proposal Analytics'],
            ['slug' => 'adviser-grants-report', 'title' => 'Adviser & Grants Report'],
            ['slug' => 'publication-repository-report', 'title' => 'Publication & Repository Report'],
            ['slug' => 'collections-analytics', 'title' => 'Collections Analytics'],
            ['slug' => 'receivables-report', 'title' => 'Receivables Report'],
            ['slug' => 'faculty-load-report', 'title' => 'Faculty Load Report'],
            ['slug' => 'leave-evaluation-analytics', 'title' => 'Leave & Evaluation Analytics'],
            ['slug' => 'lms-engagement-report', 'title' => 'LMS Engagement Report'],
            ['slug' => 'module-completion-analytics', 'title' => 'Module Completion Analytics'],
            ['slug' => 'club-activity-report', 'title' => 'Club & Activity Report'],
            ['slug' => 'volunteer-budget-analytics', 'title' => 'Volunteer & Budget Analytics'],
            ['slug' => 'accreditation-compliance-report', 'title' => 'Accreditation Compliance Report'],
            ['slug' => 'audit-findings-analytics', 'title' => 'Audit Findings Analytics'],
        ],
    ],

    // ── Superadmin only ──────────────────────────────────────────────────────
    'user-management' => [
        'label' => 'User Management',
        'icon'  => 'fa-users-cog',
        'groups' => [
            'Accounts & Roles' => [
                'user-accounts',
                'role-permissions',
            ],
            'Security' => [
                'module-security',
            ],
            'Monitoring' => [
                'activity-logs',
            ],
            'Settings' => [
                'system-settings',
            ],
        ],
        'pages' => [
            ['slug' => 'user-accounts',    'title' => 'User Accounts'],
            ['slug' => 'role-permissions', 'title' => 'Role & Permissions'],
            ['slug' => 'module-security',  'title' => 'Module Security'],
            ['slug' => 'activity-logs',    'title' => 'Activity Logs'],
            ['slug' => 'system-settings',  'title' => 'System Settings'],
        ],
    ],
];
