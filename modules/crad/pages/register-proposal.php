<?php
/**
 * SMS 2 - Register Proposal
 * Module: CRAD
 * Register a new research proposal with auto-generated proposal number.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';

requireAuth();

$pageTitle    = 'Register Proposal';
$activeModule = 'crad';
$activePage   = 'register-proposal';
$breadcrumbs  = [
    ['label' => 'CRAD', 'url' => BASE_URL . '/modules/crad/index.php'],
    ['label' => 'Register Proposal', 'url' => null],
];

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';

// ── Departments & Academic Years ─────────────────────────────────────────────
$departments = [
    'College of Computer Studies',
    'College of Business Administration',
    'College of Education',
    'College of Criminal Justice',
    'College of Hospitality & Tourism Management',
    'College of Nursing and Health Sciences',
];

$academicYears = [
    'A.Y. 2025-2026',
    'A.Y. 2026-2027',
    'A.Y. 2027-2028',
];

// ── Generate next Proposal Number ────────────────────────────────────────────
$proposalNumber = '';
$nextSeq = 1;
try {
    $cradPdo = getCradDatabaseConnection();
    $lastRow = $cradPdo->query(
        "SELECT MAX(id) AS max_id FROM research_proposals"
    )->fetch();
    $nextSeq = (int) ($lastRow['max_id'] ?? 0) + 1;
    $proposalNumber = 'CRD-' . date('Y') . '-' . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
} catch (Throwable $e) {
    error_log('CRAD register proposal error: ' . $e->getMessage());
    $proposalNumber = 'CRD-' . date('Y') . '-00001';
}

// ── Handle form submission ───────────────────────────────────────────────────
$formError = '';
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['process'] ?? '') === 'register-proposal')) {
    if (!csrfVerify()) {
        $formError = 'Security check failed. Please try again.';
    } else {
        try {
            $cradPdo = getCradDatabaseConnection();

            // Re-generate proposal number to avoid race conditions
            $lastRow = $cradPdo->query(
                "SELECT MAX(id) AS max_id FROM research_proposals"
            )->fetch();
            $nextSeq = (int) ($lastRow['max_id'] ?? 0) + 1;
            $refCode = 'CRD-' . date('Y') . '-' . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);

            $stmt = $cradPdo->prepare(
                "INSERT INTO research_proposals
                    (ref_code, research_title, program_course, year_section,
                     college_department, research_adviser, academic_year,
                     rep_name, rep_id, rep_email, rep_contact,
                     status, progress, date_submitted, submitted_by_user)
                 VALUES
                    (:ref_code, :research_title, :program_course, :year_section,
                     :college_department, :research_adviser, :academic_year,
                     :rep_name, :rep_id, :rep_email, :rep_contact,
                     'Submitted', 0, :date_submitted, :submitted_by_user)"
            );
            $stmt->execute([
                ':ref_code'           => $refCode,
                ':research_title'     => trim($_POST['research_title']  ?? ''),
                ':program_course'     => trim($_POST['program_course']  ?? ''),
                ':year_section'       => trim($_POST['year_section']    ?? ''),
                ':college_department' => trim($_POST['college_department'] ?? ''),
                ':research_adviser'   => trim($_POST['research_adviser'] ?? ''),
                ':academic_year'      => trim($_POST['academic_year']   ?? ''),
                ':rep_name'           => trim($_POST['rep_name']        ?? ''),
                ':rep_id'             => trim($_POST['rep_id']          ?? ''),
                ':rep_email'          => trim($_POST['rep_email']       ?? ''),
                ':rep_contact'        => trim($_POST['rep_contact']     ?? ''),
                ':date_submitted'     => trim($_POST['date_submitted']  ?? date('Y-m-d')),
                ':submitted_by_user'  => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $proposalId = (int) $cradPdo->lastInsertId();

            // Insert group members
            $memberIds      = $_POST['member_id']      ?? [];
            $memberNames    = $_POST['member_name']    ?? [];
            $memberEmails   = $_POST['member_email']   ?? [];
            $memberContacts = $_POST['member_contact'] ?? [];
            $memberStmt = $cradPdo->prepare(
                "INSERT INTO proposal_members
                    (proposal_id, sort_order, student_id, student_name, email, contact)
                 VALUES
                    (:proposal_id, :sort_order, :student_id, :student_name, :email, :contact)"
            );
            foreach ($memberIds as $i => $mid) {
                $mid = trim($mid);
                if ($mid === '') { continue; }
                $memberStmt->execute([
                    ':proposal_id'  => $proposalId,
                    ':sort_order'   => $i + 1,
                    ':student_id'   => $mid,
                    ':student_name' => trim($memberNames[$i]    ?? ''),
                    ':email'        => trim($memberEmails[$i]   ?? ''),
                    ':contact'      => trim($memberContacts[$i] ?? ''),
                ]);
            }

            // Log initial status
            $logStmt = $cradPdo->prepare(
                "INSERT INTO proposal_status_logs
                    (proposal_id, old_status, new_status, changed_by, remarks)
                 VALUES
                    (:proposal_id, NULL, 'Submitted', :changed_by, 'Registered via CRAD Register Proposal')"
            );
            $logStmt->execute([
                ':proposal_id' => $proposalId,
                ':changed_by'  => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);

            if (function_exists('logActivity')) {
                logActivity(
                    'create',
                    'Registered research proposal ref:' . $refCode,
                    'crad'
                );
            }

            $formSuccess = 'Proposal <strong>' . htmlspecialchars($refCode) . '</strong> has been registered successfully.';
            $proposalNumber = 'CRD-' . date('Y') . '-' . str_pad((string) ($nextSeq + 1), 5, '0', STR_PAD_LEFT);

        } catch (Throwable $e) {
            error_log('CRAD register proposal error: ' . $e->getMessage());
            $formError = 'Failed to register proposal. Please try again. (' . htmlspecialchars($e->getMessage()) . ')';
        }
    }
}
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<?php if ($formError !== ''): ?>
<div style="
    display:flex;align-items:center;gap:0.75rem;
    padding:0.85rem 1.1rem;margin-bottom:1rem;
    border:1px solid #fecaca;border-radius:12px;
    background:#fef2f2;color:#991b1b;font-size:0.88rem;font-weight:600;"
    role="alert">
    <i class="fas fa-exclamation-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
    <span><?= $formError ?></span>
</div>
<?php endif; ?>

<?php if ($formSuccess !== ''): ?>
<div style="
    display:flex;align-items:center;gap:0.75rem;
    padding:0.85rem 1.1rem;margin-bottom:1rem;
    border:1px solid #bbf7d0;border-radius:12px;
    background:#f0fdf4;color:#166534;font-size:0.88rem;font-weight:600;"
    role="alert">
    <i class="fas fa-check-circle" style="font-size:1.1rem;flex-shrink:0;"></i>
    <span><?= $formSuccess ?></span>
</div>
<?php endif; ?>

<style>
.rp-wrap { display: flex; flex-direction: column; gap: 1.5rem; }

/* Header card */
.rp-header {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 1.25rem 1.4rem;
    border: 1px solid var(--sms-border, #e2e8f0);
    border-radius: 16px;
    background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #312e81 100%);
    color: #fff;
    box-shadow: var(--sms-shadow-sm);
}
.rp-header h1 { margin: 0; font-size: 1.35rem; font-weight: 800; }
.rp-header p { margin: 0.3rem 0 0; color: #c7d2fe; font-size: 0.86rem; }
.rp-header-actions { display: flex; gap: 0.6rem; flex-shrink: 0; }
.rp-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
    min-height: 40px; padding: 0.5rem 1rem; border-radius: 10px;
    border: 1px solid transparent; font-size: 0.84rem; font-weight: 700;
    text-decoration: none; cursor: pointer; transition: all 0.15s ease;
}
.rp-btn-ghost { color: #e0e7ff; background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.25); }
.rp-btn-ghost:hover { background: rgba(255,255,255,0.18); color: #fff; }
.rp-btn-primary { color: #fff; background: #4f46e5; border-color: #4f46e5; box-shadow: 0 6px 16px rgba(79,70,229,0.35); }
.rp-btn-primary:hover { background: #4338ca; border-color: #4338ca; }

/* Form card */
.rp-card {
    border: 1px solid var(--sms-border, #e2e8f0);
    border-radius: 16px;
    background: var(--sms-surface-solid, #fff);
    box-shadow: var(--sms-shadow-sm);
    overflow: hidden;
}
.rp-card-head {
    padding: 1rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--sms-border, #e2e8f0);
}
.rp-card-head h2 {
    margin: 0; font-size: 0.72rem; font-weight: 800;
    letter-spacing: 0.07em; text-transform: uppercase;
    color: var(--sms-text-muted);
}
.rp-card-body { padding: 1.25rem; }

/* Proposal number display */
.rp-proposal-no {
    display: flex; align-items: center; gap: 0.85rem;
    padding: 1rem 1.25rem; margin-bottom: 1.25rem;
    border: 1px dashed #6366f1; border-radius: 12px;
    background: rgba(99,102,241,0.06);
}
.rp-proposal-no-icon {
    width: 44px; height: 44px; flex: 0 0 auto;
    display: grid; place-items: center;
    border-radius: 12px; font-size: 1.1rem;
    color: #4f46e5; background: rgba(99,102,241,0.14);
}
.rp-proposal-no-text span {
    display: block; color: var(--sms-text-muted);
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.05em;
}
.rp-proposal-no-text strong {
    display: block; margin-top: 0.15rem;
    color: #4f46e5; font-size: 1.15rem; font-weight: 800;
    letter-spacing: 0.02em;
}

/* Form grid */
.rp-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.9rem; }
.rp-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.9rem; }
.rp-field { display: grid; gap: 0.4rem; margin-bottom: 0.9rem; }
.rp-field label {
    color: var(--sms-text-muted); font-size: 0.72rem; font-weight: 700;
    letter-spacing: 0.04em; text-transform: uppercase;
}
.rp-field label em { color: #ef4444; font-style: normal; }
.rp-field input,
.rp-field select {
    width: 100%; min-height: 42px; padding: 0.6rem 0.8rem;
    border: 1px solid var(--sms-border, #d7e1ef); border-radius: 10px;
    background: var(--sms-input-bg, #fff); color: var(--sms-text);
    font-size: 0.88rem; outline: none; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.rp-field select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748b' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.9rem center; padding-right: 2.2rem;
}
.rp-field input:focus,
.rp-field select:focus {
    border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.rp-field input[readonly] {
    background: var(--sms-surface-muted, #f8fafc);
    color: var(--sms-text-muted);
    font-weight: 700;
    letter-spacing: 0.03em;
}

/* Section title */
.rp-section-title {
    display: flex; align-items: center; gap: 0.55rem;
    margin: 1.5rem 0 1rem; padding-top: 1.25rem;
    border-top: 1px solid var(--sms-border, #e2e8f0);
    color: var(--sms-heading); font-size: 0.95rem; font-weight: 800;
}
.rp-section-title:first-child { margin-top: 0; padding-top: 0; border-top: none; }
.rp-section-title span {
    width: 8px; height: 8px; border-radius: 50%;
    background: #6366f1; flex-shrink: 0;
}

/* Members table */
.rp-members-table {
    overflow: hidden; border: 1px solid var(--sms-border, #e2e8f0); border-radius: 12px;
}
.rp-members-head, .rp-members-row {
    display: grid; grid-template-columns: 36px 1fr 1.2fr 1.2fr 1fr; gap: 0.55rem; align-items: center;
}
.rp-members-head {
    padding: 0.7rem 0.75rem; background: var(--sms-surface-muted, #f8fafc);
    color: var(--sms-text-muted); font-size: 0.68rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.rp-members-row { padding: 0.55rem 0.75rem; border-top: 1px solid var(--sms-border, #e2e8f0); }
.rp-members-row > span { color: #6366f1; font-weight: 800; text-align: center; }
.rp-members-row input {
    width: 100%; min-height: 38px; padding: 0.5rem 0.7rem;
    border: 1px solid var(--sms-border, #d7e1ef); border-radius: 8px;
    background: var(--sms-input-bg, #fff); color: var(--sms-text); font-size: 0.84rem; outline: none;
}
.rp-members-row input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }

/* Footer */
.rp-form-footer {
    display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 0.7rem;
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid var(--sms-border, #e2e8f0);
    background: var(--sms-surface-muted, #f8fafc);
}
.rp-btn-secondary { color: var(--sms-text); background: var(--sms-surface-solid, #fff); border-color: var(--sms-border, #d7e1ef); }
.rp-btn-secondary:hover { background: var(--sms-surface-muted, #f1f5f9); }

/* Dark mode */
[data-theme="dark"] .rp-card {
    background: rgba(15,23,42,0.72); border-color: rgba(148,163,184,0.2);
}
[data-theme="dark"] .rp-field input,
[data-theme="dark"] .rp-field select,
[data-theme="dark"] .rp-members-row input {
    background: rgba(15,23,42,0.72); border-color: rgba(148,163,184,0.2); color: #e2e8f0;
}
[data-theme="dark"] .rp-field input[readonly] {
    background: rgba(148,163,184,0.08); color: #94a3b8;
}
[data-theme="dark"] .rp-members-head,
[data-theme="dark"] .rp-form-footer {
    background: rgba(148,163,184,0.06); border-color: rgba(148,163,184,0.2);
}
[data-theme="dark"] .rp-members-table { border-color: rgba(148,163,184,0.2); }
[data-theme="dark"] .rp-members-row { border-top-color: rgba(148,163,184,0.2); }
[data-theme="dark"] .rp-section-title { border-top-color: rgba(148,163,184,0.2); }
[data-theme="dark"] .rp-btn-secondary { background: rgba(15,23,42,0.72); border-color: rgba(148,163,184,0.2); color: #e2e8f0; }

@media (max-width: 991.98px) {
    .rp-grid-2, .rp-grid-3, .rp-members-head, .rp-members-row { grid-template-columns: 1fr; }
    .rp-members-head { display: none; }
    .rp-members-row { gap: 0.45rem; }
    .rp-header { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="rp-wrap">

    <!-- Header -->
    <header class="rp-header">
        <div>
            <h1><i class="fas fa-file-signature me-2"></i>Register Proposal</h1>
            <p>Register a new research proposal and generate its official proposal number.</p>
        </div>
        <div class="rp-header-actions">
            <a class="rp-btn rp-btn-ghost" href="<?= BASE_URL ?>/modules/crad/pages/proposal-submission-tracking.php">
                <i class="fas fa-arrow-left"></i> Back to Tracking
            </a>
        </div>
    </header>

    <!-- Registration Form -->
    <form method="post" action="" id="registerProposalForm">
        <?= csrfField() ?>
        <input type="hidden" name="process" value="register-proposal">

        <section class="rp-card">
            <div class="rp-card-head">
                <h2>Proposal Registration Form</h2>
            </div>
            <div class="rp-card-body">

                <!-- Proposal Number -->
                <div class="rp-proposal-no">
                    <div class="rp-proposal-no-icon"><i class="fas fa-hashtag"></i></div>
                    <div class="rp-proposal-no-text">
                        <span>Proposal Number</span>
                        <strong><?= htmlspecialchars($proposalNumber) ?></strong>
                    </div>
                </div>

                <!-- Research Information -->
                <h3 class="rp-section-title"><span></span>Research Information</h3>
                <div class="rp-field">
                    <label for="researchTitle">Research Title <em>*</em></label>
                    <input type="text" id="researchTitle" name="research_title" placeholder="ENTER COMPLETE APPROVED RESEARCH TITLE" required>
                </div>
                <div class="rp-grid-2">
                    <div class="rp-field">
                        <label for="programCourse">Program / Course <em>*</em></label>
                        <input type="text" id="programCourse" name="program_course" placeholder="e.g. BS in Information Technology" required>
                    </div>
                    <div class="rp-field">
                        <label for="yearSection">Year & Section <em>*</em></label>
                        <input type="text" id="yearSection" name="year_section" placeholder="e.g. BSIT 4101" required>
                    </div>
                </div>
                <div class="rp-grid-2">
                    <div class="rp-field">
                        <label for="collegeDept">College / Department <em>*</em></label>
                        <select id="collegeDept" name="college_department" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rp-field">
                        <label for="researchAdviser">Research Adviser <em>*</em></label>
                        <input type="text" id="researchAdviser" name="research_adviser" placeholder="Instructor / Doctor Name" required>
                    </div>
                </div>
                <div class="rp-field" style="max-width:50%;">
                    <label for="academicYear">Academic Year <em>*</em></label>
                    <select id="academicYear" name="academic_year" required>
                        <option value="">Select Academic Year</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?= htmlspecialchars($year) ?>"><?= htmlspecialchars($year) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Group Members -->
                <h3 class="rp-section-title"><span></span>Group Members <small style="font-size:0.75rem;color:var(--sms-text-muted);font-weight:600;">(Maximum 5)</small></h3>
                <div class="rp-members-table">
                    <div class="rp-members-head">
                        <span>#</span>
                        <span>Student ID *</span>
                        <span>Student Name *</span>
                        <span>Email Address *</span>
                        <span>Contact Number *</span>
                    </div>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="rp-members-row">
                            <span><?= $i ?></span>
                            <input type="text" name="member_id[]" placeholder="e.g. 2022-0451" <?= $i === 1 ? 'required' : '' ?>>
                            <input type="text" name="member_name[]" placeholder="e.g. Dela Cruz, Juan A." <?= $i === 1 ? 'required' : '' ?>>
                            <input type="email" name="member_email[]" placeholder="e.g. student@bcp.edu.ph" <?= $i === 1 ? 'required' : '' ?>>
                            <input type="text" name="member_contact[]" placeholder="e.g. 09XXXXXXXXX" <?= $i === 1 ? 'required' : '' ?>>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Group Representative -->
                <h3 class="rp-section-title"><span></span>Group Representative</h3>
                <div class="rp-grid-2">
                    <div class="rp-field">
                        <label for="repName">Representative Name <em>*</em></label>
                        <input type="text" id="repName" name="rep_name" placeholder="e.g. Dela Cruz, Juan A." required>
                    </div>
                    <div class="rp-field">
                        <label for="repId">Student ID <em>*</em></label>
                        <input type="text" id="repId" name="rep_id" placeholder="e.g. 2022-0451" required>
                    </div>
                    <div class="rp-field">
                        <label for="repEmail">Email Address <em>*</em></label>
                        <input type="email" id="repEmail" name="rep_email" placeholder="e.g. student@bcp.edu.ph" required>
                    </div>
                    <div class="rp-field">
                        <label for="repContact">Contact Number <em>*</em></label>
                        <input type="text" id="repContact" name="rep_contact" placeholder="e.g. 09XXXXXXXXX" required>
                    </div>
                </div>

                <!-- Date Submitted -->
                <h3 class="rp-section-title"><span></span>Submission Details</h3>
                <div class="rp-field" style="max-width:50%;">
                    <label for="dateSubmitted">Date Submitted <em>*</em></label>
                    <input type="date" id="dateSubmitted" name="date_submitted" value="<?= date('Y-m-d') ?>" required>
                </div>

            </div>
            <footer class="rp-form-footer">
                <a class="rp-btn rp-btn-secondary" href="<?= BASE_URL ?>/modules/crad/pages/proposal-submission-tracking.php">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="rp-btn rp-btn-primary">
                    <i class="fas fa-save"></i> Register Proposal
                </button>
            </footer>
        </section>
    </form>

</div>

<script>
(function () {
    // Auto-fill representative from member 1
    var memberIdInputs    = document.querySelectorAll('input[name="member_id[]"]');
    var memberNameInputs  = document.querySelectorAll('input[name="member_name[]"]');
    var memberEmailInputs = document.querySelectorAll('input[name="member_email[]"]');
    var memberContactInputs = document.querySelectorAll('input[name="member_contact[]"]');

    function autoFillRep() {
        if (memberIdInputs[0] && memberIdInputs[0].value) {
            document.getElementById('repId').value = memberIdInputs[0].value;
        }
        if (memberNameInputs[0] && memberNameInputs[0].value) {
            document.getElementById('repName').value = memberNameInputs[0].value;
        }
        if (memberEmailInputs[0] && memberEmailInputs[0].value) {
            document.getElementById('repEmail').value = memberEmailInputs[0].value;
        }
        if (memberContactInputs[0] && memberContactInputs[0].value) {
            document.getElementById('repContact').value = memberContactInputs[0].value;
        }
    }

    // Auto-fill when member 1 fields change
    memberIdInputs[0] && memberIdInputs[0].addEventListener('change', autoFillRep);
    memberNameInputs[0] && memberNameInputs[0].addEventListener('change', autoFillRep);
    memberEmailInputs[0] && memberEmailInputs[0].addEventListener('change', autoFillRep);
    memberContactInputs[0] && memberContactInputs[0].addEventListener('change', autoFillRep);
})();
</script>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>