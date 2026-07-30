<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScssConfigContractTest extends TestCase
{
    public function testEveryResourceUsesOnlyContractV1Variables(): void
    {
        $root = dirname(__DIR__, 2);
        $contract = json_decode(
            (string) file_get_contents(
                $root . '/manifests/scss-config-contract-v1.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $variables = $contract['variables'] ?? null;

        self::assertSame(1, $contract['schema'] ?? null);
        self::assertIsArray($variables);
        self::assertCount(40, $variables);

        $baseConfig = (string) file_get_contents(
            $root . '/src/scss/_config.scss'
        );
        preg_match_all(
            '/^\s*\$([A-Za-z_][A-Za-z0-9_-]*)\s*:/m',
            $baseConfig,
            $baseMatches
        );
        $baseVariables = array_values(array_unique(
            $baseMatches[1] ?? []
        ));

        sort($variables, SORT_STRING);
        sort($baseVariables, SORT_STRING);
        self::assertSame(
            $variables,
            $baseVariables,
            'El contrato v1 debe coincidir con el config base de CORE'
        );

        $usedVariables = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root . '/resources/scss',
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'scss') {
                continue;
            }

            $scss = (string) file_get_contents($item->getPathname());
            preg_match_all(
                '/c\.\$([A-Za-z_][A-Za-z0-9_-]*)/',
                $scss,
                $matches
            );

            foreach ($matches[1] ?? [] as $variable) {
                $usedVariables[$variable] = $item->getFilename();
            }
        }

        $outsideContract = array_diff(
            array_keys($usedVariables),
            $variables
        );

        self::assertSame(
            [],
            array_values($outsideContract),
            'Un recurso estándar usa variables fuera del contrato v1'
        );
    }

    public function testArt32ExposesAProjectOverrideWithSafeFallback(): void
    {
        $scss = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_art32.scss'
        );

        self::assertStringContainsString(
            '--art32-icon-filter: #{c.$filterColor02};',
            $scss
        );
        self::assertStringContainsString(
            'filter: var(--art32-icon-filter);',
            $scss
        );
        self::assertStringNotContainsString('c.$color03SVG', $scss);
    }
}
