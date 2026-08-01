<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogSitemapEntry;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Configuration\BlogPublicOrigin;

final class BlogSitemapRenderer
{
    /** @param list<BlogSitemapEntry> $entries */
    public function render(
        array $entries,
        BlogConfig $config,
        BlogPublicOrigin $origin
    ): string {
        $urls = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof BlogSitemapEntry) {
                throw new BlogException(BlogException::INVALID_INPUT);
            }
            $base = $config->publicPath($entry->locale());
            if ($base === null) {
                throw new BlogException(BlogException::STORAGE_UNAVAILABLE);
            }
            $location = $origin->absoluteUrl(
                $base . '/' . $entry->slug()
            );
            $urls[$location] = [
                'location' => $location,
                'last_modified' => $entry->updatedAt()
                    ->format('Y-m-d\TH:i:s\Z'),
            ];
        }
        ksort($urls, SORT_STRING);

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];
        foreach ($urls as $url) {
            $xml[] = '  <url><loc>' . $this->escape($url['location'])
                . '</loc><lastmod>' . $url['last_modified']
                . '</lastmod></url>';
        }
        $xml[] = '</urlset>';

        return implode("\n", $xml) . "\n";
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
