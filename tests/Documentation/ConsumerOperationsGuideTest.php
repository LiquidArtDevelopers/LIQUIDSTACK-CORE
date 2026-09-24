<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConsumerOperationsGuideTest extends TestCase
{
    public function testManagedConsumerReadmeKeepsTheOperationalRecipes(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = file_get_contents($root . '/stubs/README.LIQUIDSTACK.md');

        self::assertIsString($readme);

        foreach ([
            'composer require "liquidstack/core:^1.35" --with-all-dependencies',
            'composer require "liquidstack/blog:*"',
            'composer require "liquidstack/commerce:*"',
            'composer liquidstack:migrate --plan --format=json',
            'composer liquidstack:migrate --dry-run --format=json',
            'composer liquidstack:migrate --apply --yes --format=json',
            '### Caso 1: crear contenido en DB local y promoverlo después',
            '### Caso 2: desarrollar desde el principio contra una DB productiva vacía',
            '### Caso 3: añadir módulos a un stack y DB existentes',
            'Media sigue siendo filesystem',
            'composer liquidstack:webadmin:onboard --yes --format=json',
            'php App/tools/update-languages.php global',
            'php App/tools/update-languages.php templates',
            'composer liquidstack:commerce-mail-dispatch',
        ] as $command) {
            self::assertStringContainsString($command, $readme);
        }

        self::assertStringNotContainsString(
            '"liquidstack/core:1.35"',
            $readme
        );
    }

    public function testCoreReadmeKeepsReleaseWorkSeparate(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertIsString($readme);
        self::assertStringContainsString(
            '## Trabajar y publicar cambios en CORE',
            $readme
        );
        self::assertStringContainsString('composer release:prepare', $readme);
        self::assertStringContainsString('composer release', $readme);
        self::assertStringContainsString(
            'stubs/README.LIQUIDSTACK.md',
            $readme
        );
        self::assertStringNotContainsString(
            '## Recetas operativas para proyectos consumidores',
            $readme
        );
    }
}
