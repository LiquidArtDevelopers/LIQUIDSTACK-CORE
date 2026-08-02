<?php

declare(strict_types=1);

namespace App\Core\Http;

use InvalidArgumentException;
use LogicException;

/**
 * Immutable, filename-free projection of one successfully received upload.
 *
 * Browser supplied names and MIME values deliberately never cross this
 * boundary. Consumers must sniff the temporary file and choose their own
 * opaque storage key.
 */
final class UploadedFile
{
    private function __construct(
        private readonly string $temporaryPath,
        private readonly int $size
    ) {
    }

    /** @param array<string, mixed> $entry */
    public static function fromGlobal(array $entry): ?self
    {
        return self::fromPhpFileEntry($entry, true);
    }

    /**
     * Deterministic factory for Request::fromInput() and isolated tests. It
     * still requires a real regular temporary file, but not PHP's SAPI upload
     * marker, which cannot be produced outside an HTTP request.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromTestInput(array $entry): ?self
    {
        return self::fromPhpFileEntry($entry, false);
    }

    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** @return array{temporary_path: string, size: int} */
    public function __debugInfo(): array
    {
        return [
            'temporary_path' => '[redacted]',
            'size' => $this->size,
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('Uploaded files cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Uploaded files cannot be unserialized.');
    }

    /** @param array<string, mixed> $entry */
    private static function fromPhpFileEntry(
        array $entry,
        bool $requireHttpUpload
    ): ?self {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            if (!array_key_exists($key, $entry)) {
                throw new InvalidArgumentException('Invalid upload envelope.');
            }
        }
        foreach ($entry as $key => $value) {
            if (!is_string($key) || is_array($value) || is_object($value)) {
                throw new InvalidArgumentException('Invalid upload envelope.');
            }
        }

        $error = $entry['error'];
        if (!is_int($error) && !(is_string($error)
            && preg_match('/\A[0-9]+\z/', $error) === 1)) {
            throw new InvalidArgumentException('Invalid upload status.');
        }
        $error = (int) $error;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload was not completed.');
        }

        $path = $entry['tmp_name'];
        $reportedSize = $entry['size'];
        if (
            !is_string($path)
            || $path === ''
            || str_contains($path, "\0")
            || (!is_int($reportedSize) && !(is_string($reportedSize)
                && preg_match('/\A[0-9]+\z/', $reportedSize) === 1))
        ) {
            throw new InvalidArgumentException('Invalid upload metadata.');
        }
        $reportedSize = (int) $reportedSize;
        if (
            $reportedSize < 1
            || $reportedSize > Request::MAX_UPLOAD_FILE_BYTES
            || !is_file($path)
            || is_link($path)
            || !is_readable($path)
            || ($requireHttpUpload && !is_uploaded_file($path))
        ) {
            throw new InvalidArgumentException('Invalid upload temporary file.');
        }
        $actualSize = filesize($path);
        if (!is_int($actualSize) || $actualSize !== $reportedSize) {
            throw new InvalidArgumentException('Upload size mismatch.');
        }

        return new self($path, $actualSize);
    }
}
