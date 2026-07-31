<?php

declare(strict_types=1);

use App\Core\Composer\ModuleRequirementNormalizer;
use App\Core\Composer\Plugin;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreCommandRunEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;

final class PluginModuleAliasTest extends TestCase
{
    public function testRequireNormalizesOnlyLogicalSelectorsWithoutConstraint(): void
    {
        $input = $this->input([
            'liquidstack/blog',
            'liquidstack/webadmin',
            'liquidstack/blog:^1.8',
            'liquidstack/webadmin=1.8.0',
            'vendor/package',
        ]);
        $event = new PreCommandRunEvent(
            PluginEvents::PRE_COMMAND_RUN,
            $input,
            'require'
        );

        $plugin = new Plugin();
        $plugin->onPreCommandRun($event);
        $plugin->onPreCommandRun($event);

        self::assertSame([
            'liquidstack/blog:*',
            'liquidstack/webadmin:*',
            'liquidstack/blog:^1.8',
            'liquidstack/webadmin=1.8.0',
            'vendor/package',
        ], $input->getArgument('packages'));
    }

    public function testOtherComposerCommandsRemainUntouched(): void
    {
        $input = $this->input(['liquidstack/blog']);
        $event = new PreCommandRunEvent(
            PluginEvents::PRE_COMMAND_RUN,
            $input,
            'update'
        );

        (new Plugin())->onPreCommandRun($event);

        self::assertSame(
            ['liquidstack/blog'],
            $input->getArgument('packages')
        );
    }

    public function testUnrelatedRequireDoesNotLoadModuleCatalog(): void
    {
        self::assertSame(
            ['vendor/package'],
            ModuleRequirementNormalizer::normalize(
                ['vendor/package'],
                'Z:/catalog-that-must-not-be-read'
            )
        );
    }

    /**
     * @param list<string> $packages
     */
    private function input(array $packages): ArrayInput
    {
        $definition = new InputDefinition([
            new InputArgument(
                'packages',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL
            ),
        ]);

        return new ArrayInput(['packages' => $packages], $definition);
    }
}
