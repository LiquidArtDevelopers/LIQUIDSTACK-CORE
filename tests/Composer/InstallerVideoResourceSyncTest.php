<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/src/Core/Composer/Installer.php';

final class InstallerVideoResourceSyncTest extends TestCase
{
    private const RESOURCE_TARGET_ENV = [
        'STACK_CORE_RESOURCES_IMG_TARGET',
        'STACK_LIQUID_CORE_RESOURCES_IMG_TARGET',
        'STACK_CORE_RESOURCES_VIDEO_TARGET',
        'STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET',
    ];

    private string $projectRoot;
    private Filesystem $filesystem;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->filesystem  = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-video-sync-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir($this->projectRoot . DIRECTORY_SEPARATOR . 'vendor');
        $this->writeFile(
            $this->projectRoot . '/src/scss/_config.scss',
            (string) file_get_contents(
                dirname(__DIR__, 2) . '/src/scss/_config.scss'
            )
        );

        foreach (self::RESOURCE_TARGET_ENV as $environmentName) {
            $this->previousEnvironment[$environmentName] = getenv($environmentName);
            putenv($environmentName);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $environmentName => $value) {
            if ($value === false) {
                putenv($environmentName);
                continue;
            }

            putenv($environmentName . '=' . $value);
        }

        $this->filesystem->remove($this->projectRoot);
    }

    public function testVideoResourcesAreSyncedWithoutDeletingProjectFiles(): void
    {
        $videoTarget = $this->projectRoot . '/public/assets/video';
        $this->writeFile(
            $videoTarget . '/customer/project-only.mp4',
            'private project video'
        );
        $this->writeFile(
            $videoTarget . '/dummy/project-only.vtt',
            'WEBVTT project-only'
        );

        Installer::syncResources($this->createEvent());

        $coreVideoRoot = dirname(__DIR__, 2) . '/resources/video/dummy';

        foreach ([
            'dummy.mp4',
            'dummy.webm',
            'dummy-es.vtt',
            'dummy-en.vtt',
            'dummy-eu.vtt',
        ] as $asset) {
            self::assertFileEquals(
                $coreVideoRoot . '/' . $asset,
                $videoTarget . '/dummy/' . $asset
            );
        }

        foreach ([
            'bookmark-outline.svg',
            'check-ERROR.svg',
            'checkmark-sharp.svg',
            'circle1.svg',
            'enviado.jpg',
            'hidePassword.svg',
            'mail.svg',
            'showPassword.svg',
            'tel.svg',
            'web.svg',
            'wp.svg',
        ] as $asset) {
            self::assertFileEquals(
                dirname(__DIR__, 2) . '/resources/img/system/' . $asset,
                $this->projectRoot . '/public/assets/img/system/' . $asset
            );
        }

        self::assertFileEquals(
            dirname(__DIR__, 2) . '/resources/img/logos/logo-black.svg',
            $this->projectRoot . '/public/assets/img/logos/logo-black.svg'
        );
        self::assertSame(
            'private project video',
            file_get_contents($videoTarget . '/customer/project-only.mp4')
        );
        self::assertSame(
            'WEBVTT project-only',
            file_get_contents($videoTarget . '/dummy/project-only.vtt')
        );
    }

    public function testConfiguredVideoTargetTakesPrecedenceOverLegacyAlias(): void
    {
        putenv('STACK_CORE_RESOURCES_VIDEO_TARGET=runtime/core-video');
        putenv('STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET=runtime/legacy-video');

        Installer::syncResources($this->createEvent());

        self::assertFileEquals(
            dirname(__DIR__, 2) . '/resources/video/dummy/dummy-es.vtt',
            $this->projectRoot . '/runtime/core-video/dummy/dummy-es.vtt'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/runtime/legacy-video/dummy/dummy-es.vtt'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/public/assets/video/dummy/dummy-es.vtt'
        );
    }

    public function testLegacyVideoTargetAliasRemainsSupported(): void
    {
        putenv('STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET=runtime/legacy-video');

        Installer::syncResources($this->createEvent());

        self::assertFileEquals(
            dirname(__DIR__, 2) . '/resources/video/dummy/dummy-eu.vtt',
            $this->projectRoot . '/runtime/legacy-video/dummy/dummy-eu.vtt'
        );
        self::assertFileDoesNotExist(
            $this->projectRoot . '/public/assets/video/dummy/dummy-eu.vtt'
        );
    }

    private function createEvent(): Event
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge([
            'config' => [
                'vendor-dir' => $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor',
            ],
        ]);

        $composer = new Composer();
        $composer->setConfig($config);

        return new Event('test-video-resource-sync', $composer, new BufferIO());
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
