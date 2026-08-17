<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogPublicArticleShellContext;
use App\Core\Blog\Http\BlogPublicArticleViewModel;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Environment\ProjectRuntimeProfile;
use App\Core\Support\Paths;

/*
 * Managed one-require bootstrap for a project-owned public Blog article.
 *
 * The module controller owns HTTP and security. This adapter only prepares
 * the established LiquidStack view variables, catalog and project assets.
 * It must run before the DOCTYPE and must never emit headers or HTML.
 */

if (
    !isset($blogArticle)
    || !$blogArticle instanceof BlogPublicArticleViewModel
    || !isset($blogArticleShell)
    || !$blogArticleShell instanceof BlogPublicArticleShellContext
) {
    throw new RuntimeException('The public Blog article is unavailable.');
}

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$lang = $blogArticle->locale();
$canonicalUrl = $blogArticle->canonicalUrl();
$canonicalPath = parse_url($canonicalUrl, PHP_URL_PATH);
$url = is_string($canonicalPath) && $canonicalPath !== ''
    ? $canonicalPath
    : '/';
$resources = 'blogArticle';
$content = 'blog-article';

$globalCatalogPath = Paths::appPath()
    . "/config/languages/global/{$lang}.json";
$globalCatalogContents = is_file($globalCatalogPath)
    && !is_link($globalCatalogPath)
    && is_readable($globalCatalogPath)
        ? file_get_contents($globalCatalogPath)
        : false;
if (!is_string($globalCatalogContents)) {
    throw new RuntimeException(
        'The public Blog article catalog is unavailable.'
    );
}
try {
    $globalCatalog = json_decode(
        $globalCatalogContents,
        false,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable) {
    throw new RuntimeException(
        'The public Blog article catalog is unavailable.'
    );
}
if (!is_object($globalCatalog)) {
    throw new RuntimeException(
        'The public Blog article catalog is unavailable.'
    );
}
foreach (get_object_vars($globalCatalog) as $key => $value) {
    if (is_string($key)) {
        $GLOBALS[$key] = $value;
    }
}

$articleCatalogText = static function (string $key): string {
    $entry = $GLOBALS[$key] ?? null;
    $value = is_object($entry)
        ? ($entry->text ?? null)
        : (is_array($entry) ? ($entry['text'] ?? null) : null);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(
            "The Blog article catalog key {$key}.text is unavailable."
        );
    }

    return $value;
};

$GLOBALS['lang'] = $lang;
$GLOBALS['url'] = $url;
$GLOBALS['urlWithQuery'] = $url;

$title = (object) ['text' => $blogArticle->seoTitle()];
$description = (object) ['content' => $blogArticle->metaDescription()];
$robots = (object) ['content' => $blogArticle->robotsDirective()];
$GLOBALS['title'] = $title;
$GLOBALS['description'] = $description;
$GLOBALS['robots'] = $robots;

$languageAlternates = $blogArticle->languageNavigationUrls();
$pageMeta = [
    'title' => $blogArticle->seoTitle(),
    'description' => $blogArticle->metaDescription(),
    'headline' => $blogArticle->h1(),
    'canonical' => $canonicalUrl,
    'alternates' => $blogArticle->alternateUrls(),
    'x_default' => $blogArticle->xDefaultUrl(),
    'type' => 'article',
    'image' => $blogArticle->coverImageUrl(),
    'published_at' => $blogArticle->publishedAt()->format(DATE_ATOM),
    'updated_at' => $blogArticle->updatedAt()->format(DATE_ATOM),
];

$projectRoot = Paths::projectRoot();
$environment = (new ProjectEnvironmentLoader())->load($projectRoot);
if (!$environment->isUsable()) {
    throw new RuntimeException(
        'The public Blog article environment is unavailable.'
    );
}
try {
    $runtimeProfile = ProjectRuntimeProfile::fromEnvironment(
        $environment->values()
    );
} catch (Throwable) {
    throw new RuntimeException(
        'The public Blog article environment is unavailable.'
    );
}
$devMode = $runtimeProfile->isDevelopmentLoopbackHttp();
$cspNonce = $blogArticleShell->nonce();

if (!$devMode) {
    $manifestPath = Paths::publicPath() . '/.vite/manifest.json';
    $manifestContents = is_file($manifestPath)
        && !is_link($manifestPath)
        && is_readable($manifestPath)
            ? file_get_contents($manifestPath)
            : false;
    try {
        $manifest = is_string($manifestContents)
            ? json_decode(
                $manifestContents,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
            : null;
    } catch (Throwable) {
        $manifest = null;
    }
    $entry = is_array($manifest)
        ? ($manifest['src/js/blogArticle.js'] ?? null)
        : null;
    $entryCssFiles = is_array($entry) && is_array($entry['css'] ?? null)
        ? $entry['css']
        : null;
    $entryJs = is_array($entry) ? ($entry['file'] ?? null) : null;
    $validAsset = static function (
        mixed $asset,
        string $directory
    ): ?string {
        if (
            !is_string($asset)
            || preg_match(
                '#\Aassets/' . preg_quote($directory, '#')
                    . '/[a-zA-Z0-9._-]+\.(?:css|js)\z#D',
                $asset
            ) !== 1
            || !is_file(Paths::publicPath() . '/' . $asset)
        ) {
            return null;
        }

        return $asset;
    };
    $entryCss = [];
    foreach ($entryCssFiles ?? [] as $entryCssFile) {
        $validatedCss = $validAsset($entryCssFile, 'css');
        if (
            $validatedCss === null
            || in_array($validatedCss, $entryCss, true)
        ) {
            throw new RuntimeException(
                'The public Blog article bundle is unavailable.'
            );
        }
        $entryCss[] = $validatedCss;
    }
    $entryJs = $validAsset($entryJs, 'js');
    if ($entryCss === [] || $entryJs === null) {
        throw new RuntimeException(
            'The public Blog article bundle is unavailable.'
        );
    }
    $assetRoot = $runtimeProfile->origin();
    $css = array_map(
        static fn (string $asset): string => $assetRoot . '/' . $asset,
        $entryCss
    );
    $js = $assetRoot . '/' . $entryJs;
}

$blogPublicRuntimeUrl = $blogArticleShell->publicRuntimeUrl();
if (!is_file(Paths::publicPath() . $blogPublicRuntimeUrl)) {
    throw new RuntimeException('The public Blog runtime is unavailable.');
}

$lastPathSeparator = strrpos($url, '/');
$indexPath = $lastPathSeparator === false || $lastPathSeparator === 0
    ? '/'
    : substr($url, 0, $lastPathSeparator);
$articleTemplate = $blogArticle->template();
$articleModifier = BlogDocumentTemplateRegistry::hasCover($articleTemplate)
    ? 'cover'
    : 'basic';
$articleMain = $blogArticle->mainHtml();
$articleHero = $blogArticle->headerHtml();
$articleCustomCss = $blogArticle->customCss();
$relatedArticles = $blogArticle->relatedArticles();

unset(
    $globalCatalogPath,
    $globalCatalogContents,
    $globalCatalog,
    $projectRoot,
    $environment,
    $runtimeProfile,
    $canonicalPath,
    $lastPathSeparator,
    $articleTemplate
);
