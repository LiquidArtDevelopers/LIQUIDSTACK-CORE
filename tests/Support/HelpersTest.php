<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function App\Core\Support\homePath;
use function App\Core\Support\resolve_localized_href;

final class HelpersTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        unset($GLOBALS['lang']);
    }

    public function testHomePathUsesSimplifiedDefaultLanguage(): void
    {
        $_ENV['LANG_DEFAULT']    = 'es';
        $_ENV['ES_SIMPLIFICADO'] = '1';

        self::assertSame('/', homePath('es'));
        self::assertSame('/eu', homePath('eu'));
    }

    public function testResolveLocalizedHrefBuildsAbsolutePathWithLanguage(): void
    {
        $_ENV['RAIZ'] = 'https://example.test';
        $GLOBALS['lang'] = 'eu';

        $href = resolve_localized_href('contacto');

        self::assertSame('https://example.test/eu/contacto', $href);
    }

    public function testResolveLocalizedHrefRespectsAnchorsAndRelativeOutput(): void
    {
        $empty = resolve_localized_href('', [
            'absolute'      => false,
            'include_lang'  => false,
            'leading_slash' => false,
        ]);

        self::assertSame('', $empty);
        self::assertSame('#hero', resolve_localized_href('#hero'));
    }
}
