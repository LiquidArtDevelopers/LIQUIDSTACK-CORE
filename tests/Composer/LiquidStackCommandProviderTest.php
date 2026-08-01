<?php

declare(strict_types=1);

use App\Core\Composer\LiquidStackCommandProvider;
use App\Core\Composer\Plugin;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider;
use PHPUnit\Framework\TestCase;

final class LiquidStackCommandProviderTest extends TestCase
{
    public function testPluginPublishesSideEffectFreeCommandCapability(): void
    {
        $plugin = new Plugin();

        self::assertInstanceOf(Capable::class, $plugin);
        self::assertSame([
            CommandProvider::class => LiquidStackCommandProvider::class,
        ], $plugin->getCapabilities());
    }

    public function testProviderReturnsTheOperationalCommands(): void
    {
        $provider = new LiquidStackCommandProvider([]);
        $commands = $provider->getCommands();

        self::assertSame([
            'liquidstack:doctor',
            'liquidstack:migrate',
            'liquidstack:webadmin:bootstrap',
            'liquidstack:webadmin:mail:dispatch',
        ], array_map(
            static fn ($command): ?string => $command->getName(),
            $commands
        ));
    }
}
