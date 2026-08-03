<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use JsonException;
use RuntimeException;
use Throwable;

/** Optional project-owned canonical inventory; never writes project files. */
final class BlogSeoStaticPageInventory
{
    public const PROJECT_PATH = 'App/config/seo/canonical-pages.json';
    public const SCHEMA = 'liquidstack.seo.canonical-pages';
    public const MAX_BYTES = 1_048_576;
    public const MAX_PAGES = 2_000;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /** @return list<BlogSeoCompetingPage> */
    public function candidates(string $locale): array
    {
        $path = rtrim($this->projectRoot, '/\\') . '/' . self::PROJECT_PATH;
        if (!file_exists($path) && !is_link($path)) {
            return [];
        }
        if (
            !is_file($path)
            || is_link($path)
            || !is_readable($path)
            || filesize($path) === false
            || filesize($path) > self::MAX_BYTES
        ) {
            throw new RuntimeException('Invalid Blog SEO static inventory.');
        }
        try {
            $json = file_get_contents($path);
            if (!is_string($json) || strlen($json) > self::MAX_BYTES) {
                throw new RuntimeException(
                    'Invalid Blog SEO static inventory.'
                );
            }
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (
                !is_array($data)
                || !$this->hasExactKeys(
                    $data,
                    ['schema', 'version', 'pages']
                )
                || $data['schema'] !== self::SCHEMA
                || $data['version'] !== 1
                || !is_array($data['pages'])
                || !array_is_list($data['pages'])
                || count($data['pages']) > self::MAX_PAGES
            ) {
                throw new RuntimeException(
                    'Invalid Blog SEO static inventory.'
                );
            }
            $result = [];
            $seen = [];
            foreach ($data['pages'] as $page) {
                if (
                    !is_array($page)
                    || !$this->hasExactKeys(
                        $page,
                        ['locale', 'url', 'h1', 'seo_title']
                    )
                    || !is_string($page['locale'])
                    || !is_string($page['url'])
                    || !is_string($page['h1'])
                    || (!is_null($page['seo_title'])
                        && !is_string($page['seo_title']))
                ) {
                    throw new RuntimeException(
                        'Invalid Blog SEO static inventory.'
                    );
                }
                $key = $page['locale'] . "\0" . $page['url'];
                if (isset($seen[$key])) {
                    throw new RuntimeException(
                        'Duplicate Blog SEO static inventory URL.'
                    );
                }
                $seen[$key] = true;
                if ($page['locale'] !== $locale) {
                    continue;
                }
                $result[] = new BlogSeoCompetingPage(
                    BlogSeoCompetingPage::STATIC_PAGE,
                    $page['locale'],
                    $page['url'],
                    $page['h1'],
                    $page['seo_title']
                );
            }

            return $result;
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Invalid Blog SEO static inventory JSON.',
                0,
                $exception
            );
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Blog SEO static inventory is unavailable.',
                0,
                $exception
            );
        }
    }

    /** @param array<mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        if (!array_is_list($actual)) {
            return false;
        }
        foreach ($actual as $key) {
            if (!is_string($key)) {
                return false;
            }
        }
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
