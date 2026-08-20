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
            // Direct database connection to sms2_db to avoid API/network issues
            $sms2_pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=sms2_db", "root", "");
            $sms2_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $sms2_pdo->prepare("
                SELECT 
                    id as student_id, 
                    student_id as student_number, 
                    full_name
                FROM users 
                WHERE role_key = 'student' AND student_id = :sn 
                LIMIT 1
            ");
            $stmt->execute([':sn' => $student_number]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                return null;
            }
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/sync_error.log', date('Y-m-d H:i:s') . ' DB Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            return null;
        }
        
        // 2. Sync to Local Reference Cache (payment_db.students)
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