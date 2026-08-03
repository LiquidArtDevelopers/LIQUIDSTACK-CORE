<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';
use function App\Core\Support\controller;

final class Auth02ResourceContractTest extends TestCase
{
    private const MODULES = [
        'moduleFormAuthLogin02',
        'moduleFormAuthRecover02',
        'moduleFormAuthPassword02',
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

    public function testFamilyProvidesEveryManagedResourceFile(): void
    {
        foreach ([
            'stubs/App/controllers/artAuth02.php',
            'stubs/App/controllers/_moduleFormAuth02.php',
            'stubs/App/templates/_artAuth02.html',
            'resources/scss/_artAuth02.scss',
            'resources/scss/_moduleFormAuth02.scss',
            'resources/js/_moduleFormAuth02.js',
        ] as $relativePath) {
            self::assertFileExists(self::coreRoot() . '/' . $relativePath);
        }

        foreach (self::MODULES as $resource) {
            self::assertFileExists(
                self::coreRoot() . "/stubs/App/controllers/{$resource}.php"
            );
            self::assertFileExists(
                self::coreRoot() . "/stubs/App/templates/_{$resource}.html"
            );
            self::assertFileExists(
                self::coreRoot() . "/resources/scss/_{$resource}.scss"
            );
        }
    }

    public function testArticleKeepsNaturalSemanticsAndScalesHeadings(): void
    {
        $html = controller('artAuth02', 0, [
            '{form-slot}' => '<div class="injected-form"></div>',
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/article'));
        self::assertCount(0, $xpath->query('//section | //header'));
        self::assertCount(1, $xpath->query('//article//h3'));
        self::assertCount(1, $xpath->query('//article//h4'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "injected-form")]'));
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);

        $scaled = $this->createXpath(controller('artAuth02', 0, [
            'header_level' => 2,
        ]));
        self::assertCount(1, $scaled->query('//article//h2'));
        self::assertCount(1, $scaled->query('//article//h3'));
        self::assertCount(0, $scaled->query('//article//h4'));

        self::assertStringNotContainsString(
            'artAuth02--reverse',
            controller('artAuth02', 0)
        );
        self::assertStringContainsString(
            'artAuth02--reverse',
            controller('artAuth02', 1)
        );
        self::assertStringContainsString(
            'artAuth02--reverse',
            controller('artAuth02', 0, ['variant' => 'reverse'])
        );
    }

    public function testModulesPreserveTheWebAdminFieldContractAndSlots(): void
    {
        $loginHtml = controller('moduleFormAuthLogin02', 0, [
            'action' => '/session-contract',
            'method' => 'post',
            'language_attributes' => false,
            '{hidden-fields}' => '<input type="hidden" name="csrf" value="fixture">',
            '{feedback-slot}' => '<p class="fixture-feedback">Ready</p>',
        ]);
        $login = $this->createXpath($loginHtml);
        self::assertCount(1, $login->query('/html/body/div'));
        self::assertCount(0, $login->query('//article | //section | //header'));
        self::assertCount(1, $login->query('//form[@action="/session-contract"]'));
        self::assertCount(1, $login->query('//input[@name="email"]'));
        self::assertCount(1, $login->query('//input[@name="password"]'));
        self::assertCount(1, $login->query('//input[@name="csrf"]'));
        self::assertCount(1, $login->query('//*[contains(@class, "fixture-feedback")]'));
        self::assertCount(0, $login->query('//*[@data-lang]'));

        $recover = $this->createXpath(controller('moduleFormAuthRecover02', 0));
        self::assertCount(1, $recover->query('//input[@name="email"]'));
        self::assertCount(0, $recover->query('//input[@name="password"]'));

        $passwordHtml = controller('moduleFormAuthPassword02', 0);
        $password = $this->createXpath($passwordHtml);
        self::assertCount(1, $password->query('//input[@name="password"]'));
        self::assertCount(
            1,
            $password->query('//input[@name="password_confirmation"]')
        );
        self::assertCount(2, $password->query('//input[@minlength="8"]'));
        self::assertCount(6, $password->query('//*[@data-auth02-rule]'));
        self::assertCount(1, $password->query('//*[@data-auth02-rule="match"]'));
        self::assertCount(
            1,
            $password->query('//*[@data-auth02-requirements-summary]')
        );
        self::assertStringNotContainsString('1024 bytes', $passwordHtml);
        self::assertStringNotContainsString('15 caracteres', $passwordHtml);
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $passwordHtml);
    }

    public function testIdsAriaReferencesAndShowroomAnchorsStayValid(): void
    {
        $html = controller('artAuth02', 0, [
            '{form-slot}' => controller('moduleFormAuthLogin02', 0),
        ]) . controller('artAuth02', 1, [
            '{form-slot}' => controller('moduleFormAuthRecover02', 0),
        ]) . controller('artAuth02', 2, [
            '{form-slot}' => controller('moduleFormAuthPassword02', 0),
        ]);
        $xpath = $this->createXpath($html);
        $ids = [];

        foreach ($xpath->query('//*[@id]') as $element) {
            self::assertInstanceOf(DOMElement::class, $element);
            $id = $element->getAttribute('id');
            self::assertNotSame('', $id);
            self::assertArrayNotHasKey($id, $ids, "ID duplicado: {$id}");
            $ids[$id] = true;
        }

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

        foreach ($xpath->query('//a[starts-with(@href, "#")]') as $anchor) {
            self::assertInstanceOf(DOMElement::class, $anchor);
            $target = substr($anchor->getAttribute('href'), 1);
            self::assertArrayHasKey($target, $ids, "Ancla inexistente: {$target}");
        }
    }

    public function testCatalogsHydrateEveryRenderedKeyInAllBaseLanguages(): void
    {
        $html = controller('artAuth02', 0, [
            '{form-slot}' => controller('moduleFormAuthLogin02', 0),
        ]) . controller('artAuth02', 1, [
            '{form-slot}' => controller('moduleFormAuthRecover02', 0),
        ]) . controller('artAuth02', 2, [
            '{form-slot}' => controller('moduleFormAuthPassword02', 0),
        ]);
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
                'moduleFormAuthLogin02_00_emailPlaceholder',
                'moduleFormAuthLogin02_00_passwordPlaceholder',
                'moduleFormAuthRecover02_00_emailPlaceholder',
                'moduleFormAuthPassword02_00_passwordPlaceholder',
                'moduleFormAuthPassword02_00_confirmationPlaceholder',
            ] as $key) {
                self::assertSame(['placeholder'], array_keys($catalog[$key] ?? []));
                self::assertNotSame('', trim((string) ($catalog[$key]['placeholder'] ?? '')));
            }

