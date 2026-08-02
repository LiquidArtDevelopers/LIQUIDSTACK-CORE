<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TranslationHrefContractTest extends TestCase
{
    public function testLanguagePreferenceRequiresExplicitCustomConsent(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $module = dirname(__DIR__, 2)
            . '/resources/js/_languagePreference.mjs';
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const preference = await import(pathToFileURL(process.argv[1]).href);
const writes = [];
const deletions = [];
const cookie = (consent) => ({
  getCookie(name) {
    if (name !== 'cookie_custom') throw new Error('unexpected cookie');
    return consent;
  },
  setCookie(...args) { writes.push(args); },
  deleteCookie(name) { deletions.push(name); },
});

const result = {
  missing: preference.persistLanguagePreference(undefined, 'en'),
  denied: preference.persistLanguagePreference(cookie('false'), 'en'),
  absent: preference.persistLanguagePreference(cookie(''), 'en'),
  accepted: preference.persistLanguagePreference(cookie('true'), 'en'),
  brokenRead: preference.persistLanguagePreference({
    getCookie() { throw new Error('CookieLAD failed'); },
    setCookie(...args) { writes.push(args); },
  }, 'eu'),
  brokenWrite: preference.persistLanguagePreference({
    getCookie() { return 'true'; },
    setCookie() { throw new Error('CookieLAD failed'); },
  }, 'eu'),
  writes,
  deletions,
};

process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $module,
        ]);
        $process->mustRun();

        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertFalse($result['missing']);
        self::assertFalse($result['denied']);
        self::assertFalse($result['absent']);
        self::assertTrue($result['accepted']);
        self::assertFalse($result['brokenRead']);
        self::assertFalse($result['brokenWrite']);
        self::assertSame([
            ['cookie_custom_lang', 'en', 90],
        ], $result['writes']);
        self::assertSame([
            'cookie_custom_lang',
            'cookie_custom_lang',
        ], $result['deletions']);
    }

    public function testLatestLanguageRequestWinsOutOfOrderResponses(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $module = dirname(__DIR__, 2)
            . '/resources/js/_languagePreference.mjs';
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const preference = await import(pathToFileURL(process.argv[1]).href);
const tracker = preference.createLatestLanguageRequestTracker();
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolveValue, rejectValue) => {
    resolve = resolveValue;
    reject = rejectValue;
  });
  return { promise, resolve, reject };
};
const commits = [];
const fallbacks = [];
const settle = async (label, request, token) => {
  try {
    await request.promise;
    if (tracker.isCurrent(token)) commits.push(label);
  } catch {
    if (tracker.isCurrent(token)) fallbacks.push(label);
  }
};

const first = deferred();
const firstToken = tracker.next();
const firstRun = settle('first', first, firstToken);
const second = deferred();
const secondToken = tracker.next();
const secondRun = settle('second', second, secondToken);
second.resolve();
await secondRun;
first.resolve();
await firstRun;

const third = deferred();
const thirdToken = tracker.next();
const thirdRun = settle('third', third, thirdToken);
const fourth = deferred();
const fourthToken = tracker.next();
const fourthRun = settle('fourth', fourth, fourthToken);
fourth.resolve();
await fourthRun;
third.reject(new Error('stale request failed'));
await thirdRun;

