<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';
use function App\Core\Support\controller;

final class Art33Art34ContractTest extends TestCase
{
    private string $originalCwd = '';
    private array $originalEnv = [];

    /** @var array<string, array{exists: bool, value?: mixed}> */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->originalEnv = $_ENV;

        Paths::setProjectRoot(dirname(__DIR__, 2) . '/stubs');
        chdir(Paths::publicPath());

        $_ENV['LANG_DEFAULT'] = 'es';
        $_ENV['RAIZ'] = 'http://localhost:1309';
        $this->setGlobal('lang', 'es');

        $catalog = json_decode(
            (string) file_get_contents(
                Paths::appPath() . '/config/languages/templates/es.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($catalog as $key => $value) {
            if (str_starts_with($key, 'art33_') || str_starts_with($key, 'art34_')) {
                $this->setGlobal($key, (object) $value);
            }
        }
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

        $_ENV = $this->originalEnv;
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
    }

    public function testResourcesKeepArticleSemanticsAndComposableSlots(): void
    {
        $paragraph = '<p class="injected-paragraph">There is no spoon.</p>';
        $list = '<ul class="injected-list"><li>Free your mind.</li></ul>';

        $art33 = controller('art33', 0, [
            'items' => 2,
            '{a-content}' => $paragraph,
            '{b-content}' => $list,
            '{a-button-primary}' => '<a class="card-cta" href="#">Entrar</a>',
        ]);
        $art33Xpath = $this->createXpath($art33);

        self::assertCount(1, $art33Xpath->query('/html/body/article'));
        self::assertCount(0, $art33Xpath->query('//section | //header'));
        self::assertCount(1, $art33Xpath->query('//article/h3'));
        self::assertCount(2, $art33Xpath->query('//article/div/div'));
        self::assertCount(2, $art33Xpath->query('//article/div/div/h4'));
        self::assertCount(
            1,
            $art33Xpath->query('//*[contains(@class, "injected-paragraph")]')
        );
        self::assertCount(
            1,
            $art33Xpath->query('//*[contains(@class, "card-cta")]')
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $art33);

        $art34 = controller('art34', 0, [
            'items' => 2,
            '{a-content}' => $list,
            '{b-content}' => $paragraph,
            '{button-primary}' => '<a class="root-cta" href="#">Continuar</a>',
        ]);
        $art34Xpath = $this->createXpath($art34);

        self::assertCount(1, $art34Xpath->query('/html/body/article'));
        self::assertCount(0, $art34Xpath->query('//section | //header'));
        self::assertCount(1, $art34Xpath->query('//article/h3'));
        self::assertCount(2, $art34Xpath->query('//h4'));
        self::assertCount(
            1,
            $art34Xpath->query('//*[contains(@class, "root-cta")]')
        );
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $art34);
    }

    public function testHeadingLevelAndItemCountRemainRelativeAndBounded(): void
    {
        $html = controller('art33', 0, [
            'items' => 3,
            'header_level' => 2,
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('//article/h2'));
        self::assertCount(3, $xpath->query('//article/div/div/h3'));
        self::assertStringContainsString('art33--items-3', $html);

        $maximum = controller('art34', 0, ['items' => 100]);
        self::assertSame(26, substr_count($maximum, 'class="art34-item '));
        self::assertStringContainsString('art34--items-26', $maximum);
    }

    public function testCatalogAndShowroomRegistrationsAreComplete(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $showroom = ShowroomCatalogFixture::corePhp($coreRoot);
        $scss = ShowroomCatalogFixture::scss($coreRoot);

        foreach (['art33', 'art34'] as $resource) {
            self::assertFileExists(
                $coreRoot . "/stubs/App/controllers/{$resource}.php"
            );
            self::assertFileExists(
                $coreRoot . "/stubs/App/templates/_{$resource}.html"
            );
            self::assertFileExists(
                $coreRoot . "/resources/scss/_{$resource}.scss"
            );
            self::assertSame(
                1,
                substr_count($showroom, "controller('{$resource}', 0")
            );
            self::assertStringContainsString(
                "@use '../resources/{$resource}';",
                $scss
            );
        }

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = json_decode(
                (string) file_get_contents(
                    $coreRoot
                    . "/stubs/App/config/languages/templates/{$language}.json"
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            foreach ([
                'art33_00_headerPrimary',
                'art33_00_headerSecondary_b',
                'art34_00_headerPrimary',
                'art34_00_headerSecondary_b',
                'moduleList01_05_e_li_text',
                'moduleParrafo01_06_p_text',
            ] as $key) {
                self::assertArrayHasKey($key, $catalog);
            }
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

    private function setGlobal(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->globalState)) {
            $this->globalState[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false];
        }

        $GLOBALS[$key] = $value;
    }
}
