<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScssConfigContractTest extends TestCase
{
    public function testEveryResourceUsesOnlyContractV2Variables(): void
    {
        $root = dirname(__DIR__, 2);
        $contract = json_decode(
            (string) file_get_contents(
                $root . '/manifests/scss-config-contract-v2.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $variables = $contract['variables'] ?? null;
        $additiveVariables = $contract['additive_variables'] ?? null;

        self::assertSame(2, $contract['schema'] ?? null);
        self::assertIsArray($variables);
        self::assertCount(42, $variables);
        self::assertIsArray($additiveVariables);
        self::assertCount(26, $additiveVariables);

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
        preg_match_all(
            '/^\s*\$([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*([^;]+);/m',
            $baseConfig,
            $baseValueMatches,
            PREG_SET_ORDER
        );
        $baseValues = [];

        foreach ($baseValueMatches as $match) {
            $baseValues[$match[1]] = trim($match[2]);
        }

        sort($variables, SORT_STRING);
        sort($baseVariables, SORT_STRING);
        self::assertSame(
            $variables,
            $baseVariables,
            'El contrato v2 debe coincidir con el config base de CORE'
        );

        foreach ($additiveVariables as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('name', $entry);
            self::assertArrayHasKey('value', $entry);
            self::assertSame(
                $entry['value'],
                $baseValues[$entry['name']] ?? null,
                sprintf(
                    'El valor aditivo de %s debe coincidir con el config de referencia',
                    $entry['name']
                )
            );
        }

        $usedVariables = [];
        $filesWithLegacyFilters = [];
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

            if (str_contains($scss, 'filterColor')) {
                $filesWithLegacyFilters[] = $item->getFilename();
            }

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
            'Un recurso estándar usa variables fuera del contrato v2'
        );

        $forbiddenColorFamilies = [];
        $legacyFilters = [];

        foreach ($usedVariables as $variable => $filename) {
            if (
                preg_match('/\Acolor([0-9]{2})/', $variable, $match) === 1
                && (int) $match[1] > 3
            ) {
                $forbiddenColorFamilies[$variable] = $filename;
            }

            if (str_starts_with($variable, 'filterColor')) {
                $legacyFilters[$variable] = $filename;
            }
        }

        self::assertSame(
            [],
            $forbiddenColorFamilies,
            'Los recursos estándar solo pueden usar las familias color00 a color03'
        );
        self::assertSame(
            [],
            $legacyFilters,
            'Los recursos deben usar colorNNSVG en lugar de filtros legacy'
        );
        self::assertSame(
            [],
            $filesWithLegacyFilters,
            'Los SCSS canónicos no deben conservar referencias filterColor legacy'
        );
    }

    public function testArt32ExposesAProjectOverrideWithSafeFallback(): void
    {
        $scss = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_art32.scss'
        );

        self::assertStringContainsString(
            '--art32-icon-filter: #{c.$color02SVG};',
            $scss
        );
        self::assertStringContainsString(
            'filter: var(--art32-icon-filter);',
            $scss
        );
        self::assertStringNotContainsString('c.$filterColor02', $scss);
    }

    public function testSecondaryAccentsRemainSafeForLegacyConfigs(): void
    {
        $root = dirname(__DIR__, 2);
        $config = (string) file_get_contents(
            $root . '/src/scss/_config.scss'
        );

        $resources = [
            '_moduleButtonType02.scss' => '--moduleButtonType02-text-color',
            '_moduleH2Type01.scss' => '--moduleH2Type01-accent-color',
            '_moduleList01.scss' => '--moduleList01-marker-color',
        ];

        foreach ($resources as $filename => $property) {
            $scss = (string) file_get_contents(
                $root . '/resources/scss/' . $filename
            );

            self::assertStringContainsString(
                $property,
                $scss
            );
            self::assertStringContainsString(
                '#{c.$color02}',
                $scss,
                "{$filename} debe conservar un fallback visible en configs legacy"
            );
            self::assertStringContainsString(
                "{$property}: #{\$color03};",
                $config,
                "El config v2 debe activar el secundario para {$property}"
            );
        }
    }
}
