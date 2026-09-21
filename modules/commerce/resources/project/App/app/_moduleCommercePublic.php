<?php

declare(strict_types=1);

use App\CommercePresentation\CommerceDevelopmentFixtureAdapter;
use App\CommercePresentation\CommerceCorePresentationAdapter;
use App\CommercePresentation\CommerceCatalogQuery;
use App\CommercePresentation\CommercePresentationAdapterInterface;
use App\CommercePresentation\CommercePublicItemPageAdapter;
use App\Core\Commerce\Http\CommercePublicItemPage;
use App\Core\Commerce\Http\CommercePublicHttpRuntimeFactory;
use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Modules\ModuleRuntimeContext;

/*
 * Managed bridge between canonical Commerce public DTOs and the project
 * shell. Catalog and inquiry surfaces receive `$commerceAdapter` from their
 * HTTP integration. Fixtures require two explicit development flags.
 */

require_once __DIR__ . '/commerce/CommercePresentationAdapter.php';

$commerceConfig = require __DIR__ . '/../config/modules/commerce.php';
if (($commerceConfig['public']['enabled'] ?? false) !== true) {
    throw new RuntimeException('The Commerce public surface is disabled.');
}

$commerceSurface = isset($commerceSurface) && is_string($commerceSurface)
    ? $commerceSurface
    : '';
if (
    $commerceSurface === 'item'
    && isset($commerceItemPage)
    && $commerceItemPage instanceof CommercePublicItemPage
) {
    $lang = $commerceItemPage->product()->requestedLocale();
}
$commerceLocale = isset($lang) && is_string($lang)
    ? strtolower(trim($lang))
    : '';
if (!array_key_exists($commerceLocale, $commerceConfig['public_paths'] ?? [])) {
    throw new RuntimeException('The Commerce locale is unavailable.');
}

$commerceCatalogPaths = [
    __DIR__ . "/../config/languages/global/{$commerceLocale}.json",
    __DIR__ . "/../config/languages/commerce/{$commerceLocale}.json",
];
foreach ($commerceCatalogPaths as $commerceCatalogPath) {
    $commerceCatalogContents = is_file($commerceCatalogPath)
        && !is_link($commerceCatalogPath)
        && is_readable($commerceCatalogPath)
            ? file_get_contents($commerceCatalogPath)
            : false;
    try {
        $commerceCatalogData = is_string($commerceCatalogContents)
            ? json_decode(
                $commerceCatalogContents,
                false,
                512,
                JSON_THROW_ON_ERROR
            )
            : null;
    } catch (Throwable) {
        $commerceCatalogData = null;
    }
    if (!is_object($commerceCatalogData)) {
        throw new RuntimeException(
            'A Commerce public language catalog is unavailable.'
        );
    }
    foreach (
        get_object_vars($commerceCatalogData)
        as $commerceKey => $commerceValue
    ) {
        if (is_string($commerceKey)) {
            $GLOBALS[$commerceKey] = $commerceValue;
        }
    }
}

$commerceText = static function (string $key): string {
    $entry = $GLOBALS[$key] ?? null;
    $value = is_object($entry)
        ? ($entry->text ?? null)
        : (is_array($entry) ? ($entry['text'] ?? null) : null);

    return is_string($value) ? trim($value) : '';
};
$commerceEnvFlag = static function (string $key): bool {
    $value = $_ENV[$key] ?? getenv($key);

    return $value === true || (
        is_string($value)
        && in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true)
    );
};
$commerceDevelopmentFixtures = $commerceEnvFlag('DEV_MODE')
    && $commerceEnvFlag('LIQUIDSTACK_COMMERCE_DEVELOPMENT_FIXTURES');
