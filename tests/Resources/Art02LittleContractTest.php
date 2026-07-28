<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/art02little.php';

final class Art02LittleContractTest extends TestCase
{
    private Filesystem $filesystem;
    private string $fixtureRoot;
    private string $previousWorkingDirectory;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->fixtureRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-art02little-'
            . bin2hex(random_bytes(8));
        $this->previousWorkingDirectory = (string) getcwd();

        $templateTarget = $this->fixtureRoot
            . DIRECTORY_SEPARATOR
            . 'App'
            . DIRECTORY_SEPARATOR
            . 'templates'
            . DIRECTORY_SEPARATOR
            . '_art02little.html';

        $this->filesystem->mkdir(dirname($templateTarget));
        $this->filesystem->copy(
            dirname(__DIR__, 2) . '/stubs/App/templates/_art02little.html',
            $templateTarget
        );

        chdir($this->fixtureRoot);
        $GLOBALS['lang'] = 'es';
    }

    protected function tearDown(): void
    {
        chdir($this->previousWorkingDirectory);
        unset($GLOBALS['lang']);
        $this->filesystem->remove($this->fixtureRoot);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function itemCounts(): array
    {
        return [
            'minimum is clamped' => [0, 1],
            'one item' => [1, 1],
            'two items' => [2, 2],
            'three items' => [3, 3],
            'maximum is clamped' => [10, 3],
        ];
    }

    #[DataProvider('itemCounts')]
    public function testControllerExposesTheRenderedItemCount(
        int $requestedItems,
        int $expectedItems
    ): void {
        $html = controller_art02little(0, [
            'items' => $requestedItems,
        ]);

        self::assertStringContainsString(
            "art02little--items-{$expectedItems}",
            $html
        );
        self::assertSame(
            $expectedItems,
            substr_count($html, 'class="art02little-card"')
        );
    }

    public function testDesktopScssDefinesThreeColumnsForThreeItems(): void
    {
        $scss = file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_art02little.scss'
        );

        self::assertIsString($scss);
        self::assertMatchesRegularExpression(
            '/&--items-3\s*\{.*?grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\);/s',
            $scss
        );
    }
}
