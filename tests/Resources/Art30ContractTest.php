<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art30.php';

final class Art30ContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private string $previousProjectRoot;
    private array $previousEnv;

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-art30-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousProjectRoot = Paths::projectRoot();
        $this->previousEnv = $_ENV;

        $templateTarget = $this->fixtureRoot
            . '/App/templates/_art30.html';
        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            self::coreRoot() . '/stubs/App/templates/_art30.html',
            $templateTarget
        );

        Paths::setProjectRoot($this->fixtureRoot);
        chdir($this->fixtureRoot);

        $_ENV['RAIZ'] = 'https://www.example.test';
        $_ENV['LANG_DEFAULT'] = 'es';
        $_ENV['DEV_MODE'] = 'true';
        $this->setGlobal('lang', 'es');
        $this->loadArt30Globals();
    }

    protected function tearDown(): void
    {
        foreach ($this->globalState as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
                continue;
            }

            unset($GLOBALS[$key]);
        }

        $_ENV = $this->previousEnv;
        chdir($this->previousWorkingDirectory);
        Paths::setProjectRoot($this->previousProjectRoot);
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testMaximumContractUsesDivCardsAndDevelopmentGroups(): void
    {
        $html = controller_art30(0, [
            'items'    => 99,
            'benefits' => 99,
        ]);

        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);

        $xpath = $this->createXpath($html);
        $cards = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " art30-card ")]'
        );
        $cardLinks = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "), " art30-cardLink ")]'
        );
        $invalidCardRoots = $xpath->query(
            '//*[not(self::div) and contains(concat(" ", normalize-space(@class), " "), " art30-card ")]'
        );
        $benefits = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " art30-benefit ")]'
        );

        self::assertNotFalse($cards);
        self::assertNotFalse($cardLinks);
        self::assertNotFalse($invalidCardRoots);
        self::assertNotFalse($benefits);
        self::assertCount(4, $cards);
        self::assertCount(4, $cardLinks);
        self::assertCount(0, $invalidCardRoots);
        self::assertCount(6, $benefits);

        foreach ($cards as $card) {
            self::assertTrue($card->hasAttribute('data-inline-group'));
            self::assertCount(1, $xpath->query('.//img[@data-lang]', $card));
        }

        foreach ($cardLinks as $link) {
            $parent = $link->parentNode;

            self::assertInstanceOf(DOMElement::class, $parent);
            self::assertSame('div', strtolower($parent->tagName));
            self::assertMatchesRegularExpression(
                '/(?:^|\s)art30-card(?:\s|$)/',
                $parent->getAttribute('class')
            );
        }

        foreach ($benefits as $benefit) {
            self::assertTrue($benefit->hasAttribute('data-inline-group'));
            self::assertCount(
                1,
                $xpath->query('.//img[@data-lang]', $benefit)
            );
            self::assertCount(
                1,
                $xpath->query(
                    './/*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][@data-lang]',
                    $benefit
                )
            );
        }

        self::assertCount(
            1,
            $xpath->query(
                '//div[contains(concat(" ", normalize-space(@class), " "), " art30-benefits ")][@data-inline-group]'
            )
        );
    }

    public function testInlineGroupsAreNotRenderedOutsideDevelopment(): void
    {
        $_ENV['DEV_MODE'] = 'false';

        $html = controller_art30(0, [
            'items'    => 4,
            'benefits' => 6,
        ]);

        self::assertStringNotContainsString('data-inline-group', $html);
        self::assertSame(4, substr_count($html, 'class="art30-card '));
        self::assertSame(6, substr_count($html, 'class="art30-benefit"'));
    }

    public function testZeroAndNegativeCountsProduceNoRepeatedItems(): void
    {
        foreach ([0, -20] as $count) {
            $html = controller_art30(0, [
                'items'    => $count,
                'benefits' => $count,
            ]);
            $xpath = $this->createXpath($html);

            self::assertCount(
                0,
                $xpath->query(
                    '//div[contains(concat(" ", normalize-space(@class), " "), " art30-card ")]'
                )
            );
            self::assertCount(
                0,
                $xpath->query(
                    '//div[contains(concat(" ", normalize-space(@class), " "), " art30-benefit ")]'
                )
            );

            $banner = $xpath->query(
                '//div[contains(concat(" ", normalize-space(@class), " "), " art30-benefits ")]'
            );
            self::assertNotFalse($banner);
            self::assertCount(1, $banner);
            self::assertSame(0, $banner->item(0)?->childElementCount);
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
        }
    }

    public function testCatalogsHydrateFourthCardAndSixthBenefit(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                self::coreRoot()
                . "/stubs/App/config/languages/templates/{$language}.json"
            );

            foreach ([
                'art30_00_headerSecondary_d',
                'art30_00_d_p',
                'art30_00_benefit_f_headerSecondary',
            ] as $textKey) {
                self::assertSame(
                    ['text'],
                    array_keys($catalog[$textKey] ?? []),
                    "{$language}: invalid text object for {$textKey}"
                );
            }

            self::assertEqualsCanonicalizing(
                ['src', 'alt', 'title'],
                array_keys($catalog['art30_00_d_img'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['href', 'title'],
                array_keys($catalog['art30_00_d_link'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src', 'alt', 'title'],
                array_keys($catalog['art30_00_benefit_f_img'] ?? [])
            );
        }
    }

    public function testBenefitRowsKeepTheirValidatedTopAlignment(): void
    {
        $scss = (string) file_get_contents(
            self::coreRoot() . '/resources/scss/_art30.scss'
        );

        self::assertMatchesRegularExpression(
            '/\.art30-benefits\s*\{.*?align-items:\s*flex-start;/s',
            $scss
        );
    }

    private function createXpath(string $html): DOMXPath
    {
        $previousInternalErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        self::assertTrue($loaded);

        return new DOMXPath($document);
    }

    private function loadArt30Globals(): void
    {
        $catalog = $this->readJson(
            self::coreRoot()
            . '/stubs/App/config/languages/templates/es.json'
        );

        foreach ($catalog as $key => $value) {
            if (!str_starts_with($key, 'art30_')) {
                continue;
            }

            $this->setGlobal(
                $key,
                is_array($value) ? (object) $value : $value
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $content = file_get_contents($path);

        self::assertIsString($content, "Unable to read {$path}");
        $decoded = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($decoded, "{$path} must contain a JSON object");

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
