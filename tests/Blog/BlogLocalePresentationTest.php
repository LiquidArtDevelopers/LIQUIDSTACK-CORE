<?php

declare(strict_types=1);

use App\Core\Blog\Http\BlogLocalePresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogLocalePresentationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function knownLocales(): iterable
    {
        yield 'Spanish' => ['Español', 'es.svg', 'es'];
        yield 'Basque' => ['Euskera', 'es-pv.svg', 'EU'];
        yield 'English' => ['Inglés', 'gb.svg', ' en '];
    }

    #[DataProvider('knownLocales')]
    public function testKnownLocaleHasAccessibleLabelAndAllowlistedFlag(
        string $label,
        string $filename,
        string $input
    ): void {
        self::assertSame($label, BlogLocalePresentation::label($input));
        self::assertSame(
            '/assets/modules/blog/flags/' . $filename,
            BlogLocalePresentation::flagAsset($input)
        );
    }

    public function testUnknownValidLocaleUsesTextOnlyFallback(): void
    {
        self::assertSame('PT-BR', BlogLocalePresentation::label('pt-br'));
        self::assertNull(BlogLocalePresentation::flagAsset('pt-br'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeLocales(): iterable
    {
        yield 'empty' => [''];
        yield 'path traversal' => ['../../es'];
        yield 'markup' => ['<img src=x>'];
        yield 'oversized' => ['en-' . str_repeat('a', 64)];
    }

    #[DataProvider('unsafeLocales')]
    public function testInvalidLocaleCannotInfluenceLabelsOrAssetPaths(
        string $input
    ): void {
        $label = BlogLocalePresentation::label($input);

        self::assertSame('Idioma desconocido', $label);
        self::assertNull(BlogLocalePresentation::flagAsset($input));
        if ($input !== '') {
            self::assertStringNotContainsString($input, $label);
        }
    }
}
