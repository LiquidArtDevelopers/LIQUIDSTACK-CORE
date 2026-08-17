<?php

declare(strict_types=1);

use App\Core\Blog\PublicIndex\BlogPublicIndexBootstrap;
use App\Core\Blog\PublicIndex\BlogPublicIndexBootstrapOptions;
use App\Core\Blog\PublicIndex\BlogPublicIndexDefaultSecurityPolicy;
use App\Core\Blog\PublicIndex\BlogPublicIndexInput;
use App\Core\Blog\PublicIndex\BlogPublicIndexTextCatalog;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Support\Paths;

/*
 * Managed one-require bootstrap for a project-owned Blog index view.
 *
 * After this file returns, `$blogIndex` is the renderable typed page and the
 * HTTP, CSP and SEO variables expected by the project shell are ready. A
 * redirect or HEAD response is emitted and terminated here, before the view
 * can reach its DOCTYPE. Optional project behavior lives only in:
 *
 * App/config/modules/blog-public-index.php
 */

if (headers_sent()) {
    throw new RuntimeException(
        'The Blog public-index bootstrap must run before output.'
    );
}

$blogPublicProjectRoot = Paths::projectRoot();
$blogPublicEnvironment = (new ProjectEnvironmentLoader())->load(
    $blogPublicProjectRoot
);
$blogPublicOptions = BlogPublicIndexBootstrapOptions::fromProject(
    $blogPublicProjectRoot,
    BlogPublicIndexDefaultSecurityPolicy::fromEnvironment(
        $blogPublicEnvironment->values(),
        $blogPublicEnvironment->isUsable()
    )
);
$blogPublicLocale = $lang ?? null;
$blogPublicRouteParameters = is_array($GLOBALS['routeParams'] ?? null)
    ? $GLOBALS['routeParams']
    : [];
if (!is_string($blogPublicLocale)) {
    throw new RuntimeException(
        'The Blog public-index locale is unavailable.'
    );
}

$blogPublicResult = BlogPublicIndexBootstrap::current()->resolve(
    new BlogPublicIndexInput(
        $blogPublicLocale,
        $_GET,
        $blogPublicRouteParameters,
        $_SERVER
    ),
    BlogPublicIndexTextCatalog::fromGlobals($GLOBALS),
    $blogPublicOptions
);
$blogIndex = $blogPublicResult->page();

if ($blogIndex !== null) {
    if (!isset($robots) || !is_object($robots)) {
        $robots = new stdClass();
    }
    $robots->content = $blogIndex->robotsDirective();
    $pageMeta = array_replace(
        is_array($pageMeta ?? null) ? $pageMeta : [],
        $blogIndex->pageMeta()
    );
    $languageAlternates = $blogIndex->languageNavigationUrls();
    $cspNonce = $blogPublicResult->security()->nonce();
}

$blogPublicResult->response()->emit();
if ($blogPublicResult->shouldTerminate()) {
    exit;
}
if ($blogIndex === null) {
    throw new RuntimeException(
        'The Blog public-index bootstrap returned no renderable page.'
    );
}

unset(
    $blogPublicProjectRoot,
    $blogPublicEnvironment,
    $blogPublicOptions,
    $blogPublicLocale,
    $blogPublicRouteParameters,
    $blogPublicResult
);
