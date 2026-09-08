<?php
/**
 * RegistrarStudentClient
 * Handles communication with the External SMS2 Registrar API.
 * Keeps the Payment Module decoupled from external database structures.
 */

class RegistrarStudentClient {
    private $apiUrl;
    private $pdo;

    public function __construct($pdo) {
        // Read from REGISTRAR_API_BASE_URL (defined in config or .env)
        if (defined('REGISTRAR_API_BASE_URL')) {
            $this->apiUrl = REGISTRAR_API_BASE_URL . "/students/search"; 
        } else {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
           $this->apiUrl = $protocol . "://" . $host . BASE_URL . "/modules/user-management/api/students.php"; 
        }
        
        $this->pdo = $pdo;
    }

    /**
     * Retrieves student info from Registrar API and syncs it to the local cache.
     * @param string $student_number
     * @return array|null
     */
    public function getAndSyncStudent($student_number) {
        $student_number = trim($student_number);
        if (empty($student_number)) return null;

        try {
            // The payment database cache is authoritative for payment modules.
                // students.student_number is the external-facing identifier while
            // students.student_id is the numeric key used by billing.
            $stmt = $this->pdo->prepare("
                SELECT 
                    student_id,
                    user_id,
                    student_number,
                    full_name,
                    course AS course_id,
                    year_level,
                    status
                FROM students
                WHERE LOWER(student_number) = LOWER(:student_number)
                   OR CAST(student_id AS CHAR) = :student_id
                LIMIT 1
            ");
            $stmt->execute([
                ':student_number' => $student_number,
                ':student_id' => $student_number,
            ]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            file_put_contents(
                __DIR__ . '/sync_error.log',
                date('Y-m-d H:i:s') . ' Student cache lookup failed: ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
            return null;
        }

        // A student can exist in users before the payment cache is populated.
        // In that case username is the student number in the shared users table.
        if (!$student) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT
                        id AS student_id,
                        id AS user_id,
                        username AS student_number,
                        full_name,
                        NULL AS course_id,
                        NULL AS year_level,
                        status
                    FROM users
                    WHERE role_key = 'student'
                      AND LOWER(username) = LOWER(:student_number)
                    LIMIT 1
                ");
                $stmt->execute([':student_number' => $student_number]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                file_put_contents(
                    __DIR__ . '/sync_error.log',
                    date('Y-m-d H:i:s') . ' Users fallback lookup failed: ' . $e->getMessage() . PHP_EOL,
                    FILE_APPEND
                );
                return null;
            }
        }

        if (!$student) {
            return null;
        }
        
        // 2. Sync to Local Reference Cache (students)
        $this->syncLocalReference($student);

        return $student;
    }

    private function syncLocalReference($student) {
        try {
            // Support both old API format (first_name/last_name) and new format (full_name)
            $fullName = isset($student['full_name']) 
                ? $student['full_name'] 
                : trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));

            if (empty(trim($fullName))) {
                $fullName = 'Unknown Student';
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO students (student_id, user_id, student_number, full_name, course, year_level, status)
                VALUES (:id, :uid, :sn, :name, :course, :yr, :status)
                ON DUPLICATE KEY UPDATE 
                    full_name = VALUES(full_name),
                    course = VALUES(course),
                    year_level = VALUES(year_level),
                    status = VALUES(status),
                    last_sync_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ':id' => $student['student_id'],
                ':uid' => $student['student_id'], // Fallback for legacy user_id
                ':sn' => $student['student_number'],
                ':name' => $fullName,
                ':course' => $student['course_id'] ?? 'Unknown',
                ':yr' => $student['year_level'] ?? '1',
                ':status' => $student['status'] ?? 'Enrolled'
            ]);
        } catch (Exception $e) {
            // Log to a file instead of error_log to avoid breaking JSON output in some environments
            file_put_contents(__DIR__ . '/sync_error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
}