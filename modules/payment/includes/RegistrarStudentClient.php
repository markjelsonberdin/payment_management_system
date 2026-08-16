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

        // 1. Fetch from External API using cURL (UPGRADED)
        $url = $this->apiUrl . "?student_number=" . urlencode($student_number);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Timeout handling: 5 seconds to connect, 10 seconds total execution
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        // (Optional) Kung nagte-test ka sa localhost na walang valid SSL certificate, i-uncomment ito:
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);

        // Kapag nag-fail ang cURL request o hindi 200 OK (hal. 404 Not Found, 500 Server Error)
        if ($response === false || $httpCode >= 400) {
            $errorMsg = $response === false ? $curlError : "HTTP Status $httpCode";
            file_put_contents(__DIR__ . '/sync_error.log', date('Y-m-d H:i:s') . ' API Error: ' . $errorMsg . PHP_EOL, FILE_APPEND);
            
            // FALLBACK TO LOCAL CACHE IF API IS OFFLINE
            $stmt = $this->pdo->prepare("SELECT * FROM students WHERE student_number = :sn LIMIT 1");
            $stmt->execute([':sn' => $student_number]);
            $localStudent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($localStudent) {
                // Return in the format expected by the system
                return [
                    'student_id' => $localStudent['student_id'],
                    'student_number' => $localStudent['student_number'],
                    'full_name' => $localStudent['full_name'],
                    'course_id' => $localStudent['course'],
                    'year_level' => $localStudent['year_level'],
                    'status' => $localStudent['status']
                ];
            }
            
            return null;
        }

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