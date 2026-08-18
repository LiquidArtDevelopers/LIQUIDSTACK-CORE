<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Tags\BlogTagService;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use InvalidArgumentException;

/** SSR recovery surface used when native tag assignment cannot complete. */
final class BlogTagAdminHtmlRenderer
{
    private readonly WebAdminShellRenderer $shellRenderer;

    public function __construct(?WebAdminShellRenderer $shellRenderer = null)
    {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
    }

    public function assignmentFailure(
        string $basePath,
        string $csrf,
        string $postPublicId,
        string $locale,
        int $lockVersion,
        int $tagWorkspaceVersion,
        string $submittedCsv,
        string $message,
        bool $retryAllowed,
        ?WebAdminShellContext $shell = null
    ): string {
        if (
            $lockVersion < 1
            || $tagWorkspaceVersion < 0
            || strlen($submittedCsv)
                > BlogTagAdminRequestPolicy::MAX_TRANSPORT_CSV_BYTES
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog tag assignment recovery state.'
            );
        }
        $editorUrl = $this->editorUrl($basePath, $postPublicId, $locale);
        $field = '<label for="blog-tag-recovery-csv">Etiquetas</label>'
            . '<input id="blog-tag-recovery-csv" type="text" '
            . 'dir="auto" '
            . ($retryAllowed
                ? 'name="tags" '
                : 'readonly aria-readonly="true" ')
            . 'value="' . $this->escape($submittedCsv) . '" '
            . 'maxlength="' . BlogTagService::MAX_CSV_BYTES . '" '
            . 'aria-describedby="blog-tag-recovery-help">'
            . '<p id="blog-tag-recovery-help">Hasta '
            . BlogTagService::MAX_TAGS_PER_VARIANT
            . ' etiquetas separadas por comas. No se ha descartado el texto '
            . 'enviado.</p>';
        if ($retryAllowed) {
            $field = '<form method="post" action="'
                . $this->path($basePath . '/assign') . '">'
                . $this->hidden('csrf', $csrf)
                . $this->hidden('post', $postPublicId)
                . $this->hidden('locale', $locale)
                . $this->hidden('lock_version', (string) $lockVersion)
                . $this->hidden(
                    'tag_workspace_version',
                    (string) $tagWorkspaceVersion
                )
                . $field
                . '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Reintentar guardado</button></form>';
        }

        $body = '<article class="blogAdminPage blogAdminPage--tagRecovery" '
            . 'aria-labelledby="blog-tag-recovery-title">'
            . '<h1 id="blog-tag-recovery-title">Revisar etiquetas</h1>'
            . '<p role="alert">' . $this->escape($message) . '</p>'
            . $field . '<p><a href="' . $editorUrl
            . '">Volver al editor</a></p></article>';
        $shell ??= new WebAdminShellContext(
            basePath: $this->webAdminBasePath($basePath),
            logoutCsrf: null,
            activePath: '/blog/editor',
            assets: new WebAdminPageAssets([
                '/assets/modules/blog/blog-admin.css',
            ], [
                '/assets/modules/blog/blog-editor.js',
            ])
        );

        return $this->shellRenderer->render(
            'Revisar etiquetas',
            $body,
            $shell
        );
    }

    public function editorUrl(
        string $basePath,
        string $postPublicId,
        string $locale
    ): string {
        $blogPath = substr(rtrim($basePath, '/'), 0, -strlen('/tags'));
        if ($blogPath === '' || !str_ends_with($basePath, '/blog/tags')) {
            throw new InvalidArgumentException(
                'Invalid Blog tag administration base path.'
            );
        }

        return $this->escape($blogPath . '/editor?'
            . http_build_query([
                'post' => $postPublicId,
                'locale' => $locale,
            ], '', '&', PHP_QUERY_RFC3986)
            . '#blog-editor-tags-title');
    }

    private function webAdminBasePath(string $basePath): string
    {
        $normalized = rtrim($basePath, '/');
        $suffix = '/blog/tags';
        if (!str_ends_with($normalized, $suffix)) {
            throw new InvalidArgumentException(
                'Invalid Blog tag administration base path.'
            );
        }
        $webAdmin = substr($normalized, 0, -strlen($suffix));

        return $webAdmin === '' ? '/' : $webAdmin;
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . $this->escape($name)
            . '" value="' . $this->escape($value) . '">';
    }

    private function path(string $path): string
    {
        return $this->escape(rtrim($path, '/'));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
