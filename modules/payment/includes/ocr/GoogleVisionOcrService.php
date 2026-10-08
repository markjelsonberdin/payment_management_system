<?php
declare(strict_types=1);

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Auth\Credentials\ServiceAccountCredentials;
require_once __DIR__ . '/OcrTextProviderInterface.php';

final class GoogleVisionOcrException extends RuntimeException
{
    public function __construct(public readonly string $category, ?Throwable $previous = null)
    {
        parent::__construct($category, 0, $previous);
    }
}

final class GoogleVisionOcrService implements OcrTextProviderInterface
{
    public const FEATURE = 'DOCUMENT_TEXT_DETECTION';

    /** @return array{status:string,path_configured:bool,readable:bool,project_configured:bool,protected_location:bool} */
    public function configurationStatus(): array
    {
        $credential = trim((string) getenv('GOOGLE_APPLICATION_CREDENTIALS'));
        $project = trim((string) getenv('GOOGLE_CLOUD_PROJECT'));
        $inline = $this->decodeInlineCredential($credential);
        $safe = $credential !== '' && $this->isAbsolutePath($credential) && !$this->isInsidePublicApplication($credential);
        $readable = $inline !== null || ($safe && is_file($credential) && is_readable($credential));
        return [
            'status' => $readable && $project !== '' ? 'CONFIGURED' : 'NOT_CONFIGURED',
            'path_configured' => $credential !== '',
            'readable' => $readable,
            'project_configured' => $project !== '',
            'protected_location' => $inline !== null || $safe,
        ];
    }

    /** Non-billable OAuth token acquisition; never calls Vision document detection. */
    /** @return array{status:string,authenticated:bool,error_category:string} */
    public function nonBillableAuthenticationStatus(): array
    {
        try {
            $decoded = $this->resolveCredential();
            if ($decoded === null) return ['status'=>'NOT_CONFIGURED','authenticated'=>false,'error_category'=>'CONFIGURATION_ERROR'];
            $credentials = new ServiceAccountCredentials(['https://www.googleapis.com/auth/cloud-platform'], $decoded);
            $token = $credentials->fetchAuthToken();
            $authenticated = is_array($token) && isset($token['access_token']) && is_string($token['access_token']) && $token['access_token'] !== '';
            return ['status'=>$authenticated?'AUTHENTICATED':'FAILED','authenticated'=>$authenticated,'error_category'=>$authenticated?'NONE':'AUTHENTICATION_FAILED'];
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $category = str_contains($message, 'timeout') || str_contains($message, 'deadline')
                ? 'TIMEOUT'
                : (str_contains($message, 'network') || str_contains($message, 'connect')
                    ? 'NETWORK_ERROR'
                    : 'AUTHENTICATION_FAILED');
            return ['status'=>'FAILED','authenticated'=>false,'error_category'=>$category];
        }
    }

    public function projectIdentityMatches(string $expectedProject): bool
    {
        $expectedProject = trim($expectedProject);
        $environmentProject = trim((string) getenv('GOOGLE_CLOUD_PROJECT'));
        if ($expectedProject === '' || $environmentProject === '' || !hash_equals($expectedProject, $environmentProject)) return false;
        $decoded = $this->resolveCredential();
        $credentialProject = is_array($decoded) ? trim((string) ($decoded['project_id'] ?? '')) : '';
        return $credentialProject !== '' && hash_equals($expectedProject, $credentialProject);
    }

    /** @return array{raw_text:?string,provider:string,feature:string} */
    public function extractDocumentText(string $imageBytes): array
    {
        if ($imageBytes === '') throw new GoogleVisionOcrException('INVALID_IMAGE');
        $status = $this->configurationStatus();
        if ($status['status'] !== 'CONFIGURED') throw new GoogleVisionOcrException('CONFIGURATION_ERROR');
        try {
            $credential = trim((string) getenv('GOOGLE_APPLICATION_CREDENTIALS'));
            $inline = $this->decodeInlineCredential($credential);
            $options = $inline === null ? [] : ['credentials' => $inline];
            $client = new ImageAnnotatorClient($options);
            try {
                $response = $client->documentTextDetection($imageBytes);
                $error = $response->getError();
                if ($error && $error->getCode() !== 0) throw new GoogleVisionOcrException('PROVIDER_ERROR');
                $annotation = $response->getFullTextAnnotation();
                $text = $annotation ? trim((string) $annotation->getText()) : '';
                return ['raw_text' => $text !== '' ? $text : null, 'provider' => 'google_cloud_vision', 'feature' => self::FEATURE];
            } finally {
                $client->close();
            }
        } catch (GoogleVisionOcrException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $category = str_contains($message, 'credential') || str_contains($message, 'unauth') ? 'AUTHENTICATION_FAILED'
                : (str_contains($message, 'disabled') || str_contains($message, 'permission') ? 'API_DISABLED'
                : (str_contains($message, 'timeout') || str_contains($message, 'deadline') ? 'TIMEOUT' : 'NETWORK_OR_PROVIDER_ERROR'));
            throw new GoogleVisionOcrException($category, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    private function resolveCredential(): ?array
    {
        $credential = trim((string) getenv('GOOGLE_APPLICATION_CREDENTIALS'));
        $decoded = $this->decodeInlineCredential($credential);
        if ($decoded !== null) return $decoded;
        if (!$this->isAbsolutePath($credential) || $this->isInsidePublicApplication($credential) || !is_readable($credential)) return null;
        $json = file_get_contents($credential);
        return is_string($json) ? $this->decodeInlineCredential($json) : null;
    }

    private function isInsidePublicApplication(string $path): bool
    {
        $root = realpath(ROOT_PATH) ?: ROOT_PATH;
        $candidate = realpath($path) ?: $path;
        return str_starts_with(strtolower(str_replace('\\', '/', $candidate)), rtrim(strtolower(str_replace('\\', '/', $root)), '/') . '/');
    }

    /** @return array<string,mixed>|null */
    private function decodeInlineCredential(string $credential): ?array
    {
        if ($credential === '' || !str_starts_with(ltrim($credential), '{')) return null;
        try {
            $decoded = json_decode($credential, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) return null;
        foreach (['type', 'project_id', 'private_key', 'client_email'] as $key) {
            if (!isset($decoded[$key]) || !is_string($decoded[$key]) || trim($decoded[$key]) === '') return null;
        }
        return $decoded['type'] === 'service_account' ? $decoded : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }
}
