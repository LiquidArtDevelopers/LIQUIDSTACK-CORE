<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogInput;
use App\Core\Blog\BlogPostVariant;
use App\Core\Modules\Migrations\MigrationScope;
use PDO;
use PDOStatement;
use Throwable;

/** Read-only same-locale published inventory for canibalization checks. */
final class PdoBlogSeoCandidateRepository implements
    BlogSeoCandidateRepositoryInterface
{
    private readonly string $posts;
    private readonly string $localizations;

    /** @param array<string, string> $publicPaths */
    public function __construct(
        private readonly PDO $pdo,
        MigrationScope $scope,
        private readonly array $publicPaths = []
    ) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver) || !in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException('Unsupported Blog SEO storage.');
        }
        $this->posts = $scope->quotedTable('posts', $driver);
        $this->localizations = $scope->quotedTable(
            'post_localizations',
            $driver
        );
        foreach ($this->publicPaths as $locale => $path) {
            if (
                !is_string($locale)
                || !is_string($path)
                || !str_starts_with($path, '/')
                || str_starts_with($path, '//')
            ) {
                throw new \RuntimeException('Invalid Blog SEO public paths.');
            }
        }
    }

    public function publishedCandidates(
        string $locale,
        string $exceptPostPublicId,
        int $limit
    ): BlogSeoCandidateScan {
        BlogInput::locale($locale);
        BlogInput::publicId($exceptPostPublicId);
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException(
                'Invalid Blog SEO candidate limit.'
            );
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT p.public_id AS post_public_id, l.slug, l.h1, '
                . 'l.seo_title FROM ' . $this->posts . ' p JOIN '
                . $this->localizations . ' l ON l.post_id = p.id '
                . 'WHERE l.locale = :locale AND l.status = :status '
                . 'AND l.published_at IS NOT NULL AND l.slug IS NOT NULL '
                . 'AND p.public_id <> :except_post ORDER BY l.updated_at DESC, '
                . 'l.public_id ASC LIMIT :candidate_limit'
            );
            if (
                !$statement instanceof PDOStatement
                || !$statement->bindValue(':locale', $locale, PDO::PARAM_STR)
                || !$statement->bindValue(
                    ':status',
                    BlogPostVariant::PUBLISHED,
                    PDO::PARAM_STR
                )
                || !$statement->bindValue(
                    ':except_post',
                    $exceptPostPublicId,
                    PDO::PARAM_STR
                )
                || !$statement->bindValue(
                    ':candidate_limit',
                    $limit + 1,
                    PDO::PARAM_INT
                )
                || !$statement->execute()
            ) {
                throw new \RuntimeException('Blog SEO query failed.');
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                throw new \RuntimeException('Blog SEO query failed.');
            }
            $complete = count($rows) <= $limit;
            $result = [];
            foreach (array_slice($rows, 0, $limit) as $row) {
                if (
                    !is_array($row)
                    || !is_string($row['post_public_id'] ?? null)
                    || !is_string($row['slug'] ?? null)
                    || !is_string($row['h1'] ?? null)
                    || (!is_null($row['seo_title'] ?? null)
                        && !is_string($row['seo_title']))
                ) {
                    throw new \RuntimeException('Invalid Blog SEO row.');
                }
                $result[] = new BlogSeoCompetingPage(
                    BlogSeoCompetingPage::BLOG,
                    $locale,
                    rtrim($this->publicPaths[$locale] ?? '', '/')
                        . '/' . $row['slug'],
                    $row['h1'],
                    $row['seo_title'],
                    $row['post_public_id']
                );
            }

            return new BlogSeoCandidateScan($result, $complete);
        } catch (Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException) {
                throw $exception;
            }
            throw new \RuntimeException(
                'Blog SEO candidates are unavailable.',
                0,
                $exception
            );
        }
    }
}
