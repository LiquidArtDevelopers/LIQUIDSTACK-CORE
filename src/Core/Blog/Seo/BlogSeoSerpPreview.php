<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

final class BlogSeoSerpPreview
{
    public function __construct(
        private readonly string $locale,
        private readonly string $title,
        private readonly string $url,
        private readonly string $description
    ) {
        if (
            preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/', $locale) !== 1
            || preg_match('//u', $title . $url . $description) !== 1
            || !str_starts_with($url, '/')
            || str_starts_with($url, '//')
        ) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO SERP preview.'
            );
        }
    }

    /** @return array{locale:string,title:string,url:string,description:string} */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->description,
        ];
    }
}
