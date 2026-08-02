<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';
use function App\Core\Support\controller;

final class AuthResourceContractTest extends TestCase
{
    private const MODULES = [
        'moduleFormAuthLogin01',
        'moduleFormAuthRecover01',
        'moduleFormAuthPassword01',
    ];

    private string $originalCwd = '';
    private string $originalProjectRoot = '';

    /** @var array<string, array{exists: bool, value?: mixed}> */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->originalProjectRoot = Paths::projectRoot();

        Paths::setProjectRoot(self::coreRoot() . '/stubs');
        chdir(Paths::publicPath());
        $this->loadGlobals('es');
    }

    protected function tearDown(): void
    {
        foreach ($this->globalState as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }

        Paths::setProjectRoot($this->originalProjectRoot);
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
    }

    public function testArticleKeepsItsNaturalSemanticsAndRelativeHeadings(): void
    {
        $html = controller('artAuth01', 0, [
            '{form-slot}' => controller('moduleFormAuthLogin01', 0),
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/article'));
        self::assertCount(0, $xpath->query('//section | //header'));
        self::assertCount(1, $xpath->query('//article//h3'));
        self::assertCount(1, $xpath->query('//article//h4'));
        self::assertGreaterThanOrEqual(3, $xpath->query('//article//div')->length);
        self::assertCount(1, $xpath->query('//article//form'));
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);

        $scaled = $this->createXpath(controller('artAuth01', 0, [
            'header_level' => 2,
        ]));
        self::assertCount(1, $scaled->query('//article//h2'));
        self::assertCount(1, $scaled->query('//article//h3'));
    }

    public function testFormsExposeBackendAgnosticSlotsAndOptionalLanguageAttributes(): void
    {
        foreach (self::MODULES as $index => $resource) {
            $prefix = 'auth-contract-' . $index;
            $html = controller($resource, 0, [
                'id_prefix' => $prefix,
                'action' => '/session-contract',
                'method' => 'post',
                'language_attributes' => false,
                '{hidden-fields}' => '<input type="hidden" name="_csrf" value="fixture">',
                '{feedback-slot}' => '<p class="fixture-feedback">Ready</p>',
                '{secondary-action-slot}' => '<a class="fixture-secondary" href="#return">Return</a>',
            ]);
            $xpath = $this->createXpath($html);

            self::assertCount(1, $xpath->query('/html/body/div/form'));
            self::assertCount(
                1,
                $xpath->query('//form[@action="/session-contract" and @method="post"]')
            );
            self::assertCount(1, $xpath->query('//input[@name="_csrf"]'));
            self::assertCount(1, $xpath->query('//*[contains(@class, "fixture-feedback")]'));
            self::assertCount(1, $xpath->query('//*[contains(@class, "fixture-secondary")]'));
            self::assertCount(0, $xpath->query('//*[@data-lang]'));
            self::assertStringNotContainsString('/admin', $html);
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
        }
    }

    public function testIdsAndAriaReferencesRemainUniqueAcrossManyInstances(): void
    {
        $html = controller('artAuth01', 0)
            . controller('artAuth01', 1)
            . controller('moduleFormAuthLogin01', 0)
            . controller('moduleFormAuthLogin01', 1)
            . controller('moduleFormAuthRecover01', 0)
            . controller('moduleFormAuthRecover01', 1)
            . controller('moduleFormAuthPassword01', 0)
            . controller('moduleFormAuthPassword01', 1);
        $xpath = $this->createXpath($html);
        $ids = [];

        foreach ($xpath->query('//*[@id]') as $element) {
            self::assertInstanceOf(DOMElement::class, $element);
            $id = $element->getAttribute('id');
            self::assertNotSame('', $id);
            self::assertArrayNotHasKey($id, $ids, "ID duplicado: {$id}");
            $ids[$id] = true;
        }

        self::assertNotEmpty($ids);

        foreach (['for', 'aria-controls', 'aria-labelledby', 'aria-describedby'] as $attribute) {
            foreach ($xpath->query("//*[@{$attribute}]") as $element) {
                self::assertInstanceOf(DOMElement::class, $element);
                $targets = preg_split(
                    '/\s+/',
                    trim($element->getAttribute($attribute))
                ) ?: [];

                foreach ($targets as $target) {
                    self::assertArrayHasKey(
                        $target,
                        $ids,
                        "{$attribute} referencia un ID inexistente: {$target}"
                    );
                }
            }
        }
    }

    public function testPasswordResourceOnlyStatesTheRealWebAdminPolicy(): void
    {
        $html = controller('moduleFormAuthPassword01', 0);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('//ul[contains(@class, "moduleFormAuth-requirements")]/li'));
        self::assertStringContainsString('15', $html);
        self::assertStringContainsString('1024', $html);
        self::assertStringContainsString('UTF-8', $html);
        self::assertStringNotContainsString('Mayúsculas', $html);
        self::assertStringNotContainsString('número', $html);
        self::assertStringNotContainsString('símbolo', $html);
    }

    public function testCatalogsHydrateEveryRenderedLanguageKey(): void
    {
        $html = controller('artAuth01', 0)
            . controller('moduleFormAuthLogin01', 0)
            . controller('moduleFormAuthRecover01', 0)
            . controller('moduleFormAuthPassword01', 0);
        $xpath = $this->createXpath($html);
        $renderedKeys = [];

        foreach ($xpath->query('//*[@data-lang]') as $element) {
            self::assertInstanceOf(DOMElement::class, $element);
            $renderedKeys[$element->getAttribute('data-lang')] = true;
        }

        self::assertNotEmpty($renderedKeys);

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readCatalog($language);
            foreach (array_keys($renderedKeys) as $key) {
                self::assertArrayHasKey($key, $catalog, "{$language}: falta {$key}");
                self::assertNotSame([], $catalog[$key]);
            }

            foreach ([
                'moduleFormAuthLogin01_00_emailPlaceholder',
                'moduleFormAuthLogin01_00_passwordPlaceholder',
                'moduleFormAuthRecover01_00_emailPlaceholder',
                'moduleFormAuthPassword01_00_passwordPlaceholder',
                'moduleFormAuthPassword01_00_confirmationPlaceholder',
            ] as $key) {
                self::assertSame(['placeholder'], array_keys($catalog[$key] ?? []));
                self::assertNotSame('', trim((string) ($catalog[$key]['placeholder'] ?? '')));
            }

            foreach (self::MODULES as $resource) {
                $legend = (string) ($catalog["{$resource}_00_legend"]['text'] ?? '');
                self::assertStringContainsString($resource, $legend);
            }

            self::assertStringContainsString(
                'artAuth01',
                (string) ($catalog['artAuth01_00_headerPrimary']['text'] ?? '')
            );
        }
    }

    public function testShowroomStylesAndJavascriptRegisterTheFamilyOnce(): void
    {
        $php = ShowroomCatalogFixture::corePhp(self::coreRoot());
        $scss = ShowroomCatalogFixture::scss(self::coreRoot());
        $javascript = ShowroomCatalogFixture::javascript(self::coreRoot());

        foreach (array_merge(['artAuth01'], self::MODULES) as $resource) {
            self::assertSame(
                1,
                preg_match_all(
                    "/controller\\(\\s*['\"]{$resource}['\"]\\s*,\\s*0\\b/",
                    $php
                ),
                "{$resource} debe mostrarse una sola vez"
            );
            self::assertSame(
                1,
                substr_count($scss, "@use '../resources/{$resource}';")
            );
        }

        self::assertSame(
            1,
            substr_count($scss, "@use '../resources/moduleFormAuth';")
        );
        self::assertSame(
            1,
            substr_count($javascript, "from '../resources/_moduleFormAuth.js'")
        );
        self::assertSame(1, substr_count($javascript, 'initModuleFormAuth()'));
    }

    public function testPasswordToggleIsRootScopedHmrSafeAndSubmitNeutral(): void
    {
        $javascript = (string) file_get_contents(
            self::coreRoot() . '/resources/js/_moduleFormAuth.js'
        );

        self::assertStringContainsString(
            'document.querySelectorAll(".moduleFormAuth").forEach(initRoot)',
            $javascript
        );
        self::assertStringContainsString(
            'root.querySelectorAll("[data-auth-password-toggle]")',
            $javascript
        );
        self::assertStringContainsString(
            '.closest(".moduleFormAuth-passwordControl")',
            $javascript
        );
        self::assertStringContainsString('removeEventListener("click"', $javascript);
        self::assertStringContainsString('HANDLERS_KEY', $javascript);
        self::assertStringNotContainsString('preventDefault', $javascript);
        self::assertStringNotContainsString('fetch(', $javascript);
        self::assertStringNotContainsString('"submit"', $javascript);
    }

    public function testStylesAreMobileFirstAndUseOnlyCoreColorFamilies(): void
    {
        foreach (array_merge(['artAuth01', 'moduleFormAuth'], self::MODULES) as $resource) {
            $scss = (string) file_get_contents(
                self::coreRoot() . "/resources/scss/_{$resource}.scss"
            );

            self::assertStringContainsString("@use '../config' as c;", $scss);
            self::assertDoesNotMatchRegularExpression('/\bc\.\$color(?:0[4-9]|[1-9][0-9])/', $scss);
            self::assertStringNotContainsString('filterColor', $scss);
            self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $scss);
            self::assertDoesNotMatchRegularExpression('/@media\s*\([^)]*max-width/i', $scss);
        }
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

    private function loadGlobals(string $language): void
    {
        foreach ($this->readCatalog($language) as $key => $value) {
            if (
                !str_starts_with($key, 'artAuth01_')
                && !str_starts_with($key, 'moduleFormAuth')
            ) {
                continue;
            }

            $this->setGlobal($key, is_array($value) ? (object) $value : $value);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function readCatalog(string $language): array
    {
        $decoded = json_decode(
            (string) file_get_contents(
                self::coreRoot()
                . "/stubs/App/config/languages/templates/{$language}.json"
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function setGlobal(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->globalState)) {
            $this->globalState[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false];
        }

        $GLOBALS[$key] = $value;
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
