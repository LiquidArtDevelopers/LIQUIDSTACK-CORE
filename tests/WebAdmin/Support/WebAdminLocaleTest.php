<?php

declare(strict_types=1);

use App\Core\WebAdmin\Support\WebAdminLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebAdminLocaleTest extends TestCase
{
    #[DataProvider('localeProvider')]
    public function testNormalizesTheSharedMailLocaleSubset(
        string $input,
        string $expected
    ): void {
        self::assertSame($expected, WebAdminLocale::normalize($input));
        self::assertSame(
            $input === $expected,
            WebAdminLocale::isCanonical($input)
        );
    }

    /** @return array<string, array{string, string}> */
    public static function localeProvider(): array
    {
        return [
            'undetermined' => ['und', 'und'],
            'lowercase language' => ['es', 'es'],
            'canonical region' => ['es-ES', 'es-ES'],
            'mixed case canonicalized' => ['ES-es', 'es-ES'],
            'trimmed' => [' en ', 'en'],
            'three-letter unsupported' => ['eng', 'und'],
            'numeric region unsupported' => ['es-419', 'und'],
            'empty' => ['', 'und'],
            'injection' => ["es\r\nBcc", 'und'],
        ];
    }
}
