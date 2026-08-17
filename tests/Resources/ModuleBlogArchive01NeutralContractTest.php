<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class ModuleBlogArchive01NeutralContractTest extends TestCase
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

    public function testItAlwaysUsesANeutralRootAndKeepsItsHeading(): void
    {
        $html = controller('moduleBlogArchive01', 2, [
            'header_level' => 3,
            'header_text' => 'Archivo de noticias',
            'periods_data' => [
                self::period(1, true),
                self::period(2, true),
                self::period(3),
            ],
        ]);
        $xpath = self::xpath($html);

        self::assertSame(1, $xpath->query(
            '/html/body/div[@id="moduleBlogArchive01-02"]'
        )->length);
        self::assertSame(0, $xpath->query('//nav')->length);
        self::assertSame(0, $xpath->query('//*[@role]')->length);
        self::assertSame(1, $xpath->query(
            '/html/body/div/h3[@id="moduleBlogArchive01-02-heading"]'
        )->length);
        self::assertSame('', $xpath->evaluate(
            'string(/html/body/div/@aria-labelledby)'
        ));
        self::assertSame(1, $xpath->query(
            '/html/body/div[contains(concat(" ", normalize-space(@class), " "), '
                . '" moduleBlogArchive01 ")]'
        )->length);
        self::assertSame(3, $xpath->query('/html/body/div/ol/li/a')->length);
        self::assertSame(1, $xpath->query(
            '/html/body/div/ol/li/a[@aria-current="date"]'
        )->length);
        self::assertStringContainsString(
            'href="/es/noticias?year=2026&amp;month=1"',
            $html
        );
        self::assertStringContainsString('Archivo de noticias', $html);
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testItSelfContainsZeroOneAndManyStates(): void
    {
        self::assertSame('', controller('moduleBlogArchive01', 0, [
            'periods_data' => [],
        ]));

        foreach ([1, 4] as $count) {
            $periods = [];
            for ($position = 1; $position <= $count; ++$position) {
                $periods[] = self::period($position, $position === 1);
            }

            $html = controller('moduleBlogArchive01', $count, [
                'header_text' => 'Archivo',
                'periods_data' => $periods,
            ]);
            $xpath = self::xpath($html);

            self::assertSame(1, $xpath->query('/html/body/div')->length);
            self::assertSame(1, $xpath->query(
                '/html/body/div/h3[@id="moduleBlogArchive01-'
                    . sprintf('%02d', $count) . '-heading"]'
            )->length);
            self::assertSame(
                $count,
                $xpath->query('/html/body/div/ol/li')->length
            );
            self::assertSame(1, $xpath->query(
                '/html/body/div/ol/li/a[@aria-current="date"]'
            )->length);
        }
    }

    public function testItsTemplateHasNoAlternativeLandmarkMode(): void
    {
        $controller = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/App/controllers/moduleBlogArchive01.php'
        );
        $template = trim((string) file_get_contents(
            self::moduleProjectRoot()
                . '/App/templates/_moduleBlogArchive01.html'
        ));

        self::assertStringStartsWith('<div ', $template);
        self::assertStringEndsWith('</div>', $template);
        self::assertStringNotContainsString('<nav', $template);
        self::assertStringNotContainsString('aria-labelledby', $template);
        self::assertStringNotContainsString('render_mode', $controller);
        self::assertStringNotContainsString('{root-tag}', $template);
    }

    public function testNeutralStylesOwnTheirCenteredContentWidth(): void
    {
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_moduleBlogArchive01.scss'
        );

        self::assertMatchesRegularExpression(
            '/\.moduleBlogArchive01\s*\{'
                . '(?=[^}]*\bbox-sizing:\s*border-box;)'
                . '(?=[^}]*\bmin-width:\s*0;)'
                . '(?=[^}]*\bwidth:\s*min\('
                . 'calc\(100%\s*-\s*\(2\s*\*\s*c\.\$padMin\)\),'
                . '\s*c\.\$textMax\);)'
                . '(?=[^}]*\bmargin-inline:\s*auto;)'
                . '(?=[^}]*\bpadding:\s*0;)'
                . '(?=[^}]*\bborder:\s*0;)'
                . '(?=[^}]*\bborder-radius:\s*0;)'
                . '(?=[^}]*\bbackground:\s*transparent;)/s',
            $scss
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*c\.\$tablet\)\s*\{'
                . '.*?width:\s*min\('
                . 'calc\(100%\s*-\s*\(2\s*\*\s*c\.\$padMax\)\),'
                . '\s*c\.\$textMax\);/s',
            $scss
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*c\.\$desktop\)\s*\{'
                . '.*?\.moduleBlogArchive01-item\s*\{'
                . '.*?grid-column:\s*span\s+4;'
                . '.*?&:last-child:nth-child\(odd\)\s*\{'
                . '\s*grid-column:\s*span\s+4;\s*\}'
                . '.*?&:last-child:nth-child\(3n\s*\+\s*1\)'
                . '.*?grid-column:\s*5\s*\/\s*span\s+4;'
                . '.*?&:nth-last-child\(2\):nth-child\(3n\s*\+\s*1\)'
                . '.*?grid-column:\s*3\s*\/\s*span\s+4;'
                . '.*?&:last-child:nth-child\(3n\s*\+\s*2\)'
                . '.*?grid-column:\s*7\s*\/\s*span\s+4;/s',
            $scss
        );
        self::assertStringNotContainsString('&--embedded', $scss);
    }

    /** @return array{url:string,label:string,count:int,active?:bool} */
    private static function period(int $month, bool $active = false): array
    {
        $period = [
            'url' => '/es/noticias?year=2026&month=' . $month,
            'label' => 'Mes ' . $month . ' de 2026',
            'count' => $month,
        ];
        if ($active) {
            $period['active'] = true;
        }

        return $period;
    }

    private static function xpath(string $html): DOMXPath
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