            foreach (self::MODULES as $resource) {
                self::assertStringContainsString(
                    $resource,
                    (string) ($catalog["{$resource}_00_legend"]['text'] ?? '')
                );
            }
            foreach ([0, 1, 2] as $index) {
                self::assertStringContainsString(
                    'artAuth02',
                    (string) ($catalog[sprintf('artAuth02_%02d_headerPrimary', $index)]['text'] ?? '')
                );
            }

            $matrixCopy = implode(' ', array_map(
                static fn (array $entry): string => (string) ($entry['text'] ?? ''),
                array_filter(
                    $catalog,
                    static fn (string $key): bool => str_starts_with($key, 'artAuth02_'),
                    ARRAY_FILTER_USE_KEY
                )
            ));
            self::assertStringContainsString('Matrix', $matrixCopy);
        }
    }

    public function testShowroomStylesAndJavascriptRegisterTheFamilyOnce(): void
    {
        $php = ShowroomCatalogFixture::corePhp(self::coreRoot());
        $scss = ShowroomCatalogFixture::scss(self::coreRoot());
        $javascript = ShowroomCatalogFixture::javascript(self::coreRoot());

        self::assertSame(
            1,
            substr_count($php, "controller('moduleH2Type01', 10)")
        );
        self::assertSame(
            1,
            preg_match(
                "/<section>\\s*<\\?php\\s*echo controller\\('moduleH2Type01', 10\\);/",
                $php
            ),
            'La section canónica debe comenzar con su encabezado h2.'
        );
        self::assertSame(3, substr_count($php, "controller('artAuth02'"));
        foreach (self::MODULES as $resource) {
            self::assertSame(1, substr_count($php, "controller('{$resource}', 0)"));
        }

        foreach (array_merge(['artAuth02', 'moduleFormAuth02'], self::MODULES) as $resource) {
            self::assertSame(
                1,
                substr_count($scss, "@use '../resources/{$resource}';")
            );
        }
        self::assertSame(
            1,
            substr_count($javascript, "from '../resources/_moduleFormAuth02.js'")
        );
        self::assertSame(1, substr_count($javascript, 'initModuleFormAuth02()'));

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readCatalog($language);
            self::assertStringContainsString(
                'moduleH2Type01',
                (string) ($catalog['moduleH2Type01_10_h2_text']['text'] ?? '')
            );
        }
    }

    public function testPasswordFeedbackUsesThemeTokensAndHmrSafeJavascript(): void
    {
        foreach (array_merge(['artAuth02', 'moduleFormAuth02'], self::MODULES) as $resource) {
            $scss = (string) file_get_contents(
                self::coreRoot() . "/resources/scss/_{$resource}.scss"
            );
            self::assertStringContainsString("@use '../config' as c;", $scss);
            self::assertDoesNotMatchRegularExpression('/\bc\.\$color(?:0[4-9]|[1-9][0-9])/', $scss);
            self::assertStringNotContainsString('filterColor', $scss);
            self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $scss);
            self::assertDoesNotMatchRegularExpression('/@media\s*\([^)]*max-width/i', $scss);
        }

        $sharedScss = (string) file_get_contents(
            self::coreRoot() . '/resources/scss/_moduleFormAuth02.scss'
        );
        self::assertStringContainsString('overflow-wrap: anywhere', $sharedScss);
        self::assertStringContainsString('c.$colorOK', $sharedScss);
        self::assertStringContainsString('c.$colorERROR', $sharedScss);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $sharedScss);

        $articleScss = (string) file_get_contents(
            self::coreRoot() . '/resources/scss/_artAuth02.scss'
        );
        self::assertStringContainsString('&.artAuth02--reverse', $articleScss);
        self::assertStringNotContainsString(':nth-of-type(even)', $articleScss);

        $javascript = (string) file_get_contents(
            self::coreRoot() . '/resources/js/_moduleFormAuth02.js'
        );
        foreach ([
            'lengthOf(password) >= 8',
            '/\\p{Ll}/u',
            '/\\p{Lu}/u',
            '/\\p{N}/u',
            '/[\\p{P}\\p{S}]/u',
            'confirmation.length > 0 && password === confirmation',
            'submit.disabled = !allMet',
            'resetPasswordToggles(root)',
            'removeEventListener',
            'STATE_KEY',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        self::assertStringNotContainsString('innerHTML', $javascript);
        self::assertStringNotContainsString('fetch(', $javascript);
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
                !str_starts_with($key, 'artAuth02_')
                && !str_starts_with($key, 'moduleFormAuthLogin02_')
                && !str_starts_with($key, 'moduleFormAuthRecover02_')
                && !str_starts_with($key, 'moduleFormAuthPassword02_')
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
