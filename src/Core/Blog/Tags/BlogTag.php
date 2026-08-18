<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

/** Administrative tag projection; consumers must not expose its identifiers. */
final class BlogTag
{
    private readonly string $publicId;
    private readonly string $locale;
    private readonly string $slug;
    private readonly string $name;
    private readonly string $normalizedSha256;

    public function __construct(
        string $publicId,
        string $locale,
        string $slug,
        string $name,
        string $normalizedSha256
    ) {
        $this->publicId = BlogTagInput::publicId($publicId);
        $this->locale = BlogTagInput::locale($locale);
        $this->slug = BlogTagInput::slug($slug);
        $this->name = BlogTagInput::name($name);
        $this->normalizedSha256 = BlogTagInput::normalizedSha256(
            $normalizedSha256
        );
    }

    public function publicId(): string { return $this->publicId; }
    public function locale(): string { return $this->locale; }
    public function slug(): string { return $this->slug; }
    public function name(): string { return $this->name; }
    public function normalizedSha256(): string
    {
        return $this->normalizedSha256;
    }

    /** @return array{locale: string, slug: string, name: string} */
    public function toPresentationArray(): array
    {
        return [
            'locale' => $this->locale,
            'slug' => $this->slug,
            'name' => $this->name,
        ];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'public_id' => $this->publicId,
            'locale' => $this->locale,
            'slug' => '[redacted]',
            'name' => '[redacted]',
            'normalized_sha256' => '[redacted]',
        ];
    }
}