if (
    !isset($commerceAdapter)
    && !(
        $commerceSurface === 'item'
        && isset($commerceItemPage)
        && $commerceItemPage instanceof CommercePublicItemPage
    )
) {
    try {
        $commerceProjectRoot = dirname(__DIR__, 2);
        $commerceEnvironment = (new ProjectEnvironmentLoader())->load(
            $commerceProjectRoot
        );
        $commerceContext = new ModuleRuntimeContext(
            $commerceProjectRoot,
            $commerceEnvironment->values(),
            $commerceEnvironment->isUsable()
        );
        $commercePublicRuntime = (new CommercePublicHttpRuntimeFactory())
            ->create($commerceContext);
        $commerceBasketToken = null;
        $commerceCookieName = $commercePublicRuntime->basketCookieName();
        if ($commerceCookieName !== null) {
            $commerceCookieValue = $_COOKIE[$commerceCookieName] ?? null;
            $commerceBasketToken = is_string($commerceCookieValue)
                ? $commerceCookieValue
                : null;
        }
        $commerceAdapter = new CommerceCorePresentationAdapter(
            $commercePublicRuntime,
            $commerceText,
            $commerceBasketToken
        );
    } catch (Throwable $commerceRuntimeError) {
        if (!$commerceDevelopmentFixtures) {
            throw new RuntimeException(
                'The Commerce public runtime is unavailable.',
                0,
                $commerceRuntimeError
            );
        }
        $commerceAdapter = new CommerceDevelopmentFixtureAdapter(
            $commerceConfig,
            $commerceText
        );
    }
}
if (
    isset($commerceAdapter)
    && !$commerceAdapter instanceof CommercePresentationAdapterInterface
) {
    throw new RuntimeException(
        'The Commerce presentation adapter must implement its typed contract.'
    );
}

$commercePathCandidate = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
$commercePath = parse_url(
    is_string($commercePathCandidate) ? $commercePathCandidate : '/',
    PHP_URL_PATH
);
$commercePath = is_string($commercePath) && $commercePath !== ''
    ? $commercePath
    : '/';
$commerceCatalog = null;
$commerceItem = null;
$commerceInquiry = null;

