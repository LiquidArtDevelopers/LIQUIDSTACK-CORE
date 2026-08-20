<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LiquidStackDevLauncherTest extends TestCase
{
    public function testLauncherSelectsPortsInjectsOriginsAndCleansUp(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no esta disponible.');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([
            'node',
            __DIR__ . '/fixtures/liquidstack-dev-launcher-harness.mjs',
            $root . '/stubs/App/tools/liquidstack-dev.mjs',
            $root . '/stubs/App/tools/php-dev-router.php',
            PHP_BINARY,
        ], $root);
        $process->setTimeout(20.0);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getErrorOutput() . "\n" . $process->getOutput()
        );
        self::assertStringContainsString(
            'liquidstack-dev launcher harness: OK',
            $process->getOutput()
        );
    }
}
