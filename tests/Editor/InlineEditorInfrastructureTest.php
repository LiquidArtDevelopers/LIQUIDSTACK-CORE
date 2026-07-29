<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InlineEditorInfrastructureTest extends TestCase
{
    private string $fixtureRoot;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-inline-editor-'
            . bin2hex(random_bytes(8));

        $languageDir = $this->fixtureRoot
            . '/App/config/languages/templates';
        $this->filesystem->mkdir([
            $this->fixtureRoot . '/App/app',
            $languageDir,
        ]);
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/app/updateLanguage.php',
            $this->fixtureRoot . '/App/app/updateLanguage.php'
        );
        $this->filesystem->dumpFile(
            $languageDir . '/es.json',
            "{\n    \"existing\": {\n        \"text\": \"Antes\"\n    }\n}\n"
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testLanguageEndpointSupportsSingleAndBatchUpdates(): void
    {
        $singleResponse = $this->runEndpoint([
            'lang'   => 'es',
            'scope'  => 'templates',
            'key'    => 'single_key',
            'values' => ['text' => 'Actualizado'],
        ]);

        self::assertSame('ok', $singleResponse['status']);
        self::assertSame('single_key', $singleResponse['key']);
        self::assertSame(['text' => 'Actualizado'], $singleResponse['data']);

        $batchResponse = $this->runEndpoint([
            'lang'    => 'es',
            'scope'   => 'templates',
            'updates' => [
                [
                    'key'    => 'moduleList01_00_a_li_text',
                    'values' => ['text' => 'Primera línea'],
                ],
                [
                    'key'    => 'moduleList01_00_marker_icon',
                    'values' => 'star',
                ],
            ],
        ]);

        self::assertSame('ok', $batchResponse['status']);
        self::assertCount(2, $batchResponse['updates']);

        $language = json_decode(
            (string) file_get_contents(
                $this->fixtureRoot
                . '/App/config/languages/templates/es.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('Antes', $language['existing']['text']);
        self::assertSame('Actualizado', $language['single_key']['text']);
        self::assertSame(
            'Primera línea',
            $language['moduleList01_00_a_li_text']['text']
        );
        self::assertSame('star', $language['moduleList01_00_marker_icon']);
    }

    public function testLanguageEndpointPreservesAnInvalidCatalog(): void
    {
        $languagePath = $this->fixtureRoot
            . '/App/config/languages/templates/es.json';
        $invalidJson = "{\n    \"broken\":\n";
        $this->filesystem->dumpFile($languagePath, $invalidJson);

        $response = $this->runEndpoint([
            'lang'   => 'es',
            'scope'  => 'templates',
            'key'    => 'must_not_be_written',
            'values' => ['text' => 'No escribir'],
        ]);

        self::assertSame('error', $response['status']);
        self::assertStringContainsString(
            'invalid JSON',
            $response['message']
        );
        self::assertSame($invalidJson, file_get_contents($languagePath));
    }

    public function testLanguageEndpointRemainsDisabledOutsideDevelopment(): void
    {
        $response = $this->runEndpoint([
            'lang'   => 'es',
            'scope'  => 'templates',
            'key'    => 'must_not_be_written',
            'values' => ['text' => 'No escribir'],
        ], false);

        self::assertSame('error', $response['status']);
        self::assertStringContainsString(
            'only available in development mode',
            $response['message']
        );
    }

    public function testInlineEditorPrioritizesCollectionsAndEditableBackgrounds(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/_inlineEditor.js'
        );

        self::assertStringContainsString(
            'const saveBatchValues = async',
            $source
        );
        self::assertStringContainsString(
            "event.target.closest('[data-inline-collection=\"lines\"]')",
            $source
        );
        self::assertStringContainsString(
            'event.target.closest("[data-inline-background]")',
            $source
        );
        self::assertStringContainsString(
            'if (!event.ctrlKey || isOpen)',
            $source
        );
        self::assertLessThan(
            strpos($source, 'event.target.closest("[data-inline-background]")'),
            strpos(
                $source,
                "event.target.closest('[data-inline-collection=\"lines\"]')"
            )
        );
    }

    public function testInlineEditorSupportsOptInGroupsWithoutDuplicatingHmrHandlers(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/_inlineEditor.js'
        );

        self::assertStringContainsString(
            'event.target.closest("[data-inline-group]")',
            $source
        );
        self::assertStringContainsString(
            'group.querySelectorAll("[data-lang]")',
            $source
        );
        self::assertStringContainsString(
            'const INLINE_EDITOR_HANDLER_KEY =',
            $source
        );
        self::assertStringContainsString(
            'const previousHandlers = window[INLINE_EDITOR_HANDLER_KEY];',
            $source
        );
        self::assertStringContainsString(
            'document.removeEventListener("dblclick", previousHandlers.doubleClick);',
            $source
        );
        self::assertStringContainsString(
            'document.removeEventListener("click", previousHandlers.anchorClick, true);',
            $source
        );
        self::assertStringContainsString(
            '"app:languagechange",',
            $source
        );
        self::assertStringContainsString(
            'previousHandlers.languageChange,',
            $source
        );
        self::assertStringContainsString(
            'window[INLINE_EDITOR_HANDLER_KEY] = {',
            $source
        );
        self::assertStringContainsString(
            'anchorClick: handleInlineEditorAnchorClick,',
            $source
        );
        self::assertStringContainsString(
            'languageChange: handleInlineEditorLanguageChange,',
            $source
        );
        self::assertStringContainsString(
            'document.addEventListener("dblclick", handleInlineEditorDoubleClick);',
            $source
        );
    }

    public function testExistingResourcesExposeTheirEditorContracts(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $art16Controller = (string) file_get_contents(
            $coreRoot . '/stubs/App/controllers/art16.php'
        );
        $hero00Controller = (string) file_get_contents(
            $coreRoot . '/stubs/App/controllers/hero00.php'
        );
        $moduleListController = (string) file_get_contents(
            $coreRoot . '/stubs/App/controllers/moduleList01.php'
        );

        self::assertStringContainsString(
            'data-inline-background-target=".bg"',
            $art16Controller
        );
        self::assertStringContainsString(
            'data-inline-background-desktop-key="',
            $art16Controller
        );
        self::assertStringContainsString(
            'data-inline-background-fallback-key="hero00_bg_fallback"',
            $hero00Controller
        );
        foreach ([
            'hero00_bg_mobile',
            'hero00_bg_tablet',
            'hero00_bg_desktop',
            'hero00_bg_fallback',
        ] as $key) {
            self::assertStringContainsString(
                "\$GLOBALS['{$key}']->src",
                $hero00Controller
            );
        }
        self::assertStringNotContainsString(
            '$GLOBALS[$key]',
            $hero00Controller
        );
        self::assertStringContainsString(
            'data-inline-collection="lines"',
            $moduleListController
        );
        self::assertStringContainsString(
            'data-inline-icon-options="',
            $moduleListController
        );
        self::assertStringContainsString(
            '{editor-attributes}',
            (string) file_get_contents(
                $coreRoot . '/stubs/App/templates/_art16.html'
            )
        );
        self::assertStringContainsString(
            '{editor-attributes}',
            (string) file_get_contents(
                $coreRoot . '/stubs/App/templates/_hero00.html'
            )
        );
        self::assertStringContainsString(
            '{editor-attributes}',
            (string) file_get_contents(
                $coreRoot . '/stubs/App/templates/_moduleList01.html'
            )
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function runEndpoint(array $payload, bool $devMode = true): array
    {
        $endpoint = $this->fixtureRoot . '/App/app/updateLanguage.php';
        $bootstrap = '$_ENV["DEV_MODE"] = '
            . var_export($devMode ? 'true' : 'false', true)
            . ';'
            . '$_POST = ' . var_export($payload, true) . ';'
            . 'require ' . var_export($endpoint, true) . ';';

        $process = proc_open(
            [PHP_BINARY, '-r', $bootstrap],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
