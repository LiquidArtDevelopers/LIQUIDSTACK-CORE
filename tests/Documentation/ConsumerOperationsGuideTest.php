<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConsumerOperationsGuideTest extends TestCase
{
    public function testCanonicalReadmeKeepsTheConsumerRecipes(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertIsString($readme);

        foreach ([
            'composer create-project liquidstack/base',
            'composer require "liquidstack/blog:*"',
            'composer require "liquidstack/commerce:*"',
            'composer liquidstack:migrate --plan --format=json',
            'composer liquidstack:migrate --dry-run --format=json',
            'composer liquidstack:migrate --apply --yes --format=json',
            'php App/tools/update-languages.php global',
            'php App/tools/update-languages.php templates',
        ] as $command) {
            self::assertStringContainsString($command, $readme);
        }
    }
}
