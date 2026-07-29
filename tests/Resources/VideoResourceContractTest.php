<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class VideoResourceContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $originalCwd = '';
    private array $originalEnv = [];

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['artVideo01', 'artVideo02', 'moduleVideo01'] as $resource) {
            require_once self::coreRoot()
                . "/stubs/App/controllers/{$resource}.php";
        }
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-video-resources-'
            . bin2hex(random_bytes(8));
        $this->originalCwd = (string) getcwd();
        $this->originalEnv = $_ENV;

        foreach (['artVideo01', 'artVideo02', 'moduleVideo01'] as $resource) {
            $target = $this->fixtureRoot
                . "/App/templates/_{$resource}.html";

            $this->filesystem->mkdir(dirname($target));
            $this->filesystem->copy(
                self::coreRoot() . "/stubs/App/templates/_{$resource}.html",
                $target
            );
        }

        chdir($this->fixtureRoot);

        $_ENV['RAIZ']         = 'http://localhost:1309';
        $_ENV['LANG_DEFAULT'] = 'es';
        $_ENV['DEV_MODE']     = 'true';

        $this->setGlobal('lang', 'es');
        $this->loadResourceGlobals();
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

        $_ENV = $this->originalEnv;

        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }

        $this->filesystem->remove($this->fixtureRoot);
    }

    public function testFilesAndArticleSemanticsFollowTheResourceContract(): void
    {
        foreach ([
            'stubs/App/controllers/artVideo01.php',
            'stubs/App/templates/_artVideo01.html',
            'resources/scss/_artVideo01.scss',
            'stubs/App/controllers/artVideo02.php',
            'stubs/App/templates/_artVideo02.html',
            'resources/scss/_artVideo02.scss',
            'stubs/App/controllers/moduleVideo01.php',
            'stubs/App/templates/_moduleVideo01.html',
            'resources/scss/_moduleVideo01.scss',
            'resources/js/_moduleVideo01.js',
            'resources/video/dummy/dummy-es.vtt',
            'resources/video/dummy/dummy-en.vtt',
            'resources/video/dummy/dummy-eu.vtt',
        ] as $relativePath) {
            self::assertFileExists(self::coreRoot() . '/' . $relativePath);
        }

        $html = $this->renderResource('artVideo01', 0, [
            '{content}'        => '<p class="injected-copy">Matrix ipsum.</p>',
            '{video}'          => '<div class="injected-video"></div>',
            '{button-primary}' => '<a class="injected-cta" href="#">Entrar</a>',
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/article'));
        self::assertCount(0, $xpath->query('//section'));
        self::assertCount(0, $xpath->query('//header'));
        self::assertCount(
            0,
            $xpath->query(
                '//*[not(self::div) and ('
                . 'contains(concat(" ", normalize-space(@class), " "), " artVideo01-content ")'
                . ' or contains(concat(" ", normalize-space(@class), " "), " artVideo01-copy ")'
                . ' or contains(concat(" ", normalize-space(@class), " "), " artVideo01-cta ")'
                . ' or contains(concat(" ", normalize-space(@class), " "), " artVideo01-media ")'
                . ')]'
            )
        );
        self::assertCount(1, $xpath->query('//article/h3 | //article/div/h3'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "injected-copy")]'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "injected-video")]'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "injected-cta")]'));
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testColumnArticleKeepsNaturalSemanticsAndResponsiveWidthContract(): void
    {
        $html = $this->renderResource('artVideo02', 0, [
            '{content}'        => '<p class="column-copy">Matrix ipsum.</p>',
            '{video}'          => '<div class="column-video"></div>',
            '{button-primary}' => '<a class="column-cta" href="#">Entrar</a>',
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/article[contains(@class, "artVideo02")]'));
        self::assertCount(0, $xpath->query('//section'));
        self::assertCount(0, $xpath->query('//header'));
        self::assertCount(1, $xpath->query('//h3[contains(@class, "artVideo02-title")]'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "column-copy")]'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "column-video")]'));
        self::assertCount(1, $xpath->query('//*[contains(@class, "column-cta")]'));

        $scaled = $this->renderResource('artVideo02', 0, [
            'header_level' => 2,
        ]);
        $scaledXpath = $this->createXpath($scaled);
        self::assertCount(1, $scaledXpath->query('//h2[contains(@class, "artVideo02-title")]'));
        self::assertCount(0, $scaledXpath->query('//*[contains(@class, "artVideo02-copy")]'));
        self::assertCount(0, $scaledXpath->query('//*[contains(@class, "artVideo02-cta")]'));
        self::assertCount(0, $scaledXpath->query('//*[contains(@class, "artVideo02-media")]'));

        $scss = $this->readFile(
            self::coreRoot() . '/resources/scss/_artVideo02.scss'
        );
        self::assertStringContainsString('flex-direction: column;', $scss);
        self::assertStringContainsString('padding: 3rem c.$padMin;', $scss);
        self::assertStringContainsString('width: 90%;', $scss);
        self::assertStringContainsString('width: c.$anchoMedio;', $scss);
        self::assertSame(1, substr_count($scss, 'width: c.$anchoCorto;'));
    }

    public function testArticleHeadingScalesAndOptionalWrappersStayEmptyFree(): void
    {
        $scaled = $this->renderResource('artVideo01', 0, [
            'header_level' => 2,
            '{content}'    => '<p>Contenido</p>',
            '{video}'      => '<div>Vídeo</div>',
        ]);
        $scaledXpath = $this->createXpath($scaled);

        self::assertCount(1, $scaledXpath->query('//h2[contains(@class, "artVideo01-title")]'));
        self::assertCount(0, $scaledXpath->query('//h3[contains(@class, "artVideo01-title")]'));

        $external = $this->renderResource('artVideo01', 0, [
            '{header-primary}' => '<h4 class="external-heading">Encabezado externo</h4>',
        ]);
        $externalXpath = $this->createXpath($external);

        self::assertCount(1, $externalXpath->query('//h4[contains(@class, "external-heading")]'));
        self::assertCount(0, $externalXpath->query('//*[contains(@class, "artVideo01-copy")]'));
        self::assertCount(0, $externalXpath->query('//*[contains(@class, "artVideo01-cta")]'));
        self::assertCount(0, $externalXpath->query('//*[contains(@class, "artVideo01-media")]'));

        $invalidPosition = $this->renderResource('artVideo01', 0, [
            'media_position' => 'javascript:alert(1)',
        ]);
        self::assertStringContainsString('artVideo01--media-end', $invalidPosition);
        self::assertStringNotContainsString('javascript:', $invalidPosition);
    }

    public function testYoutubeModeIsInertUntilSocialConsentAndEditableOutsideTheIframe(): void
    {
        $html = $this->renderResource('moduleVideo01');
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('//div[contains(@class, "moduleVideo01--youtube")]'));
        self::assertCount(0, $xpath->query('//iframe'));

        $slots = $xpath->query('//*[@data-module-video-youtube]');
        self::assertNotFalse($slots);
        self::assertCount(1, $slots);

        $slot = $slots->item(0);
        self::assertInstanceOf(DOMElement::class, $slot);
        self::assertSame('moduleVideo01_00_youtube', $slot->getAttribute('data-lang'));
        self::assertSame(
            'https://www.youtube-nocookie.com/embed/vKQi3bBA1y8',
            $slot->getAttribute('data-video-src')
        );
        self::assertNotSame('', trim($slot->getAttribute('title')));
        self::assertSame('data-video-src', $slot->getAttribute('data-inline-src-target'));
        self::assertCount(1, $xpath->query('//*[@data-inline-group]'));
        self::assertCount(1, $xpath->query('//*[@data-inline-video]'));
        self::assertCount(1, $xpath->query('//*[@data-lang="moduleVideo01_00_settings"]'));
        self::assertSame(
            'youtube',
            $xpath->query('//*[@data-lang="moduleVideo01_00_settings"]')
                ?->item(0)?->attributes?->getNamedItem('data-video-type')?->nodeValue
        );
        self::assertCount(1, $xpath->query('//video[@data-module-video-local][@hidden]'));
        self::assertCount(1, $xpath->query('//*[@data-module-video-play][@hidden]'));
        $thumbnail = $xpath->query('//*[@data-module-video-thumbnail]')?->item(0);
        self::assertInstanceOf(DOMElement::class, $thumbnail);
        self::assertFalse($thumbnail->hasAttribute('src'));
        self::assertCount(1, $xpath->query('//button[contains(@class, "moduleVideo01-editorHandle")]'));

        $javascript = $this->readFile(
            self::coreRoot() . '/resources/js/_moduleVideo01.js'
        );

        self::assertStringContainsString("readCookie('cookie_social') === 'true'", $javascript);
        self::assertStringContainsString("'cookielad:consent-change'", $javascript);
        self::assertStringContainsString('document.createElement(\'iframe\')', $javascript);
        self::assertStringContainsString('youtube-nocookie.com/embed/', $javascript);
        self::assertStringContainsString('https://i.ytimg.com/vi/', $javascript);
        self::assertStringContainsString('playButton?.addEventListener(', $javascript);
        self::assertStringContainsString('if (!event.ctrlKey)', $javascript);
        self::assertStringContainsString("playbackUrl.searchParams.set('autoplay', '1')", $javascript);
        self::assertStringContainsString('strict-origin-when-cross-origin', $javascript);
        self::assertStringContainsString('currentIframe?.remove()', $javascript);
        self::assertStringContainsString("const ROOT_SELECTOR = '.moduleVideo01'", $javascript);
        self::assertStringContainsString("root.dataset.videoType === 'local'", $javascript);
        self::assertStringContainsString("const LANGUAGE_EVENT = 'app:languagechange'", $javascript);
        self::assertStringContainsString('applyLanguageCatalog(instance, catalog)', $javascript);
        self::assertStringContainsString("localVideo?.querySelectorAll('source[data-lang]')", $javascript);
        self::assertStringContainsString("localVideo?.querySelectorAll('track[data-lang]')", $javascript);
        self::assertStringContainsString('localVideo.load()', $javascript);

        $lockedLocal = $this->renderResource('moduleVideo01', 0, [
            'type' => 'local',
        ]);
        $lockedXpath = $this->createXpath($lockedLocal);
        $lockedRoot = $lockedXpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " moduleVideo01 ")]'
        )?->item(0);
        self::assertInstanceOf(DOMElement::class, $lockedRoot);
        self::assertSame('local', $lockedRoot->getAttribute('data-video-type'));
        self::assertFalse($lockedRoot->hasAttribute('data-lang'));
    }

    public function testLocalModeRendersNativeEditableVideoSourcesAndCaptions(): void
    {
        $html = $this->renderResource('moduleVideo01', 1, [
            'type' => 'local',
        ]);
        $xpath = $this->createXpath($html);

        $videos = $xpath->query('//video[contains(@class, "moduleVideo01-video")]');
        self::assertNotFalse($videos);
        self::assertCount(1, $videos);

        $video = $videos->item(0);
        self::assertInstanceOf(DOMElement::class, $video);
        self::assertTrue($video->hasAttribute('controls'));
        self::assertTrue($video->hasAttribute('playsinline'));
        self::assertSame('metadata', $video->getAttribute('preload'));
        self::assertFalse($video->hasAttribute('autoplay'));
        self::assertFalse($video->hasAttribute('loop'));
        self::assertFalse($video->hasAttribute('muted'));
        self::assertFalse($video->hasAttribute('aria-hidden'));
        self::assertSame('moduleVideo01_01_video', $video->getAttribute('data-lang'));
        self::assertFalse($video->hasAttribute('hidden'));
        self::assertSame(
            'http://localhost:1309/assets/img/dummy/dummy01.avif',
            $video->getAttribute('poster')
        );

        $sources = $xpath->query('//source');
        self::assertNotFalse($sources);
        self::assertCount(2, $sources);
        self::assertSame(
            'video/webm',
            $sources->item(0)?->attributes?->getNamedItem('type')?->nodeValue
        );
        self::assertSame(
            'moduleVideo01_01_webm',
            $sources->item(0)?->attributes?->getNamedItem('data-lang')?->nodeValue
        );
        self::assertSame(
            'video/mp4',
            $sources->item(1)?->attributes?->getNamedItem('type')?->nodeValue
        );
        self::assertSame(
            'moduleVideo01_01_mp4',
            $sources->item(1)?->attributes?->getNamedItem('data-lang')?->nodeValue
        );

        $tracks = $xpath->query('//track');
        self::assertNotFalse($tracks);
        self::assertCount(1, $tracks);

        $track = $tracks->item(0);
        self::assertInstanceOf(DOMElement::class, $track);
        self::assertSame('captions', $track->getAttribute('kind'));
        self::assertSame('es', $track->getAttribute('srclang'));
        self::assertNotSame('', $track->getAttribute('label'));
        self::assertTrue($track->hasAttribute('default'));
        self::assertSame('moduleVideo01_01_captions', $track->getAttribute('data-lang'));

        $inlineEditor = $this->readFile(
            self::coreRoot() . '/resources/js/_inlineEditor.js'
        );
        self::assertStringContainsString(
            'const COMPOUND_WITH_DESCENDANTS = new Set(["A", "VIDEO"])',
            $inlineEditor
        );
        self::assertStringContainsString('case "poster":', $inlineEditor);
        self::assertStringContainsString('data-inline-src-target', $inlineEditor);
        self::assertStringContainsString('data-inline-local-extensions', $inlineEditor);
        self::assertStringContainsString('validateVideoResourceEntries(payload)', $inlineEditor);
        self::assertStringContainsString('normalizeInlineYoutubeUrl', $inlineEditor);
        self::assertStringContainsString('data-inline-attribute-targets', $inlineEditor);
        self::assertStringContainsString('video.load()', $inlineEditor);
    }

    public function testControllersRejectUntrustedYoutubeAndLocalMediaSources(): void
    {
        $this->setGlobal('moduleVideo01_00_youtube', (object) [
            'src'   => 'https://example.com/embed/vKQi3bBA1y8',
            'title' => 'Origen no permitido',
        ]);

        $youtube = $this->renderResource('moduleVideo01', 0, [
            'type' => 'youtube',
        ]);
        $youtubeXpath = $this->createXpath($youtube);
        $slot = $youtubeXpath->query('//*[@data-module-video-youtube]')?->item(0);
        self::assertInstanceOf(DOMElement::class, $slot);
        self::assertSame('', $slot->getAttribute('data-video-src'));
        self::assertStringNotContainsString('example.com', $youtube);

        $this->setGlobal('moduleVideo01_01_mp4', (object) [
            'src' => '%2e%2e/private/video.mp4',
        ]);

        $local = $this->renderResource('moduleVideo01', 1, [
            'type' => 'local',
        ]);
        $localXpath = $this->createXpath($local);
        $mp4Sources = $localXpath->query('//source[@type="video/mp4"]');
        self::assertNotFalse($mp4Sources);
        self::assertCount(1, $mp4Sources);
        self::assertFalse($mp4Sources->item(0)?->hasAttribute('src'));
        self::assertStringNotContainsString('%2e%2e/private', $local);

        $_ENV['DEV_MODE'] = 'false';
        $productionLocal = $this->renderResource('moduleVideo01', 1);
        $productionXpath = $this->createXpath($productionLocal);
        $dynamicMp4 = $productionXpath->query('//source[@type="video/mp4"]');
        self::assertNotFalse($dynamicMp4);
        self::assertCount(1, $dynamicMp4);
        self::assertFalse($dynamicMp4->item(0)?->hasAttribute('src'));
        self::assertCount(
            1,
            $productionXpath->query('//*[@data-module-video-youtube][@hidden]')
        );
        self::assertCount(
            1,
            $productionXpath->query('//video[@data-module-video-local][not(@hidden)]')
        );
        self::assertCount(0, $productionXpath->query('//iframe'));

        $productionYoutube = $this->renderResource('moduleVideo01');
        $productionYoutubeXpath = $this->createXpath($productionYoutube);
        self::assertCount(
            1,
            $productionYoutubeXpath->query('//*[@data-module-video-youtube][not(@hidden)]')
        );
        self::assertCount(
            1,
            $productionYoutubeXpath->query('//video[@data-module-video-local][@hidden]')
        );
        self::assertCount(0, $productionYoutubeXpath->query('//iframe'));

        $lockedProduction = $this->renderResource('moduleVideo01', 1, [
            'type' => 'local',
        ]);
        $lockedProductionXpath = $this->createXpath($lockedProduction);
        self::assertCount(
            0,
            $lockedProductionXpath->query('//*[@data-module-video-youtube]')
        );
        self::assertCount(
            1,
            $lockedProductionXpath->query('//video[@data-module-video-local]')
        );
        self::assertCount(
            0,
            $lockedProductionXpath->query('//source[@type="video/mp4"]')
        );
    }

    public function testShowroomImportsAndEveryTemplateCatalogExposeBothModes(): void
    {
        $scss = $this->readFile(self::coreRoot() . '/src/scss/templates.scss');
        $javascript = $this->readFile(self::coreRoot() . '/src/js/templates.js');
        $showroom = $this->readFile(self::coreRoot() . '/stubs/App/views/_showroom.php');

        self::assertSame(1, substr_count($scss, "@use './resources/artVideo01';"));
        self::assertSame(1, substr_count($scss, "@use './resources/artVideo02';"));
        self::assertSame(1, substr_count($scss, "@use './resources/moduleVideo01';"));
        self::assertSame(
            1,
            substr_count(
                $javascript,
                "import initModuleVideo01 from './resources/_moduleVideo01.js'"
            )
        );
        self::assertSame(1, substr_count($javascript, 'initModuleVideo01()'));
        self::assertSame(2, substr_count($showroom, "controller('artVideo01'"));
        self::assertSame(1, substr_count($showroom, "controller('artVideo02'"));
        self::assertSame(3, substr_count($showroom, "controller('moduleVideo01'"));
        self::assertStringNotContainsString("'type' => 'youtube'", $showroom);
        self::assertStringNotContainsString("'type' => 'local'", $showroom);
        self::assertStringContainsString("controller('moduleParrafo01', 2)", $showroom);
        self::assertStringContainsString("controller('moduleParrafo01', 4)", $showroom);
        self::assertStringContainsString("controller('moduleList01', 3", $showroom);

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                self::coreRoot()
                    . "/stubs/App/config/languages/templates/{$language}.json"
            );

            foreach ([
                'artVideo01_00_headerPrimary',
                'artVideo01_01_headerPrimary',
                'artVideo02_00_headerPrimary',
            ] as $headingKey) {
                self::assertArrayHasKey($headingKey, $catalog);
                self::assertStringContainsString(
                    str_starts_with($headingKey, 'artVideo02')
                        ? 'artVideo02'
                        : 'artVideo01',
                    (string) ($catalog[$headingKey]['text'] ?? '')
                );
            }

            self::assertSame('youtube', $catalog['moduleVideo01_00_settings']['type'] ?? null);
            self::assertSame('local', $catalog['moduleVideo01_01_settings']['type'] ?? null);
            self::assertSame('local', $catalog['moduleVideo01_02_settings']['type'] ?? null);
            self::assertEqualsCanonicalizing(
                ['src', 'title', 'playLabel'],
                array_keys($catalog['moduleVideo01_00_youtube'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src', 'title', 'playLabel'],
                array_keys($catalog['moduleVideo01_01_youtube'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['poster', 'title'],
                array_keys($catalog['moduleVideo01_01_video'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src'],
                array_keys($catalog['moduleVideo01_01_webm'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src'],
                array_keys($catalog['moduleVideo01_01_mp4'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src', 'kind', 'srclang', 'label'],
                array_keys($catalog['moduleVideo01_01_captions'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src', 'title', 'playLabel'],
                array_keys($catalog['moduleVideo01_02_youtube'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['poster', 'title'],
                array_keys($catalog['moduleVideo01_02_video'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src'],
                array_keys($catalog['moduleVideo01_02_webm'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src'],
                array_keys($catalog['moduleVideo01_02_mp4'] ?? [])
            );
            self::assertEqualsCanonicalizing(
                ['src', 'kind', 'srclang', 'label'],
                array_keys($catalog['moduleVideo01_02_captions'] ?? [])
            );
        }
    }

    private function renderResource(
        string $resource,
        int $index = 0,
        array $params = []
    ): string {
        $function = "controller_{$resource}";

        self::assertTrue(
            function_exists($function),
            "Missing controller function {$function}"
        );

        $html = $function($index, $params);
        self::assertIsString($html);

        return $html;
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

        self::assertTrue($loaded, 'El HTML del recurso de vídeo no se pudo analizar');

        return new DOMXPath($document);
    }

    private function loadResourceGlobals(): void
    {
        $catalog = $this->readJson(
            self::coreRoot() . '/stubs/App/config/languages/templates/es.json'
        );

        foreach ($catalog as $key => $value) {
            if (
                str_starts_with($key, 'artVideo01_')
                || str_starts_with($key, 'artVideo02_')
                || str_starts_with($key, 'moduleVideo01_')
                || $key === 'moduleParrafo01_02_p_text'
                || $key === 'moduleParrafo01_04_p_text'
                || str_starts_with($key, 'moduleList01_03_')
                || str_starts_with($key, 'moduleButtonType04_01_')
                || str_starts_with($key, 'moduleButtonType04_02_')
            ) {
                $this->setGlobal($key, json_decode(
                    json_encode($value, JSON_THROW_ON_ERROR),
                    false,
                    512,
                    JSON_THROW_ON_ERROR
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(
            $this->readFile($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded, "El catálogo {$path} no es un objeto");
        return $decoded;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, "No se pudo leer {$path}");
        return $content;
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
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
