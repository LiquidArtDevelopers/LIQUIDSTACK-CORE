<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TranslationHrefContractTest extends TestCase
{
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
            4,
            substr_count($source, 'this.resolveLocalizedHref('),
            'El helper debe usarse en los cuatro flujos de traducción'
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