process.stdout.write(JSON.stringify({
  commits,
  fallbacks,
  firstIsCurrent: tracker.isCurrent(firstToken),
  fourthIsCurrent: tracker.isCurrent(fourthToken),
}));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $module,
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(['second', 'fourth'], $result['commits']);
        self::assertSame([], $result['fallbacks']);
        self::assertFalse($result['firstIsCurrent']);
        self::assertTrue($result['fourthIsCurrent']);
    }

    public function testLanguageFallbackOnlyNavigatesToValidSameOriginHref(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $module = dirname(__DIR__, 2)
            . '/resources/js/_languagePreference.mjs';
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const preference = await import(pathToFileURL(process.argv[1]).href);
const assigned = [];
const windowRef = {
  location: {
    href: 'https://example.test/es/noticias?page=2',
    assign(href) { assigned.push(href); },
  },
};

const result = {
  relative: preference.navigateToLanguageHref(windowRef, '/en/news'),
  sameOriginAbsolute: preference.navigateToLanguageHref(
    windowRef,
    'https://example.test/eu/albisteak'
  ),
  hash: preference.navigateToLanguageHref(windowRef, '#language'),
  javascript: preference.navigateToLanguageHref(
    windowRef,
    'javascript:alert(1)'
  ),
  external: preference.navigateToLanguageHref(
    windowRef,
    'https://external.test/en'
  ),
  empty: preference.navigateToLanguageHref(windowRef, ''),
  assigned,
};

process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $module,
        ]);
        $process->mustRun();

        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($result['relative']);
        self::assertTrue($result['sameOriginAbsolute']);
        self::assertFalse($result['hash']);
        self::assertFalse($result['javascript']);
        self::assertFalse($result['external']);
        self::assertFalse($result['empty']);
        self::assertSame([
            'https://example.test/en/news',
            'https://example.test/eu/albisteak',
        ], $result['assigned']);
    }

    public function testLanguageCatalogLoadingIsAtomicAndRejectsInvalidResponses(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $module = dirname(__DIR__, 2)
            . '/resources/js/_languagePreference.mjs';
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const preference = await import(pathToFileURL(process.argv[1]).href);
const calls = [];
const catalogs = await preference.loadLanguageCatalogs(
  async (url, options) => {
    const body = new URLSearchParams(options.body);
    calls.push({
      url,
      route: body.get('route'),
      lang: body.get('lang'),
      method: options.method,
      contentType: options.headers['Content-Type'],
    });
    return {
      ok: true,
      status: 200,
      async text() {
        return JSON.stringify({ route: body.get('route') });
      },
    };
  },
  ['global', 'noticias', 'global'],
  'en'
);

const rejected = async (fetcher, routes = ['global']) => {
  try {
    await preference.loadLanguageCatalogs(fetcher, routes, 'en');
    return false;
  } catch {
    return true;
  }
};

const result = {
  calls,
  catalogs,
  httpRejected: await rejected(async () => ({
    ok: false,
    status: 503,
    async text() { return '{}'; },
  })),
  jsonRejected: await rejected(async () => ({
    ok: true,
    status: 200,
    async text() { return '{'; },
  })),
  arrayRejected: await rejected(async () => ({
    ok: true,
    status: 200,
    async text() { return '[]'; },
  })),
  missingRouteRejected: await rejected(async () => ({
    ok: true,
    status: 200,
    async text() { return '{}'; },
  }), []),
};

process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $module,
        ]);
        $process->mustRun();

        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame([
            [
                'url' => '/languages',
                'route' => 'global',
                'lang' => 'en',
                'method' => 'POST',
                'contentType' =>
                    'application/x-www-form-urlencoded;charset=UTF-8',
            ],
            [
                'url' => '/languages',
                'route' => 'noticias',
                'lang' => 'en',
                'method' => 'POST',
                'contentType' =>
                    'application/x-www-form-urlencoded;charset=UTF-8',
            ],
        ], $result['calls']);
        self::assertSame([
            ['route' => 'global'],
            ['route' => 'noticias'],
        ], $result['catalogs']);
        self::assertTrue($result['httpRejected']);
        self::assertTrue($result['jsonRejected']);
        self::assertTrue($result['arrayRejected']);
        self::assertTrue($result['missingRouteRejected']);
    }

    public function testLanguageClickDoesNotGateTranslationOnCookieLad(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/_traducciones.js'
        );

        self::assertStringContainsString(
            'persistLanguagePreference(window.CookieLAD, idioma);',
            $source
        );
        self::assertStringNotContainsString(
            "if (typeof window.CookieLAD === 'undefined')",
            $source
        );
        self::assertStringContainsString(
            'const fallbackHref = btn.getAttribute("href");',
            $source
        );
        self::assertStringContainsString(
            'await traduccion.traducirTodo(idioma, requestToken);',
            $source
        );
        self::assertStringContainsString(
            'const requestToken = languageRequests.next();',
            $source
        );
        self::assertStringContainsString(
            'async traducirTodo(idioma, requestToken = null)',
            $source
        );
        self::assertSame(3, substr_count(
            $source,
            'languageRequests.isCurrent('
        ));
        self::assertStringContainsString(
            'const catalogs = await loadLanguageCatalogs(',
            $source
        );
        $method = substr(
            $source,
            (int) strpos(
                $source,
                'async traducirTodo(idioma, requestToken = null)'
            )
        );
        $loadedAt = strpos($method, 'await loadLanguageCatalogs(');
        $appliedAt = strpos($method, 'this.aplicarCatalogo(');
        $eventAt = strpos($method, 'window.dispatchEvent(');
        $historyAt = strpos($method, 'history.pushState(');
        self::assertIsInt($loadedAt);
        self::assertIsInt($appliedAt);
        self::assertIsInt($eventAt);
        self::assertIsInt($historyAt);
        self::assertLessThan($appliedAt, $loadedAt);
        self::assertLessThan($eventAt, $appliedAt);
        self::assertLessThan($historyAt, $eventAt);
        self::assertStringContainsString(
            'if (!languageRequests.isCurrent(activeRequestToken))',
            $method
        );
        self::assertStringNotContainsString('.catch(', $method);
        self::assertSame(
            2,
            substr_count(
                $source,
                'navigateToLanguageHref(window, fallbackHref);'
            )
        );
    }

    public function testTranslationRuntimePreservesNonLocalizedHrefForms(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/_traducciones.js'
        );

        self::assertStringContainsString(
            'resolveLocalizedHref(pathOrigin, idioma, rawHref)',
            $source
        );

        foreach (['"/"', '"#"', '"?"', '"//"'] as $prefix) {
            self::assertStringContainsString(
                "href.startsWith({$prefix})",
                $source
            );
        }

        self::assertStringContainsString(
            '/^[a-z][a-z\\d+.-]*:/i.test(href)',
            $source
        );
        self::assertStringContainsString(
            'return `${pathOrigin}/${lang}/${href}`;',
            $source
        );
        self::assertSame(
            3,
            substr_count($source, 'this.resolveLocalizedHref('),
            'El helper debe usarse en los flujos de traducción activos'
        );
    }

    public function testTranslationRuntimePreservesSegmentedShowroomRoute(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents(
            $root . '/resources/js/_traducciones.js'
        );
        $shell = (string) file_get_contents(
            $root . '/stubs/App/views/_showroom.php'
        );

        self::assertStringContainsString(
            'resolveRouteContext(pathActual, currentLang, targetLang = currentLang)',
            $source
        );
        self::assertStringContainsString(
            'document.body?.dataset.showroomCategory',
            $source
        );
        self::assertStringContainsString(
            'const parentPath = decodedPath.slice(0, -(category.length + 1));',
            $source
        );
        self::assertStringContainsString(
            'path: `${targetParent.replace(/\\/$/, "")}/${category}`',
            $source
        );
        self::assertSame(2, substr_count($source, 'this.resolveRouteContext('));

        self::assertStringContainsString(
            'data-showroom-category=',
            $shell
        );
        self::assertStringContainsString(
            'data-lang="showroom_catalog_title"',
            $shell
        );
        self::assertStringContainsString(
            'data-lang="showroom_catalog_nav"',
            $shell
        );
        self::assertStringContainsString(
            'data-showroom-link="index"',
            $shell
        );
        self::assertStringNotContainsString(
            'class="showroomCatalog-nav" data-lang=',
            $shell
        );
        self::assertStringNotContainsString(
            'class="showroomCatalog-index" data-lang=',
            $shell
        );

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = json_decode(
                (string) file_get_contents(
                    $root
                    . "/stubs/App/config/languages/templates/{$language}.json"
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertArrayHasKey('showroom_catalog_title', $catalog);
            self::assertArrayHasKey('showroom_catalog_intro', $catalog);
            self::assertArrayHasKey('showroom_catalog_nav', $catalog);
            self::assertArrayHasKey('showroom_catalog_index', $catalog);

            foreach ([
                'heroes',
                'particles',
                'gsap_specials',
                'common',
                'cards_grids',
                'media',
                'forms_interactive',
                'modules_sections',
            ] as $category) {
                self::assertArrayHasKey(
                    "showroom_catalog_category_{$category}_label",
                    $catalog
                );
                self::assertArrayHasKey(
                    "showroom_catalog_category_{$category}_description",
                    $catalog
                );
            }
        }
    }

    public function testShowroomLinksFollowTheLocalizedParentWithoutReload(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $module = dirname(__DIR__, 2)
            . '/src/js/showroom/catalog-routing.mjs';
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const routing = await import(pathToFileURL(process.argv[1]).href);
const links = [
  {
    dataset: { showroomLink: 'index' },
    setAttribute(name, value) { this[name] = value; },
  },
  {
    dataset: { showroomLink: 'heroes' },
    setAttribute(name, value) { this[name] = value; },
  },
  {
    dataset: { showroomLink: 'media' },
    setAttribute(name, value) { this[name] = value; },
  },
];
const listeners = {};
const documentRef = {
  body: { dataset: { showroomCategory: 'media' } },
  querySelectorAll() { return links; },
};
const windowRef = {
  location: { pathname: '/es/showroom/media' },
  addEventListener(name, handler) { listeners[name] = handler; },
  removeEventListener(name, handler) {
    if (listeners[name] === handler) delete listeners[name];
  },
};

const cleanup = routing.installShowroomLanguageLinks(
  windowRef,
  documentRef,
);
listeners['app:languagechange']({
  detail: { path: '/en/showroom/media' },
});

const result = {
  hrefs: links.map((link) => link.href),
  aliasBase: routing.resolveShowroomBasePath(
    '/eu/templates/forms-interactive',
    'forms-interactive',
  ),
  invalidTarget: routing.resolveShowroomLink('/en/showroom', '../private'),
  listenerBeforeCleanup:
    typeof listeners['app:languagechange'] === 'function',
};
cleanup();
result.listenerAfterCleanup =
  typeof listeners['app:languagechange'] === 'function';

process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process([
            'node',
            '--input-type=module',
            '--eval',
            $script,
            $module,
        ]);
        $process->mustRun();

        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame([
            '/en/showroom',
            '/en/showroom/heroes',
            '/en/showroom/media',
        ], $result['hrefs']);
        self::assertSame('/eu/templates', $result['aliasBase']);
        self::assertSame('/en/showroom', $result['invalidTarget']);
        self::assertTrue($result['listenerBeforeCleanup']);
        self::assertFalse($result['listenerAfterCleanup']);
    }
}
