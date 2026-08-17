<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use InvalidArgumentException;

/** Per-response CSP contract for advanced Text previews rendered via srcdoc. */
final class BlogEditorPreviewSandboxPolicy
{
    private const NONCE_PATTERN =
        '/\A[A-Za-z0-9+\/_-]{16,128}={0,2}\z/D';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $styleNonce
    ) {
        if (preg_match(self::NONCE_PATTERN, $styleNonce) !== 1) {
            throw new InvalidArgumentException(
                'Invalid Blog editor preview style nonce.'
            );
        }
    }

    public static function random(): self
    {
        return new self(base64_encode(random_bytes(18)));
    }

    public function styleNonce(): string
    {
        return $this->styleNonce;
    }

    public function styleSource(): string
    {
        return "'nonce-" . $this->styleNonce . "'";
    }

    public function contentSecurityPolicy(): string
    {
        $styleSource = $this->styleSource();

        return "default-src 'none'; style-src " . $styleSource
            . '; style-src-elem ' . $styleSource
            . "; style-src-attr 'none'; img-src 'none'; "
            . "font-src 'none'; script-src 'none'; script-src-attr 'none'; "
            . "connect-src 'none'; frame-src 'none'; media-src 'none'; "
            . "worker-src 'none'; form-action 'none'; base-uri 'none'; "
            . "object-src 'none'";
    }
}
