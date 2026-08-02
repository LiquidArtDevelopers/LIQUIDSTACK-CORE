<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Media;

use App\Core\Blog\BlogInput;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use Throwable;

/** Content-safe media catalog projection for the Blog editor. */
final class BlogEditorMediaAsset
{
    private readonly string $publicId;
    private readonly string $label;

    public function __construct(string $publicId, string $label)
    {
        try {
            $this->publicId = BlogInput::generatedPublicId($publicId);
            $label = trim($label);
            if (
                $label === ''
                || strlen($label) > 480
                || preg_match('//u', $label) !== 1
                || preg_match('/[\x00-\x1F\x7F<>]/u', $label) === 1
            ) {
                throw new \InvalidArgumentException('Invalid media label.');
            }
            $this->label = $label;
        } catch (Throwable) {
            throw new BlogStructuredContentException(
                BlogStructuredContentException::MEDIA_UNAVAILABLE
            );
        }
    }

    public function publicId(): string
    {
        return $this->publicId;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'public_id' => $this->publicId,
            'label' => '[redacted]',
        ];
    }
}
