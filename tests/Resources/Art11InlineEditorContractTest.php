<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art11.php';

final class Art11InlineEditorContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;
    private string $previousProjectRoot;
    private array $previousEnv;

    /**
     * @var array<string, mixed>
     */
    private array $catalog = [];

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-art11-inline-editor-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();
        $this->previousProjectRoot = Paths::projectRoot();
        $this->previousEnv = $_ENV;

        $templateTarget = $this->fixtureRoot
            . '/App/templates/_art11.html';
        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            self::coreRoot() . '/stubs/App/templates/_art11.html',
            $templateTarget
        );

        Paths::setProjectRoot($this->fixtureRoot);
        chdir($this->fixtureRoot);

        $_ENV['RAIZ'] = 'https://www.example.test';
        $_ENV['LANG_DEFAULT'] = 'es';
        $_ENV['DEV_MODE'] = 'true';
        $this->setGlobal('lang', 'es');
        $this->loadArt11Globals();
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

    public function testDevelopmentCountersExposeTargetAndSuffixToTheInlineEditor(): void
    {
        $html = controller_art11(0, ['items' => 3]);
        $xpath = $this->createXpath($html);
        $counters = $xpath->query(
            '//span[contains(concat(" ", normalize-space(@class), " "), " stat-number ")]'
        );

        self::assertNotFalse($counters);
        self::assertCount(3, $counters);

        foreach (['a', 'b', 'c'] as $letter) {
            $targetKey = "art11_00_{$letter}_target";
            $suffixKey = "art11_00_{$letter}_suffix";
            $labelKey = "art11_00_{$letter}_label";
            $counter = $xpath->query(
                '//span[contains(concat(" ", normalize-space(@class), " "), " stat-number ")]'
                . '[@data-lang="' . $targetKey . '"]'
            )?->item(0);

            self::assertInstanceOf(DOMElement::class, $counter);
            self::assertSame(
                (string) ($this->catalog[$targetKey]['value'] ?? ''),
                $counter->getAttribute('data-target')
            );
            self::assertTrue($counter->hasAttribute('data-inline-group'));
            self::assertTrue(
                $counter->hasAttribute('data-inline-attribute-targets')
            );
            self::assertSame(
                ['value' => 'data-target'],
                json_decode(
                    $counter->getAttribute('data-inline-attribute-targets'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            );

            self::assertCount(
                1,
                $xpath->query('./*[@data-art11-counter-value]', $counter)
            );
            self::assertCount(
                1,
                $xpath->query(
                    './span[contains(concat(" ", normalize-space(@class), " "), " stat-number-suffix ")]'
                    . '[@data-lang="' . $suffixKey . '"]',
                    $counter
                )
            );

            $item = $counter->parentNode;
            self::assertInstanceOf(DOMElement::class, $item);
            $labels = $xpath->query(
                './span[contains(concat(" ", normalize-space(@class), " "), " stat-label ")]'
                . '[@data-lang="' . $labelKey . '"]',
                $item
            );
            self::assertNotFalse($labels);
            self::assertCount(1, $labels);

            $label = $labels->item(0);
            self::assertInstanceOf(DOMElement::class, $label);
            self::assertFalse($label->hasAttribute('data-inline-group'));
            self::assertFalse(
                $label->hasAttribute('data-inline-attribute-targets')
            );
        }
    }

    public function testProductionKeepsLanguageKeysWithoutEditorMetadata(): void
    {
        $_ENV['DEV_MODE'] = 'false';

        $html = controller_art11(0, ['items' => 3]);
        $xpath = $this->createXpath($html);

        foreach ([
            'data-inline-group',
            'data-inline-attribute-targets',
            'data-inline-field-meta',
        ] as $editorAttribute) {
            self::assertStringNotContainsString($editorAttribute, $html);
        }

        foreach (['a', 'b', 'c'] as $letter) {
            $targetKey = "art11_00_{$letter}_target";
            $suffixKey = "art11_00_{$letter}_suffix";
            $labelKey = "art11_00_{$letter}_label";
            $counters = $xpath->query(
                '//span[contains(concat(" ", normalize-space(@class), " "), " stat-number ")]'
                . '[@data-lang="' . $targetKey . '"][@data-target]'
            );

            self::assertNotFalse($counters);
            self::assertCount(1, $counters);

            $counter = $counters->item(0);
            self::assertInstanceOf(DOMElement::class, $counter);
            self::assertCount(
                1,
                $xpath->query('./*[@data-art11-counter-value]', $counter)
            );
            self::assertCount(
                1,
                $xpath->query(
                    './span[contains(concat(" ", normalize-space(@class), " "), " stat-number-suffix ")]'
                    . '[@data-lang="' . $suffixKey . '"]',
                    $counter
                )
            );

            $item = $counter->parentNode;
            self::assertInstanceOf(DOMElement::class, $item);
            self::assertCount(
                1,
                $xpath->query(
                    './span[contains(concat(" ", normalize-space(@class), " "), " stat-label ")]'
                    . '[@data-lang="' . $labelKey . '"]',
                    $item
                )
            );
        }
    }

    public function testCounterScriptUpdatesOnlyTheValueNodeAndOwnsItsLifecycle(): void
    {
        $javascript = $this->readFile(
            self::coreRoot() . '/resources/js/_art11.js'
        );

        $selectorMatches = [];
        self::assertSame(
            1,
            preg_match(
                '/(?:const|let)\s+(?<selector>[A-Za-z_$][A-Za-z0-9_$]*)'
                . '\s*=\s*([\'\"])\[data-art11-counter-value\]\2/',
                $javascript,
                $selectorMatches
            ),
            'art11 must declare a selector for its dedicated value child.'
        );

        $selectorVariable = preg_quote(
            (string) $selectorMatches['selector'],
            '/'
        );
        $valueMatches = [];

        self::assertSame(
            1,
            preg_match(
                '/(?:const|let)\s+(?<value>[A-Za-z_$][A-Za-z0-9_$]*)'
                . '\s*=\s*[A-Za-z_$][A-Za-z0-9_$]*\.querySelector\('
                . '\s*' . $selectorVariable . '\s*\)/',
                $javascript,
                $valueMatches
            ),
            'art11 must resolve a dedicated child for the animated number.'
        );

        $valueVariable = preg_quote((string) $valueMatches['value'], '/');
        self::assertMatchesRegularExpression(
            '/\banimateCounter\s*\([^)]*\b' . $valueVariable . '\b[^)]*\)/',
            $javascript
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bcounter\.textContent\s*=/',
            $javascript,
            'Updating the counter root would remove its editable suffix child.'
        );

        self::assertMatchesRegularExpression(
            '/\bnew\s+MutationObserver\s*\(/',
            $javascript
        );
        self::assertMatchesRegularExpression(
            '/(?:attributeFilter|attributeName)[\s\S]{0,160}[\'\"]data-target[\'\"]/',
            $javascript
        );

        self::assertMatchesRegularExpression(
            '/import\.meta\.hot\s*\.\s*dispose\s*\(/',
            $javascript
        );
        self::assertMatchesRegularExpression(
            '/\.disconnect\s*\(\s*\)/',
            $javascript,
            'The data-target observer must be disconnected during cleanup.'
        );
        self::assertGreaterThanOrEqual(
            2,
            preg_match_all('/\.kill\s*\(\s*\)/', $javascript),
            'Cleanup must stop both active tweens and ScrollTrigger instances.'
        );
        self::assertMatchesRegularExpression(
            '/\bdelete\s+[A-Za-z_$][A-Za-z0-9_$]*\s*\[[^]]+\]/',
            $javascript,
            'Cleanup must remove the state stored on each counter.'
        );

        $disposeMatches = [];
        self::assertSame(
            1,
            preg_match(
                '/import\.meta\.hot\s*\.\s*dispose\s*\(\s*'
                . '(?:\(\s*\)\s*=>\s*)?'
                . '(?<cleanup>[A-Za-z_$][A-Za-z0-9_$]*)\s*'
                . '(?:\(\s*\))?\s*\)/',
                $javascript,
                $disposeMatches
            ),
            'HMR disposal must invoke the same active-counter cleanup.'
        );

        $cleanupVariable = preg_quote(
            (string) $disposeMatches['cleanup'],
            '/'
        );
        self::assertMatchesRegularExpression(
            '/export\s+default\s+function\s+[A-Za-z_$][A-Za-z0-9_$]*'
            . '\s*\([^)]*\)\s*\{\s*' . $cleanupVariable . '\s*\(\s*\)/',
            $javascript,
            'Reinitialisation must clean stale instances before creating new ones.'
        );
    }

    public function testEveryTemplateCatalogKeepsCounterFieldsAsObjects(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                self::coreRoot()
                . "/stubs/App/config/languages/templates/{$language}.json"
            );

            foreach (['a', 'b', 'c'] as $letter) {
                self::assertSame(
                    ['value'],
                    array_keys($catalog["art11_00_{$letter}_target"] ?? []),
                    "{$language}: target {$letter} must keep its value object"
                );

                foreach (['suffix', 'label'] as $field) {
                    self::assertSame(
                        ['text'],
                        array_keys(
                            $catalog["art11_00_{$letter}_{$field}"] ?? []
                        ),
                        "{$language}: {$field} {$letter} must keep its text object"
                    );
                }
            }
        }
    }

    public function testCounterRuntimePreservesEditableMarkupAndCleansReinitialisation(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();

        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js is not available.');
        }

        $process = new Process([
            'node',
            self::coreRoot()
                . '/tests/Resources/fixtures/art11-inline-editor-harness.mjs',
            self::coreRoot(),
        ]);
        $process->setTimeout(20);
        $process->run();

        self::assertTrue(
            $process->isSuccessful(),
            $process->getErrorOutput() . $process->getOutput()
        );

        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('25', $result['animation']['initialValue'] ?? null);
        self::assertSame(0, $result['animation']['rootWrites'] ?? null);
        self::assertSame('+', $result['animation']['suffix'] ?? null);
        self::assertSame(0, $result['animation']['suffixWrites'] ?? null);
        self::assertSame(
            [
                'immediateValue' => '30',
                'nextEnterTarget' => 30,
                'invalidValue' => '0',
            ],
            $result['targetChanges'] ?? null
        );
        self::assertSame(
            [
                'tweenKilled' => true,
                'triggerKilled' => true,
                'observerDisconnected' => true,
            ],
            $result['reinitialisation'] ?? null
        );
        self::assertSame(
            [
                'tweenKilled' => true,
                'triggerKilled' => true,
                'observerDisconnected' => true,
            ],
            $result['finalCleanup'] ?? null
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

    private function loadArt11Globals(): void
    {
        $this->catalog = $this->readJson(
            self::coreRoot()
            . '/stubs/App/config/languages/templates/es.json'
        );

        foreach ($this->catalog as $key => $value) {
            if (!str_starts_with($key, 'art11_')) {
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
        $content = $this->readFile($path);
        $decoded = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded, "{$path} must contain a JSON object");

        return $decoded;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        self::assertIsString($content, "Unable to read {$path}");

        return $content;
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
