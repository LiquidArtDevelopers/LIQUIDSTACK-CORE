<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    public const MAX_BODY_BYTES = 1_048_576;
    public const MAX_UPLOAD_FILE_BYTES = 12_582_912;
    public const MAX_MULTIPART_BODY_BYTES = 13_631_488;
    public const MAX_INPUT_ITEMS = 256;
    public const MAX_INPUT_DEPTH = 4;
    public const MAX_INPUT_VALUE_BYTES = 8_192;
    public const MAX_FORM_VALUE_BYTES = self::MAX_BODY_BYTES;
    public const MAX_HEADER_COUNT = 100;
    public const MAX_HEADER_VALUE_BYTES = 8_192;
    public const MAX_COOKIE_COUNT = 64;
    public const MAX_COOKIE_VALUE_BYTES = 4_096;
    public const MAX_PATH_BYTES = 2_048;

    /**
     * @param array<string|int, mixed> $query
     * @param array<string|int, mixed> $form
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     * @param array<string, UploadedFile> $uploadedFiles
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly bool $methodValid,
        private readonly bool $pathValid,
        private readonly array $query,
        private readonly bool $queryValid,
        private readonly array $form,
        private readonly bool $formValid,
        private readonly array $cookies,
        private readonly bool $cookiesValid,
        private readonly array $headers,
        private readonly bool $headersValid,
        private readonly string $body,
        private readonly bool $bodyValid,
        private readonly array $uploadedFiles,
        private readonly bool $uploadedFilesValid,
        private readonly bool $multipartFormData,
        private readonly bool $secureTransport,
        private readonly ?string $clientIp
    ) {
    }

    /**
     * Backwards-compatible request factory for callers that only need routing.
     * It never reads superglobals or php://input.
     *
     * @param array<string, mixed> $server
     */
    public static function fromServer(array $server): self
    {
        return self::fromInput($server);
    }

    /**
     * Builds the runtime request once, before module dispatch. The body read is
     * bounded and skipped when Content-Length already exceeds the limit.
     */
    public static function fromGlobals(): self
    {
        $body = '';
        $bodyReadable = true;
        $isMultipart = self::rawContentTypeIsMultipart(
            $_SERVER['CONTENT_TYPE'] ?? null
        );
        $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        $declaredTooLarge = is_scalar($declaredLength)
            && preg_match('/\A[0-9]+\z/', (string) $declaredLength) === 1
            && (int) $declaredLength > ($isMultipart
                ? self::MAX_MULTIPART_BODY_BYTES
                : self::MAX_BODY_BYTES);

        if (!$isMultipart && !$declaredTooLarge) {
            $read = @file_get_contents(
                'php://input',
                false,
                null,
                0,
                self::MAX_BODY_BYTES + 1
            );
            if ($read === false) {
                $bodyReadable = false;
            } else {
                $body = $read;
            }
        }

        return self::build(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            [],
            $body,
            $bodyReadable,
            $_FILES,
            true
        );
    }

    /**
     * Deterministic factory for tests and non-global runtimes.
     *
     * Explicit headers override equivalent server-derived headers, while an
     * invalid value in either source still makes the request invalid.
     *
     * @param array<string, mixed> $server
     * @param array<string|int, mixed> $query
     * @param array<string|int, mixed> $form
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $files one flat PHP-style $_FILES map
     */
    public static function fromInput(
        array $server,
        array $query = [],
        array $form = [],
        array $cookies = [],
        array $headers = [],
        string $body = '',
        array $files = []
    ): self {
        return self::build(
            $server,
            $query,
            $form,
            $cookies,
            $headers,
            $body,
            true,
            $files,
            false
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function hasValidMethod(): bool
    {
        return $this->methodValid;
    }

    public function hasValidPath(): bool
    {
        return $this->pathValid;
    }

    public function hasValidQuery(): bool
    {
        return $this->queryValid;
    }

    public function hasValidForm(): bool
    {
        return $this->formValid;
    }

    public function hasValidCookies(): bool
    {
        return $this->cookiesValid;
    }

    public function hasValidHeaders(): bool
    {
        return $this->headersValid;
    }

    public function hasValidBody(): bool
    {
        return $this->bodyValid;
    }

    public function hasValidUploadedFiles(): bool
    {
        return $this->uploadedFilesValid;
    }

    /** @return array<string, UploadedFile> */
    public function uploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function uploadedFile(string $field): ?UploadedFile
    {
        return $this->uploadedFiles[$field] ?? null;
    }

    public function isMultipartFormData(): bool
    {
        return $this->multipartFormData;
    }

    public function isValid(): bool
    {
        return $this->methodValid
            && $this->pathValid
            && $this->queryValid
            && $this->formValid
            && $this->cookiesValid
            && $this->headersValid
            && $this->bodyValid
            && $this->uploadedFilesValid;
    }

    /** @return array<string|int, mixed> */
    public function queryParams(): array
    {
        return $this->query;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string|int, mixed> */
    public function formParams(): array
    {
        return $this->form;
    }

    public function form(string $key, mixed $default = null): mixed
    {
        return $this->form[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function bodySize(): int
    {
        return strlen($this->body);
    }

    /**
     * Reports only transport state asserted by the local web server. Forwarded
     * scheme headers are deliberately ignored until a trusted-proxy policy has
     * authenticated the immediate peer.
     */
    public function isSecureTransport(): bool
    {
        return $this->secureTransport;
    }

    /**
     * Returns only REMOTE_ADDR. Forwarded headers are deliberately ignored
     * until a project has an explicit trusted-proxy contract.
     */
    public function clientIp(): ?string
    {
        return $this->clientIp;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string|int, mixed> $query
     * @param array<string|int, mixed> $form
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $explicitHeaders
     * @param array<string, mixed> $files
     */
    private static function build(
        array $server,
        array $query,
        array $form,
        array $cookies,
        array $explicitHeaders,
        string $body,
        bool $bodyReadable,
        array $files,
        bool $requireHttpUpload
    ): self {
        [$method, $methodValid] = self::normalizeMethod($server);
        [$path, $pathValid] = self::normalizePath($server);
        [$query, $queryValid] = self::normalizeInput(
            $query,
            self::MAX_INPUT_VALUE_BYTES
        );
        [$form, $formValid] = self::normalizeInput(
            $form,
            self::MAX_FORM_VALUE_BYTES
        );

        [$serverHeaders, $serverHeadersValid] = self::normalizeHeaders(
            self::extractServerHeaders($server)
        );
        [$providedHeaders, $providedHeadersValid] = self::normalizeHeaders(
            $explicitHeaders
        );
        $headers = array_replace($serverHeaders, $providedHeaders);
        $headersValid = $serverHeadersValid
            && $providedHeadersValid
            && count($headers) <= self::MAX_HEADER_COUNT;
        if (!$headersValid) {
            $headers = [];
        }
        $isMultipart = self::contentTypeIsMultipart(
            $headers['content-type'] ?? null
        );

        $rawCookieHeader = $headers['cookie']
            ?? (is_string($server['HTTP_COOKIE'] ?? null)
                ? $server['HTTP_COOKIE']
                : '');
        [$parsedCookies, $rawCookiesValid] = self::parseCookieHeader(
            $rawCookieHeader
        );
        if ($cookies === [] && $rawCookieHeader !== '') {
            $cookies = $parsedCookies;
        }
        [$cookies, $cookiesValid] = self::normalizeCookies($cookies);
        $cookiesValid = $cookiesValid && $rawCookiesValid;
        if (!$cookiesValid) {
            $cookies = [];
        }

        $bodyLimit = $isMultipart
            ? self::MAX_MULTIPART_BODY_BYTES
            : self::MAX_BODY_BYTES;
        $bodyValid = $bodyReadable
            && strlen($body) <= $bodyLimit
            && self::declaredBodyLengthIsValid($server, $bodyLimit);
        if (!$bodyValid) {
            $body = '';
        }
        [$uploadedFiles, $uploadedFilesValid] = self::normalizeUploadedFiles(
            $files,
            $isMultipart,
            $requireHttpUpload
        );

        return new self(
            $method,
            $path,
            $methodValid,
            $pathValid,
            $query,
            $queryValid,
            $form,
            $formValid,
            $cookies,
            $cookiesValid,
            $headers,
            $headersValid,
            $body,
            $bodyValid,
            $uploadedFiles,
            $uploadedFilesValid,
            $isMultipart,
            self::secureTransportFromServer($server),
            self::clientIpFromServer($server)
        );
    }

    /**
     * @param array<string, mixed> $server
     * @return array{0: string, 1: bool}
     */
    private static function normalizeMethod(array $server): array
    {
        $raw = $server['REQUEST_METHOD'] ?? 'GET';
        if (!is_scalar($raw)) {
            return ['', false];
        }

        $method = strtoupper(trim((string) $raw));
        if ($method === '') {
            $method = 'GET';
        }

        return [
            $method,
            strlen($method) <= 16
                && preg_match('/\A[A-Z]+\z/', $method) === 1,
        ];
    }

    /**
     * @param array<string, mixed> $server
     * @return array{0: string, 1: bool}
     */
    private static function normalizePath(array $server): array
    {
        $rawUri = $server['REQUEST_URI'] ?? '/';
        if (!is_string($rawUri)) {
            return ['/', false];
        }

        $rawPath = parse_url($rawUri, PHP_URL_PATH);
        if (!is_string($rawPath) || $rawPath === '') {
            return ['/', false];
        }

        $valid = str_starts_with($rawUri, '/')
            && !str_starts_with($rawUri, '//')
            && strlen($rawPath) <= self::MAX_PATH_BYTES
            && preg_match('/%(?![0-9A-Fa-f]{2})/', $rawPath) !== 1
            && preg_match('/%(?:2f|5c)/i', $rawPath) !== 1;

        $path = rawurldecode($rawPath);
        $valid = $valid
            && strlen($path) <= self::MAX_PATH_BYTES
            && str_starts_with($path, '/')
            && preg_match('/%(?:2f|5c)/i', $path) !== 1
            && !str_contains($path, "\\")
            && !str_contains($path, '?')
            && !str_contains($path, '#')
            && !str_contains($path, '//')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
            && preg_match('#(?:^|/)\.\.?($|/)#', $path) !== 1
            && preg_match('//u', $path) === 1;

        return [
            $valid ? $path : self::invalidRoutingPath($path),
            $valid,
        ];
    }

    private static function invalidRoutingPath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            return '/';
        }

        /*
         * Preserve the apparent prefix so an invalid /admin request remains
         * inside the module's fail-closed 404 instead of falling through to a
         * legacy route. Handlers still cannot run because pathValid is false.
         */
        $path = str_replace('\\', '/', $path);
        $path = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $path);
        if (strlen($path) > self::MAX_PATH_BYTES) {
            $path = substr($path, 0, self::MAX_PATH_BYTES);
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @param array<string|int, mixed> $input
     * @return array{0: array<string|int, mixed>, 1: bool}
     */
    private static function normalizeInput(
        array $input,
        int $maxValueBytes
    ): array
    {
        $items = 0;
        $valid = self::validateInputLevel(
            $input,
            1,
            $items,
            $maxValueBytes
        );

        return [$valid ? $input : [], $valid];
    }

    /** @param array<string|int, mixed> $input */
    private static function validateInputLevel(
        array $input,
        int $depth,
        int &$items,
        int $maxValueBytes
    ): bool {
        if ($depth > self::MAX_INPUT_DEPTH) {
            return false;
        }

        foreach ($input as $key => $value) {
            ++$items;
            if ($items > self::MAX_INPUT_ITEMS) {
                return false;
            }
            if (
                is_string($key)
                && (
                    strlen($key) > 128
                    || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
                )
            ) {
                return false;
            }

            if (is_array($value)) {
                if (!self::validateInputLevel(
                    $value,
                    $depth + 1,
                    $items,
                    $maxValueBytes
                )) {
                    return false;
                }
                continue;
            }

            if (
                !is_string($value)
                || strlen($value) > $maxValueBytes
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $server */
    private static function extractServerHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
            } elseif (in_array($key, [
                'CONTENT_TYPE',
                'CONTENT_LENGTH',
                'CONTENT_MD5',
            ], true)) {
                $name = str_replace('_', '-', $key);
            } else {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        $count = 0;
        foreach ($headers as $name => $value) {
            if (
                !is_string($name)
                || preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1
            ) {
                return [[], false];
            }

            $values = is_array($value) ? $value : [$value];
            if ($values === [] || !array_is_list($values)) {
                return [[], false];
            }

            $clean = [];
            foreach ($values as $item) {
                ++$count;
                if (
                    $count > self::MAX_HEADER_COUNT
                    || !is_string($item)
                    || strlen($item) > self::MAX_HEADER_VALUE_BYTES
                    || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $item) === 1
                ) {
                    return [[], false];
                }
                $clean[] = trim($item);
            }

            $normalized[strtolower($name)] = implode(', ', $clean);
        }

        return [$normalized, true];
    }

    /**
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function parseCookieHeader(string $header): array
    {
        if ($header === '') {
            return [[], true];
        }
        if (strlen($header) > self::MAX_HEADER_VALUE_BYTES) {
            return [[], false];
        }

        $cookies = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $separator = strpos($part, '=');
            if ($separator === false) {
                return [[], false];
            }

            $name = trim(substr($part, 0, $separator));
            $value = urldecode(trim(substr($part, $separator + 1)));
            if (array_key_exists($name, $cookies)) {
                return [[], false];
            }
            $cookies[$name] = $value;
        }

        return self::normalizeCookies($cookies);
    }

    /**
     * @param array<string, mixed> $cookies
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function normalizeCookies(array $cookies): array
    {
        if (count($cookies) > self::MAX_COOKIE_COUNT) {
            return [[], false];
        }

        $normalized = [];
        foreach ($cookies as $name => $value) {
            if (
                !is_string($name)
                || preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1
                || !is_string($value)
                || strlen($value) > self::MAX_COOKIE_VALUE_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                return [[], false];
            }
            $normalized[$name] = $value;
        }

        return [$normalized, true];
    }

    /** @param array<string, mixed> $server */
    private static function declaredBodyLengthIsValid(
        array $server,
        int $maximum
    ): bool
    {
        if (!array_key_exists('CONTENT_LENGTH', $server)) {
            return true;
        }

        $value = $server['CONTENT_LENGTH'];

        return is_scalar($value)
            && preg_match('/\A[0-9]+\z/', (string) $value) === 1
            && (int) $value <= $maximum;
    }

    /**
     * @param array<string, mixed> $files
     * @return array{0: array<string, UploadedFile>, 1: bool}
     */
    private static function normalizeUploadedFiles(
        array $files,
        bool $isMultipart,
        bool $requireHttpUpload
    ): array {
        if (!$isMultipart) {
            return [$files === [] ? [] : [], $files === []];
        }
        if (count($files) > 1) {
            return [[], false];
        }

        $normalized = [];
        try {
            foreach ($files as $field => $entry) {
                if (
                    !is_string($field)
                    || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $field) !== 1
                    || !is_array($entry)
                ) {
                    return [[], false];
                }
                $file = $requireHttpUpload
                    ? UploadedFile::fromGlobal($entry)
                    : UploadedFile::fromTestInput($entry);
                if ($file instanceof UploadedFile) {
                    $normalized[$field] = $file;
                }
            }
        } catch (\Throwable) {
            return [[], false];
        }

        return [$normalized, true];
    }

    private static function rawContentTypeIsMultipart(mixed $value): bool
    {
        return is_string($value) && self::contentTypeIsMultipart($value);
    }

    private static function contentTypeIsMultipart(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return preg_match(
            '/\Amultipart\/form-data\s*;\s*boundary=(?:'
                . '"[A-Za-z0-9\'()+_,.\/:=? -]{1,70}"'
                . '|[A-Za-z0-9\'()+_,.\/:=?-]{1,70})\s*\z/i',
            trim($value)
        ) === 1;
    }

    /** @param array<string, mixed> $server */
    private static function clientIpFromServer(array $server): ?string
    {
        $value = $server['REMOTE_ADDR'] ?? null;
        if (
            !is_string($value)
            || strlen($value) > 45
            || filter_var($value, FILTER_VALIDATE_IP) === false
        ) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $server */
    private static function secureTransportFromServer(array $server): bool
    {
        $https = $server['HTTPS'] ?? null;
        if (
            is_scalar($https)
            && in_array(
                strtolower(trim((string) $https)),
                ['1', 'on', 'true'],
                true
            )
        ) {
            return true;
        }

        $scheme = $server['REQUEST_SCHEME'] ?? null;
        if (
            is_string($scheme)
            && strtolower(trim($scheme)) === 'https'
        ) {
            return true;
        }

        $port = $server['SERVER_PORT'] ?? null;

        return is_scalar($port)
            && preg_match('/\A[0-9]{1,5}\z/', (string) $port) === 1
            && (int) $port === 443;
    }
}
