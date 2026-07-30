<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileRegistry;
use PHPUnit\Framework\TestCase;

final class ManagedFileRegistryTest extends TestCase
{
    public function testTextFingerprintsIgnoreOnlyTrailingEofWhitespace(): void
    {
        $path = 'resources/scss/_sample.scss';
        $withoutNewline = ".sample {\n  color: red;\n}";
        $withNewline = $withoutNewline . "\n";
        $withBlankLines = $withoutNewline . "\r\n \r\n\t\r\n";

        $shared = array_intersect(
            ManagedFileRegistry::fingerprintContents(
                $path,
                $withoutNewline
            ),
            ManagedFileRegistry::fingerprintContents(
                $path,
                $withNewline
            ),
            ManagedFileRegistry::fingerprintContents(
                $path,
                $withBlankLines
            )
        );

        self::assertSame(
            [
                'sha256:' . hash('sha256', $withNewline),
            ],
            array_values($shared)
        );
    }

    public function testTextFingerprintsKeepRealContentChangesDistinct(): void
    {
        $path = 'resources/scss/_sample.scss';
        $original = ".sample {\n\n  color: red;\n}";
        $changed = ".sample {\n  color: red;\n}";

        self::assertSame(
            [],
            array_intersect(
                ManagedFileRegistry::fingerprintContents(
                    $path,
                    $original
                ),
                ManagedFileRegistry::fingerprintContents(
                    $path,
                    $changed
                )
            )
        );
    }
}
