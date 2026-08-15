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
        // If not yet defined by the Registrar module, fallback to their expected endpoint route
        if (defined('REGISTRAR_API_BASE_URL')) {
            $this->apiUrl = REGISTRAR_API_BASE_URL . "/students/search"; // Adjust endpoint as necessary
        } else {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $this->apiUrl = $protocol . "://" . $host . BASE_URL . "/api/registrar/students"; 
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

        // 1. Fetch from External API
        $url = $this->apiUrl . "?student_number=" . urlencode($student_number);
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $response = @file_get_contents($url, false, $context);

        if (!$response) return null;

        $data = json_decode($response, true);
        if (!isset($data['success']) || $data['success'] !== true) {
            return null;
        }

        $student = $data['data'];
        
        // 2. Sync to Local Reference Cache (payment_db.students)
        $this->syncLocalReference($student);

        return $student;
    }

    private function syncLocalReference($student) {
        try {
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
                ':name' => trim($student['first_name'] . ' ' . $student['last_name']),
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
