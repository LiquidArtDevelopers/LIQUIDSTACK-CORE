<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;
use InvalidArgumentException;

final class BlogSitemapRenderer
{
    public const MAX_DOCUMENT_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly int $maxDocumentBytes = self::MAX_DOCUMENT_BYTES
    ) {
        if (
            $maxDocumentBytes < 1
            || $maxDocumentBytes > self::MAX_DOCUMENT_BYTES
        ) {
            throw new InvalidArgumentException(
                'The Blog sitemap byte limit is invalid.'
            );
        }
    }

    /** @param list<BlogSitemapEntry> $entries */
    public function render(
        array $entries,
        BlogConfig $config,
        BlogPublicOrigin $origin
    ): string {
        $urls = [];
        $groups = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof BlogSitemapEntry) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $base = $config->publicPath($entry->locale());
            if ($base === null) {
                continue;
            }
            $location = $origin->absoluteUrl(
                $base . '/' . $entry->slug()
            );
            $group = $entry->postPublicId() === null
                ? 'route:' . $entry->locale() . ':' . $entry->slug()
                : 'post:' . $entry->postPublicId();
            if (
                isset($groups[$group][$entry->locale()])
                || isset($urls[$location])
            ) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $groups[$group][$entry->locale()] = $location;
            $urls[$location] = [
                'location' => $location,
                'last_modified' => $entry->updatedAt()
                    ->format('Y-m-d\TH:i:s\Z'),
                'group' => $group,
            ];
        }
        ksort($urls, SORT_STRING);

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
                . 'xmlns:xhtml="http://www.w3.org/1999/xhtml">',
        ];
        foreach ($urls as $url) {
            $alternates = $this->orderedAlternates(
                $groups[$url['group']],
                $config
            );
            $xDefaultUrl = $alternates[$config->defaultLocale()]
                ?? reset($alternates);
            if (!is_string($xDefaultUrl)) {
                throw new BlogException(BlogException::INVALID_STATE);
            }
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $this->escape($url['location']) . '</loc>';
            foreach ($alternates as $locale => $alternateUrl) {
                $xml[] = '    <xhtml:link rel="alternate" hreflang="'
                    . $this->escape($locale) . '" href="'
                    . $this->escape($alternateUrl) . '" />';
            }
            $xml[] = '    <xhtml:link rel="alternate" hreflang="x-default" '
                . 'href="' . $this->escape($xDefaultUrl) . '" />';
            $xml[] = '    <lastmod>' . $url['last_modified'] . '</lastmod>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        $document = implode("\n", $xml) . "\n";
        if (strlen($document) > $this->maxDocumentBytes) {
            throw new BlogException(BlogException::SITEMAP_OVERFLOW);
        }

        return $document;
    }

    /**
     * @param array<string, string> $alternates
     * @return array<string, string>
     */
    private function orderedAlternates(
        array $alternates,
        BlogConfig $config
    ): array {
        $ordered = [];
        foreach ($config->publicPaths() as $locale => $_base) {
            if (isset($alternates[$locale])) {
                $ordered[$locale] = $alternates[$locale];
            }
        }

        return $ordered;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
