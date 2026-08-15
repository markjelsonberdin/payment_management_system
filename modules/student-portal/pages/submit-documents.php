<?php
/**
 * SMS 2 - Research Document Attachment Form
 * Student Portal — CRD Document Vault (secure uploads)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/uploads.php';
require_once ROOT_PATH . '/modules/crad/config/config.php';

requireAuth();

// ── Research Forum payment gate ──────────────────────────────────────────────
// Check if student has paid the Research Forum fee before allowing access.
// In production, replace this with a real DB query against the payment table.
$_gatePayments = [
    ['description' => 'Tuition Down Payment',  'status' => 'Paid'],
    ['description' => 'Registration Fee',       'status' => 'Paid'],
    ['description' => 'Laboratory Fee',         'status' => 'Paid'],
    ['description' => 'Research Forum',         'status' => 'Paid'],
];
$_researchForumPaid = false;
foreach ($_gatePayments as $_txn) {
    if (
        stripos($_txn['description'], 'Research Forum') !== false &&
        strtolower($_txn['status']) === 'paid'
    ) {
        $_researchForumPaid = true;
        break;
    }
}
if (!$_researchForumPaid) {
    // Redirect to payment history with a notice
    header('Location: ' . '/SMS2_system/modules/student-portal/pages/payment-history.php?notice=research-forum-required');
    exit;
}
unset($_gatePayments, $_txn, $_researchForumPaid);

$studentId = $_SESSION['student_id'] ?? 'S230000001';
$studentName = $_SESSION['user_name'] ?? 'Juan Dela Cruz';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$nameParts = array_values(array_filter(preg_split('/\s+/', trim($studentName)) ?: []));
if (count($nameParts) >= 3) {
    $lastName = $nameParts[count($nameParts) - 2] . ' ' . $nameParts[count($nameParts) - 1];
    $firstNames = implode(' ', array_slice($nameParts, 0, -2));
} elseif (count($nameParts) === 2) {
    $lastName = $nameParts[1];
    $firstNames = $nameParts[0];
} else {
    $lastName = $nameParts[0] ?? 'Dela Cruz';
    $firstNames = 'Juan';
}
$defaultMemberName = $lastName . ', ' . $firstNames . ' A.';

$uploadError = '';
$submitted = false;

$documentSlots = [
    ['key' => 'manuscript', 'title' => 'Research Manuscript', 'desc' => 'Complete research paper manuscript.', 'required' => true],
    ['key' => 'approval', 'title' => 'Approval Sheet', 'desc' => 'Signed title/approval sheet.', 'required' => true],
    ['key' => 'abstract', 'title' => 'Abstract', 'desc' => 'Formal research abstract page.', 'required' => true],
    ['key' => 'certificate_adviser', 'title' => 'Certificate of Technical Adviser and Grammarian', 'desc' => 'Signed adviser and grammarian certificate.', 'required' => true],
    ['key' => 'certificate_originality', 'title' => 'Certificate of Originality', 'desc' => 'Signed originality certification.', 'required' => true],
    ['key' => 'supporting', 'title' => 'Supporting Documents', 'desc' => 'Optional annexes and attachments.', 'required' => false],
    ['key' => 'receipt_screenshot', 'title' => 'Screenshot of the Receipt', 'desc' => 'Screenshot or photo of the payment receipt.', 'required' => true],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['process'] ?? '') === 'submit-documents')) {
    if (!csrfVerify()) {
        $uploadError = 'Security check failed. Please try again.';
    } else {
        $subdir = 'student_docs/u' . max(0, $userId);
        $allowed = [
            'pdf'  => ['application/pdf'],
            'doc'  => ['application/msword', 'application/octet-stream'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/octet-stream',
            ],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
        ];
        $saved = [];
        foreach ($documentSlots as $slot) {
            $key = $slot['key'];
            $file = $_FILES[$key] ?? ['error' => UPLOAD_ERR_NO_FILE];
            $result = smsSecureUpload(is_array($file) ? $file : [], [
                'subdir' => $subdir,
                'max_bytes' => 10 * 1024 * 1024,
                'allowed' => $allowed,
                'required' => !empty($slot['required']),
            ]);
            if (empty($result['ok'])) {
                $uploadError = ($slot['title'] ?? $key) . ': ' . ($result['error'] ?: 'Upload failed.');
                break;
            }
            if (!empty($result['stored_name'])) {
                $saved[$key] = [
                    'stored' => $result['stored_name'],
                    'original' => $result['original_name'],
                    'size' => $result['size'],
                ];
            }
        }
        if ($uploadError === '') {
            // ── Save to crad_db ───────────────────────────────────────────
            try {
                $cradPdo = getCradDatabaseConnection();

                // Generate unique reference code: CRD-YYYY-NNNNN
                $year    = date('Y');
                $lastRow = $cradPdo->query(
                    "SELECT MAX(id) AS max_id FROM research_proposals"
                )->fetch();
                $nextSeq = (int) ($lastRow['max_id'] ?? 0) + 1;
                $refCode = 'CRD-' . $year . '-' . str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);

                // Insert main proposal record
                $stmt = $cradPdo->prepare(
                    "INSERT INTO research_proposals
                        (ref_code, research_title, program_course, year_section,
                         college_department, research_adviser, academic_year,
                         rep_name, rep_id, rep_email, rep_contact,
                         status, progress, date_submitted, signature_data, submitted_by_user)
                     VALUES
                        (:ref_code, :research_title, :program_course, :year_section,
                         :college_department, :research_adviser, :academic_year,
                         :rep_name, :rep_id, :rep_email, :rep_contact,
                         'Submitted', 0, :date_submitted, :signature_data, :submitted_by_user)"
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
                    ':signature_data'     => $_POST['signature_data']       ?? null,
                    ':submitted_by_user'  => $userId ?: null,
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

                // Insert uploaded document records
                if (!empty($saved)) {
                    $docSlotTitles = array_column($documentSlots, 'title', 'key');
                    $docStmt = $cradPdo->prepare(
                        "INSERT INTO proposal_documents
                            (proposal_id, doc_key, doc_title, original_name, stored_name, file_size)
                         VALUES
                            (:proposal_id, :doc_key, :doc_title, :original_name, :stored_name, :file_size)"
                    );
                    foreach ($saved as $docKey => $docInfo) {
                        $docStmt->execute([
                            ':proposal_id'  => $proposalId,
                            ':doc_key'      => $docKey,
                            ':doc_title'    => $docSlotTitles[$docKey] ?? $docKey,
                            ':original_name'=> $docInfo['original'],
                            ':stored_name'  => $docInfo['stored'],
                            ':file_size'    => $docInfo['size'],
                        ]);
                    }
                }

                // Log initial status
                $logStmt = $cradPdo->prepare(
                    "INSERT INTO proposal_status_logs
                        (proposal_id, old_status, new_status, changed_by, remarks)
                     VALUES
                        (:proposal_id, NULL, 'Submitted', :changed_by, 'Initial submission via Student Portal')"
                );
                $logStmt->execute([
                    ':proposal_id' => $proposalId,
                    ':changed_by'  => $userId ?: null,
                ]);

                if (function_exists('logActivity')) {
                    logActivity(
                        'create',
                        'Submitted research document packet ref:' . $refCode . ' (' . count($saved) . ' files)',
                        'student_portal'
                    );
                }

                // Redirect CRAD officer tracking page with success flash
                $trackingUrl = BASE_URL
                    . '/modules/crad/pages/proposal-submission-tracking.php'
                    . '?submitted=1&ref=' . urlencode($refCode);
                header('Location: ' . $trackingUrl);
                exit;

            } catch (Throwable $e) {
                error_log('CRAD submit error: ' . $e->getMessage());
                $uploadError = 'Submission saved but could not be recorded in the CRAD database. Please contact the CRAD officer. (' . htmlspecialchars($e->getMessage()) . ')';
            }
        }
    }
}

$pageTitle = 'Submit Documents';
$activeModule = 'student_portal';
$activePage = 'submit-documents';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/my-profile.php'],
    ['label' => 'Submit Documents', 'url' => null],
];

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

require_once ROOT_PATH . '/includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="student-portal doc-vault-wrap">
    <?php if ($uploadError !== ''): ?>
        <div class="alert alert-danger student-process-alert" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= e($uploadError) ?>
        </div>
    <?php endif; ?>
    <?php if ($submitted): ?>
        <div class="alert alert-success student-process-alert" role="alert">
            <i class="fas fa-check-circle me-2"></i>Document packet has been submitted securely to the CRD Document Vault for review.
        </div>
    <?php endif; ?>

    <form class="doc-vault-form" id="docVaultForm" method="post" action="" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="process" value="submit-documents" id="docProcessField">

        <header class="doc-vault-header">
            <div>
                <span class="doc-vault-kicker">CRD Document Vault Sub-Module</span>
                <h1>Research Document Attachment Form</h1>
                <p>Formal system submission and storage matching program checklist guidelines.</p>
            </div>
            <a class="doc-btn doc-btn-ghost" href="<?= BASE_URL ?>/modules/student-portal/pages/my-profile.php">← Cancel &amp; Exit</a>
        </header>

        <section class="doc-section">
            <h2><span></span>Research Information</h2>
            <div class="doc-field">
                <label for="researchTitle">Research Title <em>*</em></label>
                <input type="text" id="researchTitle" name="research_title" placeholder="ENTER COMPLETE APPROVED RESEARCH TITLE" required>
            </div>
            <div class="doc-grid-2">
                <div class="doc-field">
                    <label for="programCourse">Program / Course <em>*</em></label>
                    <input type="text" id="programCourse" name="program_course" value="BS in Information Technology" required>
                </div>
                <div class="doc-field">
                    <label for="yearSection">Year &amp; Section <em>*</em></label>
                    <input type="text" id="yearSection" name="year_section" value="BSIT 4101" required>
                </div>
            </div>
            <div class="doc-grid-2">
                <div class="doc-field">
                    <label for="collegeDept">College / Department <em>*</em></label>
                    <select id="collegeDept" name="college_department" required>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>" <?= $dept === 'College of Computer Studies' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="doc-field">
                    <label for="researchAdviser">Research Adviser <em>*</em></label>
                    <input type="text" id="researchAdviser" name="research_adviser" placeholder="Instructor / Doctor Name" value="Dr. Roberto M. Santos" required>
                </div>
            </div>
            <div class="doc-field doc-field-half">
                <label for="academicYear">Academic Year <em>*</em></label>
                <select id="academicYear" name="academic_year" required>
                    <?php foreach ($academicYears as $year): ?>
                        <option value="<?= htmlspecialchars($year) ?>" <?= $year === 'A.Y. 2026-2027' ? 'selected' : '' ?>>
                            <?= htmlspecialchars($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </section>

        <section class="doc-section">
            <h2><span></span>Group Members <small>(Maximum 5)</small></h2>
            <div class="doc-members-table">
                <div class="doc-members-head">
                    <span>#</span>
                    <span>Student ID *</span>
                    <span>Student Name *</span>
                    <span>Email Address *</span>
                    <span>Contact Number *</span>
                </div>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <div class="doc-members-row">
                        <span><?= $i ?></span>
                        <input type="text" name="member_id[]" placeholder="e.g. 2022-0451" <?= $i === 1 ? 'value="' . htmlspecialchars($studentId) . '" required' : '' ?>>
                        <input type="text" name="member_name[]" placeholder="e.g. Dela Cruz, Juan A." <?= $i === 1 ? 'value="' . htmlspecialchars($defaultMemberName) . '" required' : '' ?>>
                        <input type="email" name="member_email[]" placeholder="e.g. student@bcp.edu.ph" <?= $i === 1 ? 'value="s' . preg_replace('/\D+/', '', $studentId) . '@bcp.edu.ph" required' : '' ?>>
                        <input type="text" name="member_contact[]" placeholder="e.g. 09XXXXXXXXX" <?= $i === 1 ? 'value="09171234567" required' : '' ?>>
                    </div>
                <?php endfor; ?>
            </div>
        </section>

        <section class="doc-section">
            <h2><span></span>Document Attachments</h2>
            <div class="doc-attach-notice" id="docAttachNotice">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>All required documents must be uploaded before submitting.</strong>
                    <span>Upload each file marked <em>REQUIRED</em> to enable the Submit Form Packet button. Allowed formats: PDF, DOCX, JPG, or PNG. Max 10MB per file.</span>
                </div>
            </div>
            <div id="docMissingAlert" class="doc-missing-alert" style="display:none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="docMissingText">Please upload all required documents before submitting.</span>
            </div>
            <div class="doc-upload-grid">
                <?php foreach ($documentSlots as $slot): ?>
                    <label class="doc-upload-card" for="file_<?= htmlspecialchars($slot['key']) ?>">
                        <span class="doc-tag <?= $slot['required'] ? 'required' : 'optional' ?>">
                            <?= $slot['required'] ? 'REQUIRED' : 'OPTIONAL' ?>
                        </span>
                        <strong><?= htmlspecialchars($slot['title']) ?></strong>
                        <small><?= htmlspecialchars($slot['desc']) ?></small>
                        <span class="doc-upload-btn"><i class="fas fa-cloud-upload-alt"></i> Upload Document File</span>
                        <input type="file" id="file_<?= htmlspecialchars($slot['key']) ?>" name="<?= htmlspecialchars($slot['key']) ?>" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" <?= $slot['required'] ? 'required' : '' ?>>
                        <em class="doc-file-name" data-file-label>No file selected</em>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="doc-section">
            <div class="doc-section-head">
                <div>
                    <h2><span></span>Group Representative</h2>
                    <p class="doc-hint">Primary point of contact for CRD response routing.</p>
                </div>
                <button type="button" class="doc-btn doc-btn-purple-soft" id="autoFillRepBtn">← Auto-fill from Member 1</button>
            </div>
            <div class="doc-grid-2">
                <div class="doc-field">
                    <label for="repName">Representative Name <em>*</em></label>
                    <input type="text" id="repName" name="rep_name" value="<?= htmlspecialchars($defaultMemberName) ?>" required>
                </div>
                <div class="doc-field">
                    <label for="repId">Student ID <em>*</em></label>
                    <input type="text" id="repId" name="rep_id" value="<?= htmlspecialchars($studentId) ?>" required>
                </div>
                <div class="doc-field">
                    <label for="repEmail">Email Address <em>*</em></label>
                    <input type="email" id="repEmail" name="rep_email" value="s<?= preg_replace('/\D+/', '', $studentId) ?>@bcp.edu.ph" required>
                </div>
                <div class="doc-field">
                    <label for="repContact">Contact Number <em>*</em></label>
                    <input type="text" id="repContact" name="rep_contact" value="09171234567" required>
                </div>
            </div>
        </section>

        <section class="doc-section">
            <h2><span></span>Final Declaration &amp; Representative Signature</h2>
            <label class="doc-check">
                <input type="checkbox" id="declarationCheck" name="declaration" value="1" required>
                <span>We certify that the information provided is true and correct. We further certify that all uploaded documents are authentic, complete, and submitted on behalf of all members of the research group.</span>
            </label>
            <div class="doc-signature-row">
                <div class="doc-signature-pad-wrap">
                    <div class="doc-signature-label">
                        <span>Representative Signature Pad (Draw Below):</span>
                        <button type="button" id="clearPadBtn">Clear Pad</button>
                    </div>
                    <canvas id="signaturePad" width="760" height="180" aria-label="Signature pad"></canvas>
                    <input type="hidden" name="signature_data" id="signatureData">
                </div>
                <div class="doc-field">
                    <label for="dateSubmitted">Date Submitted <em>*</em></label>
                    <input type="date" id="dateSubmitted" name="date_submitted" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
        </section>

        <footer class="doc-form-footer">
            <button type="button" class="doc-btn doc-btn-muted" id="saveDraftBtn">Save Draft</button>
            <button type="button" class="doc-btn doc-btn-purple" id="submitPacketBtn"><i class="fas fa-file-upload me-2"></i>Submit Form Packet</button>
            <a class="doc-btn doc-btn-ghost" href="<?= BASE_URL ?>/modules/student-portal/pages/my-profile.php">Cancel</a>
        </footer>
    </form>
</div>

<style>
.doc-vault-wrap { max-width: 100%; margin: 0; padding: 0 0.25rem; }
.doc-vault-form {
    --doc-bg: #12151a;
    --doc-panel: #181c22;
    --doc-input: #222831;
    --doc-line: rgba(148,163,184,0.2);
    --doc-purple: #7c3aed;
    --doc-purple-2: #8b5cf6;
    overflow: hidden; border: 1px solid rgba(148,163,184,0.14); border-radius: 18px;
    background: linear-gradient(180deg, #171a20 0%, var(--doc-bg) 40%);
    color: #f8fafc; box-shadow: 0 18px 40px rgba(0,0,0,0.28);
}
.doc-vault-header {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
    padding: 1.35rem 1.4rem 1.1rem;
    background: linear-gradient(135deg, #2e1065 0%, #4c1d95 45%, #111827 100%);
}
.doc-vault-kicker {
    display: inline-block; margin-bottom: 0.35rem; color: #c4b5fd;
    font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase;
}
.doc-vault-header h1 { margin: 0; color: #fff; font-size: 1.45rem; font-weight: 800; }
.doc-vault-header p { margin: 0.35rem 0 0; color: #ddd6fe; font-size: 0.9rem; }
.doc-section {
    padding: 1.2rem 1.4rem; border-bottom: 1px solid var(--doc-line);
}
.doc-section h2 {
    display: flex; align-items: center; gap: 0.55rem;
    margin: 0 0 1rem; color: #fff; font-size: 0.98rem; font-weight: 800;
}
.doc-section h2 span {
    width: 8px; height: 8px; border-radius: 50%; background: var(--doc-purple-2);
}
.doc-section h2 small { color: #94a3b8; font-size: 0.78rem; font-weight: 600; }
.doc-section-head {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.85rem;
}
.doc-section-head h2 { margin-bottom: 0.25rem; }
.doc-hint { margin: -0.35rem 0 1rem; color: #94a3b8; font-size: 0.82rem; }
.doc-field { display: grid; gap: 0.4rem; margin-bottom: 0.9rem; }
.doc-field label {
    color: #cbd5e1; font-size: 0.72rem; font-weight: 700;
    letter-spacing: 0.04em; text-transform: uppercase;
}
.doc-field label em { color: #f87171; font-style: normal; }
.doc-field input,
.doc-field select,
.doc-members-row input {
    width: 100%; min-height: 42px; padding: 0.65rem 0.8rem;
    border: 1px solid var(--doc-line); border-radius: 10px;
    background: var(--doc-input); color: #fff; font-size: 0.9rem; outline: none;
}
.doc-field select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%94a3b8' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.9rem center; padding-right: 2.2rem;
}
.doc-field input:focus,
.doc-field select:focus,
.doc-members-row input:focus {
    border-color: #a78bfa; box-shadow: 0 0 0 3px rgba(124,58,237,0.18);
}
.doc-grid-2 { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 0.9rem; }
.doc-field-half { max-width: 50%; }
.doc-members-table {
    overflow: hidden; border: 1px solid var(--doc-line); border-radius: 12px; background: var(--doc-panel);
}
.doc-members-head, .doc-members-row {
    display: grid; grid-template-columns: 36px 1fr 1.2fr 1.2fr 1fr; gap: 0.55rem; align-items: center;
}
.doc-members-head {
    padding: 0.7rem 0.75rem; background: #1f2430; color: #94a3b8;
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em;
}
.doc-members-row { padding: 0.55rem 0.75rem; border-top: 1px solid var(--doc-line); }
.doc-members-row > span { color: #a78bfa; font-weight: 800; text-align: center; }
.doc-upload-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 0.85rem; }
.doc-upload-card {
    display: grid; gap: 0.45rem; padding: 0.95rem;
    border: 1px dashed rgba(167,139,250,0.35); border-radius: 12px;
    background: var(--doc-panel); cursor: pointer;
}
.doc-upload-card strong { color: #fff; font-size: 0.88rem; }
.doc-upload-card small { color: #94a3b8; font-size: 0.78rem; line-height: 1.35; }
.doc-tag {
    display: inline-flex; width: fit-content; padding: 0.18rem 0.45rem; border-radius: 999px;
    font-size: 0.62rem; font-weight: 800; letter-spacing: 0.04em;
}
.doc-tag.required { color: #fecaca; background: rgba(239,68,68,0.16); }
.doc-tag.optional { color: #cbd5e1; background: rgba(148,163,184,0.16); }
.doc-upload-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
    min-height: 38px; margin-top: 0.25rem; border-radius: 9px;
    background: rgba(124,58,237,0.16); color: #c4b5fd; font-size: 0.78rem; font-weight: 700;
}
.doc-upload-card input { display: none; }
.doc-file-name { color: #64748b; font-size: 0.72rem; font-style: normal; }
.doc-check {
    display: flex; align-items: flex-start; gap: 0.7rem; margin-bottom: 1rem;
    color: #cbd5e1; font-size: 0.86rem; line-height: 1.45;
}
.doc-check input { margin-top: 0.2rem; accent-color: var(--doc-purple); }
.doc-signature-row {
    display: grid; grid-template-columns: minmax(0,1fr) 220px; gap: 1rem; align-items: start;
}
.doc-signature-pad-wrap {
    overflow: hidden; border: 1px solid var(--doc-line); border-radius: 12px; background: #0b0d11;
}
.doc-signature-label {
    display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
    padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--doc-line);
    color: #94a3b8; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
}
.doc-signature-label button {
    border: 0; background: transparent; color: #c4b5fd; font-size: 0.75rem; font-weight: 700; cursor: pointer;
}
#signaturePad { display: block; width: 100%; height: 180px; cursor: crosshair; touch-action: none; }
.doc-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem;
    min-height: 42px; padding: 0.55rem 1rem; border-radius: 10px;
    border: 1px solid transparent; font-size: 0.88rem; font-weight: 700;
    text-decoration: none; cursor: pointer;
}
.doc-btn-ghost { color: #e2e8f0; background: rgba(15,23,42,0.28); border-color: rgba(226,232,240,0.28); }
.doc-btn-muted { color: #e2e8f0; background: #2a303a; border-color: #3f4654; }
.doc-btn-purple { color: #fff; background: var(--doc-purple); border-color: var(--doc-purple); box-shadow: 0 8px 20px rgba(124,58,237,0.35); }
.doc-btn-purple-soft { color: #ede9fe; background: rgba(124,58,237,0.22); border-color: rgba(167,139,250,0.35); }
.doc-form-footer {
    display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 0.7rem;
    padding: 1rem 1.4rem 1.35rem;
}
/* ── Document attachment notice & validation UI ── */
.doc-attach-notice {
    display: flex; align-items: flex-start; gap: 0.75rem;
    padding: 0.85rem 1rem; margin-bottom: 1rem;
    border: 1px solid rgba(99,179,237,0.35); border-radius: 10px;
    background: rgba(99,179,237,0.08); color: #7dd3fc;
    font-size: 0.83rem; line-height: 1.5;
}
.doc-attach-notice i { margin-top: 0.1rem; flex-shrink: 0; font-size: 1rem; color: #38bdf8; }
.doc-attach-notice strong { display: block; margin-bottom: 0.2rem; font-weight: 700; color: #e0f2fe; font-size: 0.85rem; }
.doc-attach-notice em { font-style: normal; font-weight: 700; color: #fca5a5; }
.doc-attach-notice span { color: #94a3b8; }

.doc-missing-alert {
    display: flex; align-items: center; gap: 0.65rem;
    padding: 0.8rem 1rem; margin-bottom: 1rem;
    border: 1px solid rgba(239,68,68,0.35); border-radius: 10px;
    background: rgba(239,68,68,0.1); color: #fca5a5;
    font-size: 0.84rem; font-weight: 600;
}
.doc-missing-alert i { flex-shrink: 0; font-size: 1rem; }

/* Card state when required file is missing and submit was attempted */
.doc-upload-card--missing {
    border-color: rgba(239,68,68,0.65) !important;
    background: rgba(239,68,68,0.06) !important;
}
.doc-upload-card--missing .doc-upload-btn {
    background: rgba(239,68,68,0.18);
    color: #fca5a5;
}
.doc-upload-card--missing .doc-file-name {
    color: #f87171;
}

/* Submit button disabled state */
.doc-btn-purple:disabled,
.doc-btn-purple[aria-disabled="true"] {
    opacity: 0.5; cursor: not-allowed; box-shadow: none;
}

/* Light mode overrides for notice/alert */
[data-theme="light"] .doc-attach-notice {
    background: rgba(14,165,233,0.07); border-color: rgba(14,165,233,0.3); color: #0369a1;
}
[data-theme="light"] .doc-attach-notice strong { color: #0c4a6e; }
[data-theme="light"] .doc-attach-notice span { color: #475569; }
[data-theme="light"] .doc-attach-notice i { color: #0ea5e9; }
[data-theme="light"] .doc-missing-alert {
    background: rgba(239,68,68,0.07); border-color: rgba(239,68,68,0.3); color: #b91c1c;
}
[data-theme="light"] .doc-upload-card--missing {
    border-color: rgba(239,68,68,0.5) !important;
    background: rgba(239,68,68,0.04) !important;
}

@media (max-width: 991.98px) {
    .doc-grid-2, .doc-upload-grid, .doc-signature-row, .doc-members-head, .doc-members-row { grid-template-columns: 1fr; }
    .doc-field-half { max-width: none; }
    .doc-members-head { display: none; }
    .doc-members-row { gap: 0.45rem; }
}
@media (max-width: 767.98px) {
    .doc-vault-header, .doc-section-head, .doc-form-footer { flex-direction: column; align-items: stretch; }
    .doc-btn { width: 100%; }
}

/* Light mode support */
[data-theme="light"] .doc-vault-form {
    --doc-bg: #ffffff;
    --doc-panel: #f8fafc;
    --doc-input: #ffffff;
    --doc-line: #d7e1ef;
    background: #ffffff;
    border-color: #dbe3ef;
    color: #0f172a;
    box-shadow: 0 10px 28px rgba(15,33,88,0.08);
}
[data-theme="light"] .doc-vault-header {
    background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 48%, #6d28d9 100%);
}
[data-theme="light"] .doc-vault-kicker { color: #ede9fe; }
[data-theme="light"] .doc-vault-header h1 { color: #fff; }
[data-theme="light"] .doc-vault-header p { color: #ddd6fe; }
[data-theme="light"] .doc-section {
    border-bottom-color: #e2e8f0;
}
[data-theme="light"] .doc-section h2 { color: #0f172a; }
[data-theme="light"] .doc-section h2 small,
[data-theme="light"] .doc-hint,
[data-theme="light"] .doc-file-name { color: #64748b; }
[data-theme="light"] .doc-field label { color: #475569; }
[data-theme="light"] .doc-field input,
[data-theme="light"] .doc-field select,
[data-theme="light"] .doc-members-row input {
    background: #fff;
    color: #0f172a;
    border-color: #d7e1ef;
    color-scheme: light;
}
[data-theme="light"] .doc-members-table {
    background: #fff;
    border-color: #d7e1ef;
}
[data-theme="light"] .doc-members-head {
    background: #f1f5f9;
    color: #64748b;
}
[data-theme="light"] .doc-members-row {
    border-top-color: #e2e8f0;
}
[data-theme="light"] .doc-members-row > span { color: #7c3aed; }
[data-theme="light"] .doc-upload-card {
    background: #fff;
    border-color: #c4b5fd;
}
[data-theme="light"] .doc-upload-card strong { color: #0f172a; }
[data-theme="light"] .doc-upload-card small { color: #64748b; }
[data-theme="light"] .doc-tag.required {
    color: #b91c1c;
    background: rgba(239,68,68,0.1);
}
[data-theme="light"] .doc-tag.optional {
    color: #475569;
    background: rgba(100,116,139,0.12);
}
[data-theme="light"] .doc-upload-btn {
    background: rgba(124,58,237,0.1);
    color: #6d28d9;
}
[data-theme="light"] .doc-check { color: #334155; }
[data-theme="light"] .doc-signature-pad-wrap {
    background: #f8fafc;
    border-color: #d7e1ef;
}
[data-theme="light"] .doc-signature-label {
    color: #64748b;
    border-bottom-color: #e2e8f0;
}
[data-theme="light"] .doc-signature-label button { color: #6d28d9; }
[data-theme="light"] #signaturePad {
    background: #fff;
}
[data-theme="light"] .doc-btn-ghost {
    color: #334155;
    background: #fff;
    border-color: #cbd5e1;
}
[data-theme="light"] .doc-btn-muted {
    color: #334155;
    background: #f1f5f9;
    border-color: #cbd5e1;
}
[data-theme="light"] .doc-btn-purple-soft {
    color: #5b21b6;
    background: rgba(124,58,237,0.1);
    border-color: rgba(124,58,237,0.25);
}
[data-theme="light"] .doc-form-footer {
    background: #f8fafc;
}
</style>

<script>
(function () {
    var form = document.getElementById('docVaultForm');
    var canvas = document.getElementById('signaturePad');
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasStroke = false;

    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var rect = canvas.getBoundingClientRect();
        canvas.width = Math.floor(rect.width * ratio);
        canvas.height = Math.floor(180 * ratio);
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        var theme = document.documentElement.getAttribute('data-theme') || 'light';
        ctx.strokeStyle = theme === 'light' ? '#0f172a' : '#ffffff';
    }

    function pointerPos(event) {
        var rect = canvas.getBoundingClientRect();
        var point = event.touches ? event.touches[0] : event;
        return { x: point.clientX - rect.left, y: point.clientY - rect.top };
    }

    canvas.addEventListener('mousedown', function (event) {
        drawing = true;
        var pos = pointerPos(event);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    });
    canvas.addEventListener('mousemove', function (event) {
        if (!drawing) return;
        var pos = pointerPos(event);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        hasStroke = true;
    });
    ['mouseup', 'mouseleave'].forEach(function (name) {
        canvas.addEventListener(name, function () { drawing = false; });
    });
    canvas.addEventListener('touchstart', function (event) {
        event.preventDefault();
        drawing = true;
        var pos = pointerPos(event);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    }, { passive: false });
    canvas.addEventListener('touchmove', function (event) {
        event.preventDefault();
        if (!drawing) return;
        var pos = pointerPos(event);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        hasStroke = true;
    }, { passive: false });
    canvas.addEventListener('touchend', function () { drawing = false; });

    document.getElementById('clearPadBtn').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasStroke = false;
        document.getElementById('signatureData').value = '';
    });

    document.querySelectorAll('.doc-upload-card input[type="file"]').forEach(function (input) {
        input.addEventListener('change', function () {
            var card  = input.closest('.doc-upload-card');
            var label = card.querySelector('[data-file-label]');
            var hasFile = input.files && input.files[0];
            label.textContent = hasFile ? input.files[0].name : 'No file selected';
            // Clear the missing highlight once a file is chosen
            if (hasFile) {
                card.classList.remove('doc-upload-card--missing');
            }
            // Re-check overall completeness to update the notice
            checkRequiredDocs();
        });
    });

    // Returns array of required upload cards that have no file selected
    function getMissingRequiredCards() {
        var missing = [];
        document.querySelectorAll('.doc-upload-card input[type="file"][required]').forEach(function (input) {
            if (!input.files || !input.files[0]) {
                missing.push(input.closest('.doc-upload-card'));
            }
        });
        return missing;
    }

    function checkRequiredDocs() {
        var missing = getMissingRequiredCards();
        var notice  = document.getElementById('docAttachNotice');
        var alert   = document.getElementById('docMissingAlert');
        var btn     = document.getElementById('submitPacketBtn');

        if (missing.length === 0) {
            // All required docs uploaded — green state
            notice.style.borderColor  = 'rgba(16,185,129,0.4)';
            notice.style.background   = 'rgba(16,185,129,0.08)';
            notice.style.color        = '#6ee7b7';
            notice.querySelector('i').style.color    = '#34d399';
            notice.querySelector('strong').style.color = '#d1fae5';
            notice.querySelector('strong').textContent = 'All required documents uploaded — ready to submit.';
            notice.querySelector('span').textContent  = 'You may now proceed to sign and submit the form packet.';
            alert.style.display = 'none';
        } else {
            var n = missing.length;
            notice.style.borderColor  = '';
            notice.style.background   = '';
            notice.style.color        = '';
            notice.querySelector('i').style.color    = '';
            notice.querySelector('strong').style.color = '';
            notice.querySelector('strong').textContent = 'All required documents must be uploaded before submitting.';
            notice.querySelector('span').textContent  = 'Upload each file marked REQUIRED to enable the Submit Form Packet button. Allowed formats: PDF, DOCX, JPG, or PNG. Max 10MB per file.';
        }
    }

    // Run once on page load
    checkRequiredDocs();

    document.getElementById('autoFillRepBtn').addEventListener('click', function () {
        var ids = document.querySelectorAll('input[name="member_id[]"]');
        var names = document.querySelectorAll('input[name="member_name[]"]');
        var emails = document.querySelectorAll('input[name="member_email[]"]');
        var contacts = document.querySelectorAll('input[name="member_contact[]"]');
        document.getElementById('repId').value = ids[0].value;
        document.getElementById('repName').value = names[0].value;
        document.getElementById('repEmail').value = emails[0].value;
        document.getElementById('repContact').value = contacts[0].value;
    });

    document.getElementById('saveDraftBtn').addEventListener('click', function () {
        alert('Draft saved locally for this session.');
    });

    document.getElementById('submitPacketBtn').addEventListener('click', function () {
        // 1. Check required file uploads first
        var missingCards = getMissingRequiredCards();
        if (missingCards.length > 0) {
            // Highlight each missing card
            document.querySelectorAll('.doc-upload-card').forEach(function (c) {
                c.classList.remove('doc-upload-card--missing');
            });
            missingCards.forEach(function (card) {
                card.classList.add('doc-upload-card--missing');
            });
            // Show the red alert with count
            var alertEl  = document.getElementById('docMissingAlert');
            var alertTxt = document.getElementById('docMissingText');
            alertTxt.textContent = missingCards.length === 1
                ? '1 required document is still missing. Please upload it before submitting.'
                : missingCards.length + ' required documents are still missing. Please upload them before submitting.';
            alertEl.style.display = 'flex';
            // Scroll to the Document Attachments section
            alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // 2. Standard HTML5 form validation (text fields, etc.)
        if (!form.reportValidity()) return;

        // 3. Signature required
        if (!hasStroke) {
            alert('Please draw the representative signature before submitting.');
            return;
        }

        document.getElementById('signatureData').value = canvas.toDataURL('image/png');
        form.submit();
    });

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
})();
</script>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
