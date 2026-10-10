<?php
declare(strict_types=1);

final class ReceiptStorageException extends RuntimeException {}

final class PrivateReceiptStorageService
{
    public const PREFIX = 'private-receipt:';
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly ?string $configuredRoot = null) {}

    /** @return array{status:string,readable:bool,writable:bool,outside_application:bool} */
    public function configurationStatus(bool $testWrite = false): array
    {
        try {
            $root=$this->privateRoot(false);
            $writable=is_writable($root);
            if($testWrite&&$writable){
                $probe=$root.DIRECTORY_SEPARATOR.'.sms2-ocr-write-probe-'.bin2hex(random_bytes(8));
                $written=@file_put_contents($probe,'probe',LOCK_EX);
                $writable=$written===5&&is_file($probe);
                if(is_file($probe))@unlink($probe);
            }
            return ['status'=>$writable?'CONFIGURED':'NOT_WRITABLE','readable'=>is_readable($root),'writable'=>$writable,'outside_application'=>true];
        } catch(Throwable) {
            return ['status'=>'NOT_CONFIGURED','readable'=>false,'writable'=>false,'outside_application'=>false];
        }
    }

    /** @param array<string,mixed> $upload */
    public function store(array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ReceiptStorageException('RECEIPT_UPLOAD_FAILED');
        $temporary = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES || !is_uploaded_file($temporary)) throw new ReceiptStorageException('RECEIPT_SIZE_INVALID');

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) throw new ReceiptStorageException('RECEIPT_TYPE_INVALID');
        $imageInfo = @getimagesize($temporary);
        if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== $mime || $imageInfo[0] < 1 || $imageInfo[1] < 1) {
            throw new ReceiptStorageException('RECEIPT_IMAGE_INVALID');
        }
        $bytes = file_get_contents($temporary);
        if ($bytes === false || (function_exists('imagecreatefromstring') && @imagecreatefromstring($bytes) === false)) {
            throw new ReceiptStorageException('RECEIPT_IMAGE_INVALID');
        }

        $root = $this->privateRoot(true);
        $name = bin2hex(random_bytes(24)) . '.' . self::MIME_EXTENSIONS[$mime];
        $destination = $root . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($temporary, $destination)) throw new ReceiptStorageException('RECEIPT_STORE_FAILED');
        @chmod($destination, 0640);
        return self::PREFIX . $name;
    }

    /** @return array{path:string,mime:string,extension:string,legacy:bool} */
    public function resolve(string $reference): array
    {
        if (str_starts_with($reference, self::PREFIX)) {
            $name = substr($reference, strlen(self::PREFIX));
            if (!preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp)$/', $name)) throw new ReceiptStorageException('RECEIPT_REFERENCE_INVALID');
            $root = $this->privateRoot(false);
            $path = realpath($root . DIRECTORY_SEPARATOR . $name);
            if ($path === false || !$this->isWithin($path, $root) || !is_file($path) || !is_readable($path)) throw new ReceiptStorageException('RECEIPT_NOT_FOUND');
            return $this->resolved($path, false);
        }

        // Read-only compatibility for receipts created before private storage.
        $legacyRoot = realpath(ROOT_PATH . '/uploads/receipts');
        $candidate = realpath(ROOT_PATH . '/' . ltrim(str_replace('\\', '/', $reference), '/'));
        if ($legacyRoot === false || $candidate === false || !$this->isWithin($candidate, $legacyRoot) || !is_file($candidate)) {
            throw new ReceiptStorageException('RECEIPT_NOT_FOUND');
        }
        return $this->resolved($candidate, true);
    }

    public function discardNew(string $reference): void
    {
        if(!str_starts_with($reference,self::PREFIX))return;
        try{$resolved=$this->resolve($reference);if(!$resolved['legacy'])@unlink($resolved['path']);}catch(Throwable){}
    }

    private function privateRoot(bool $create): string
    {
        $configured = trim((string) ($this->configuredRoot ?? getenv('PAYMENT_PRIVATE_RECEIPT_ROOT')));
        if ($configured === '' || preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)~', $configured) !== 1) {
            throw new ReceiptStorageException('PRIVATE_RECEIPT_STORAGE_NOT_CONFIGURED');
        }
        if ($this->isWithin($configured, ROOT_PATH) || $this->samePath($configured, ROOT_PATH)) {
            throw new ReceiptStorageException('PRIVATE_RECEIPT_STORAGE_UNSAFE');
        }
        if ($create && !is_dir($configured) && !mkdir($configured, 0750, true)) throw new ReceiptStorageException('PRIVATE_RECEIPT_STORAGE_UNAVAILABLE');
        $root = realpath($configured);
        if ($root === false || !is_dir($root) || !is_readable($root) || ($create && !is_writable($root))) {
            throw new ReceiptStorageException('PRIVATE_RECEIPT_STORAGE_UNAVAILABLE');
        }
        $applicationRoot=realpath(ROOT_PATH)?:ROOT_PATH;
        if($this->isWithin($root,$applicationRoot)||$this->samePath($root,$applicationRoot))throw new ReceiptStorageException('PRIVATE_RECEIPT_STORAGE_UNSAFE');
        return $root;
    }

    /** @return array{path:string,mime:string,extension:string,legacy:bool} */
    private function resolved(string $path, bool $legacy): array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime]) || @getimagesize($path) === false) throw new ReceiptStorageException('RECEIPT_IMAGE_INVALID');
        return ['path' => $path, 'mime' => $mime, 'extension' => self::MIME_EXTENSIONS[$mime], 'legacy' => $legacy];
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
        return str_starts_with($path, $root . '/');
    }

    private function samePath(string $a, string $b): bool
    {
        return rtrim(strtolower(str_replace('\\', '/', $a)), '/') === rtrim(strtolower(str_replace('\\', '/', $b)), '/');
    }
}