if ($commerceSurface === 'catalog') {
    if (!isset($commerceAdapter)) {
        throw new RuntimeException('The Commerce catalog adapter is unavailable.');
    }
    $commerceCatalogQuery = CommerceCatalogQuery::fromInput($_GET);
    $commerceCatalog = $commerceAdapter->catalog(
        $commerceLocale,
        $commerceCatalogQuery
    );
} elseif ($commerceSurface === 'item') {
    if (
        isset($commerceItemPage)
        && $commerceItemPage instanceof CommercePublicItemPage
    ) {
        $commerceItem = CommercePublicItemPageAdapter::adapt(
            $commerceItemPage,
            $commerceText
        );
        $commercePath = $commerceItemPage->product()->publicPath()
            ?? $commercePath;
    } elseif (isset($commerceAdapter)) {
        $commerceItem = $commerceAdapter->item(
            $commerceLocale,
            $commercePath
        );
    } else {
        throw new RuntimeException('The Commerce item DTO is unavailable.');
    }
} elseif ($commerceSurface === 'inquiry') {
    if (!isset($commerceAdapter)) {
        throw new RuntimeException('The Commerce inquiry adapter is unavailable.');
    }
    $commerceRawItems = $_GET['items'] ?? '';
    if (is_string($commerceRawItems)) {
        $commerceRequestedItems = preg_split(
            '/\s*,\s*/',
            trim($commerceRawItems),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    } elseif (is_array($commerceRawItems)) {
        $commerceRequestedItems = array_values(array_filter(
            $commerceRawItems,
            static fn (mixed $value): bool => is_string($value)
        ));
    } else {
        $commerceRequestedItems = [];
    }
    $commerceInquiry = $commerceAdapter->inquiry(
        $commerceLocale,
        $commerceRequestedItems
    );
} else {
    throw new RuntimeException('Unknown Commerce public surface.');
}

$resources = match ($commerceSurface) {
    'catalog' => 'commerce',
    'item' => 'commerceItem',
    'inquiry' => 'commerceInquiry',
};
$content = 'commerce';
$url = $commercePath;
$GLOBALS['lang'] = $commerceLocale;
$GLOBALS['url'] = $url;
$GLOBALS['urlWithQuery'] = $url;
if (!$commerceEnvFlag('DEV_MODE')) {
    $commerceProjectRoot ??= dirname(__DIR__, 2);
    $commerceManifestPath = $commerceProjectRoot
        . '/public/.vite/manifest.json';
    try {
        $commerceManifest = json_decode(
            (string) file_get_contents($commerceManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        $commerceManifest = null;
    }
    $commerceBundle = is_array($commerceManifest)
        ? ($commerceManifest["src/js/{$resources}.js"] ?? null)
        : null;
    $commerceOrigin = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
    $commerceAsset = static function (mixed $asset, string $extension): ?string {
        return is_string($asset)
            && preg_match(
                '#\Aassets/(?:css|js)/[a-zA-Z0-9._-]+\.'
                    . preg_quote($extension, '#') . '\z#D',
                $asset
            ) === 1
                ? $asset
                : null;
    };
    $commerceJs = $commerceAsset(
        is_array($commerceBundle) ? ($commerceBundle['file'] ?? null) : null,
        'js'
    );
    $commerceCss = [];
    foreach (
        is_array($commerceBundle) && is_array($commerceBundle['css'] ?? null)
            ? $commerceBundle['css']
            : []
        as $commerceStylesheet
    ) {
        $commerceStylesheet = $commerceAsset($commerceStylesheet, 'css');
        if ($commerceStylesheet !== null) {
            $commerceCss[] = $commerceOrigin . '/' . $commerceStylesheet;
        }
    }
    if ($commerceJs === null || $commerceCss === []) {
        throw new RuntimeException('The Commerce frontend bundle is unavailable.');
    }
    $js = $commerceOrigin . '/' . $commerceJs;
    $css = $commerceCss;
}

$commerceMetaPrefix = 'commerce_' . $commerceSurface . '_meta_';
$commerceMetaTitle = $commerceText($commerceMetaPrefix . 'title');
$commerceMetaDescription = $commerceText(
    $commerceMetaPrefix . 'description'
);
if ($commerceSurface === 'item' && $commerceItem !== null) {
    $commerceProduct = $commerceItem->product();
    $commerceMetaTitle = isset($commerceItemPage)
        && $commerceItemPage instanceof CommercePublicItemPage
            ? ($commerceItemPage->product()->seoTitle()
                ?? $commerceProduct->title())
            : $commerceProduct->title();
    $commerceMetaDescription = isset($commerceItemPage)
        && $commerceItemPage instanceof CommercePublicItemPage
            ? ($commerceItemPage->product()->seoDescription()
                ?? $commerceProduct->summary()
                ?: $commerceProduct->description())
            : ($commerceProduct->summary() ?: $commerceProduct->description());
}
if ($commerceMetaTitle === '' || $commerceMetaDescription === '') {
    throw new RuntimeException('The Commerce metadata is unavailable.');
}
$title = (object) ['text' => $commerceMetaTitle];
$description = (object) ['content' => $commerceMetaDescription];
$robots = (object) [
    'content' => $commerceSurface === 'inquiry'
        ? 'noindex, follow'
        : 'index, follow',
];
$pageMeta = array_replace(
    is_array($pageMeta ?? null) ? $pageMeta : [],
    [
        'title' => $commerceMetaTitle,
        'description' => $commerceMetaDescription,
        'headline' => $commerceMetaTitle,
        'type' => 'website',
    ]
);
if (isset($commerceItemPage) && $commerceItemPage instanceof CommercePublicItemPage) {
    $commerceOrigin = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
    $commerceAlternates = [];
    foreach ($commerceItemPage->alternatePaths() as $locale => $path) {
        if (is_string($locale) && is_string($path)) {
            $commerceAlternates[$locale] = $commerceOrigin . $path;
        }
    }
    $robots->content = $commerceItemPage->robotsDirective();
    $pageMeta['canonical'] = $commerceItemPage->canonicalUrl();
    $pageMeta['alternates'] = $commerceAlternates;
    $languageAlternates = $commerceItemPage->alternatePaths();
}

unset(
    $commerceConfig,
    $commerceCatalogPath,
    $commerceCatalogPaths,
    $commerceCatalogContents,
    $commerceCatalogData,
    $commerceKey,
    $commerceValue,
    $commerceText,
    $commerceEnvFlag,
    $commerceDevelopmentFixtures,
    $commerceProjectRoot,
    $commerceEnvironment,
    $commerceContext,
    $commercePublicRuntime,
    $commerceCookieName,
    $commerceBasketToken,
    $commerceCookieValue,
    $commerceRuntimeError,
    $commerceCatalogQuery,
    $commerceLocale,
    $commercePathCandidate,
    $commercePath,
    $commerceRawItems,
    $commerceRequestedItems,
    $commerceMetaPrefix,
    $commerceMetaTitle,
    $commerceMetaDescription,
    $commerceProduct,
    $commerceOrigin,
    $commerceAlternates,
    $commerceManifestPath,
    $commerceManifest,
    $commerceBundle,
    $commerceAsset,
    $commerceJs,
    $commerceCss,
    $commerceStylesheet
);
