<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class BlogDiscoveryResourceContractTest extends TestCase
{
    private string $originalCwd = '';
    private string $originalProjectRoot = '';

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->originalProjectRoot = Paths::projectRoot();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());
    }

    protected function tearDown(): void
    {
        Paths::setProjectRoot($this->originalProjectRoot);
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
    }

    public function testRelatedSectionUsesPublicCardArraysAndScalesHeadings(): void
    {
        $html = controller('sectionBlogRelated01', 2, [
            '{header-primary}' => '<h3 class="external-heading">Relacionadas</h3>',
            'items_data' => [
                $this->card('/es/noticias/matrix', 'Matrix'),
                $this->card('/es/noticias/zion', 'Zion'),
            ],
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/section'));
        self::assertCount(2, $xpath->query('//section//article'));
        self::assertCount(1, $xpath->query('//section/h3'));
        self::assertCount(2, $xpath->query('//article/h4'));
        self::assertSame(
            'sectionBlogRelated01-02-heading',
            $xpath->evaluate('string(/html/body/section/@aria-labelledby)')
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testRelatedSectionHidesWhenNoValidItemExists(): void
    {
        self::assertSame('', controller('sectionBlogRelated01', 0, [
            'items_data' => [],
        ]));
        self::assertSame('', controller('sectionBlogRelated01', 1, [
            'items_data' => [[
                'url' => 'javascript:alert(1)',
                'h1' => 'Unsafe',
                'excerpt' => 'Unsafe.',
                'published_at' => '2026-08-03T09:00:00+00:00',
            ]],
        ]));
    }

    public function testArchiveIsAnAccessiblePresentationOnlyNavigation(): void
    {
        $html = controller('moduleBlogArchive01', 1, [
            'header_level' => 3,
            'header_text' => 'Archivo Matrix',
            'periods_data' => [
                [
                    'url' => '/es/noticias?year=2026&month=4',
                    'label' => 'Abril de 2026',
                    'count' => 2,
                    'active' => true,
                ],
                [
                    'url' => '/es/noticias?year=2026&month=3',
                    'label' => 'Marzo de 2026',
                    'count' => 1,
                    'active' => true,
                ],
            ],
            'count_label_singular' => 'entrada',
            'count_label_plural' => 'entradas',
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/nav'));
        self::assertCount(1, $xpath->query('//nav/h3'));
        self::assertCount(1, $xpath->query('//nav/ol'));
        self::assertCount(2, $xpath->query('//nav/ol/li/a'));
        self::assertSame(
            'moduleBlogArchive01-01-heading',
            $xpath->evaluate('string(/html/body/nav/@aria-labelledby)')
        );
        self::assertStringContainsString('aria-label="2 entradas"', $html);
        self::assertStringContainsString('aria-label="1 entrada"', $html);
        self::assertSame(1, substr_count($html, 'aria-current="date"'));
        self::assertStringContainsString(
            'moduleBlogArchive01-item--active',
            $html
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testArchiveRejectsUnsafeOrIncompletePeriodsAndEscapesCopy(): void
    {
        $html = controller('moduleBlogArchive01', 0, [
            'header_text' => '<script>heading</script>',
            'periods_data' => [
                [
                    'url' => 'javascript:alert(1)',
                    'label' => 'Unsafe',
                    'count' => 4,
                ],
                [
                    'url' => '/es/noticias?year=2026&month=4',
                    'label' => '<b>Abril</b>',
                    'count' => 2,
                ],
                [
                    'url' => '/es/noticias?year=2026&month=3',
                    'label' => 'Vacío',
                    'count' => 0,
                ],
            ],
        ]);

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('Unsafe', $html);
        self::assertStringNotContainsString('Vacío', $html);
        self::assertStringContainsString(
            '&lt;script&gt;heading&lt;/script&gt;',
            $html
        );
        self::assertStringContainsString('&lt;b&gt;Abril&lt;/b&gt;', $html);
        self::assertSame('', controller('moduleBlogArchive01', 2, [
            'periods_data' => [],
        ]));
    }

    public function testDiscoveryResourcesNeedNoJavascriptOrDatabaseAccess(): void
    {
        $root = self::moduleProjectRoot();
        foreach (['sectionBlogRelated01', 'moduleBlogArchive01'] as $resource) {
            $controller = (string) file_get_contents(
                $root . "/App/controllers/{$resource}.php"
            );
            $scss = (string) file_get_contents(
                $root . "/src/scss/resources/_{$resource}.scss"
            );

            foreach (['PDO', 'SELECT ', 'prepare(', 'query(', 'BlogService'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $controller);
            }
            self::assertFileDoesNotExist(
                $root . "/src/js/resources/_{$resource}.js"
            );
            self::assertStringContainsString("@use '../config' as c;", $scss);
            self::assertStringContainsString(
                '@media (min-width: c.$tablet)',
                $scss
            );
            if ($resource === 'moduleBlogArchive01') {
                self::assertStringContainsString('position: static;', $scss);
                self::assertStringContainsString('height: auto;', $scss);
                self::assertStringContainsString('z-index: auto;', $scss);
            } else {
                self::assertStringContainsString(
                    'justify-content: center;',
                    $scss
                );
            }
            self::assertDoesNotMatchRegularExpression(
                '/@media\s*\([^)]*max-width/i',
                $scss
            );
            self::assertDoesNotMatchRegularExpression(
                '/\bc\.\$color(?:0[4-9]|[1-9][0-9])/',
                $scss
            );
            self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $scss);
        }
    }

    /** @return array<string, string> */
    private function card(string $url, string $title): array
    {
        return [
            'url' => $url,
            'h1' => $title,
            'excerpt' => 'Una entrada relacionada de prueba Matrix.',
            'published_at' => '2026-08-03T09:00:00+00:00',
        ];
    }

    private function createXpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        return new DOMXPath($document);
    }

    private static function moduleProjectRoot(): string
    {
        return self::coreRoot() . '/modules/blog/resources/project';
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
