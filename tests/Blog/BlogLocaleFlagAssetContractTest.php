<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogLocaleFlagAssetContractTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function requiredFlags(): iterable
    {
        yield 'Spanish' => [
            'es.svg',
            'flag-icons-es',
            'f9cfaff858e95f830733ade9591037b5322dfb5827a53b70956a3d190bb49b9a',
        ];
        yield 'Basque' => [
            'es-pv.svg',
            'flag-icons-es-pv',
            'df3beb6c83af9f45226eeef3f43a2c5b9d2c52017d3b26f57c656632cc6ee1d8',
        ];
        yield 'British English' => [
            'gb.svg',
            'flag-icons-gb',
            'c8be1e7208798a4ae692ee1e937065d498bb29e741943f6172b29118b8ed8066',
        ];
    }

    #[DataProvider('requiredFlags')]
    public function testFlagIsLocalStaticFourByThreeSvg(
        string $filename,
        string $expectedId,
        string $expectedSha256
    ): void {
        $path = $this->assetDirectory() . '/' . $filename;
        $svg = file_get_contents($path);

        self::assertIsString($svg);
        self::assertGreaterThan(0, strlen($svg));
        self::assertLessThan(131_072, strlen($svg));
        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString(
            'id="' . $expectedId . '"',
            $svg
        );
        self::assertStringContainsString('viewBox="0 0 640 480"', $svg);
        self::assertStringEndsWith("</svg>\n", $svg);
        self::assertSame($expectedSha256, hash_file('sha256', $path));

        foreach ([
            '<script',
            '<foreignObject',
            '<image',
            'javascript:',
            ' href=',
            ' xlink:href=',
            ' onload=',
            ' onclick=',
            'url(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $svg);
        }
    }

    public function testPinnedSourceAndLicenseTravelWithRedistributedAssets(): void
    {
        $license = file_get_contents(
            $this->assetDirectory() . '/LICENSE.flag-icons.txt'
        );

        self::assertIsString($license);
        self::assertStringContainsString('flag-icons 7.5.0', $license);
        self::assertStringContainsString(
            'https://github.com/lipis/flag-icons',
            $license
        );
        self::assertStringContainsString(
            'Copyright (c) 2013 Panayiotis Lipiridis',
            $license
        );
        self::assertStringContainsString('The MIT License (MIT)', $license);
    }

    public function testBlogManifestPublishesTheAssetDirectoryAsManagedContent(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 2) . '/modules/blog/module.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains([
            'source' => 'published/assets',
            'target' => 'public/assets/modules/blog',
            'type' => 'dir',
            'policy' => 'managed_hash',
        ], $manifest['project_files']);
    }

    private function assetDirectory(): string
    {
        return dirname(__DIR__, 2)
            . '/modules/blog/published/assets/flags';
    }
}
