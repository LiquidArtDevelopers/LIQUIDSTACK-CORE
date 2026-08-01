<?php

namespace App\Core\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // No initialization required.
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No teardown actions required.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No uninstall actions required.
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_COMMAND_RUN => 'onPreCommandRun',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD  => 'onPostUpdate',
        ];
    }

    /**
     * @return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => LiquidStackCommandProvider::class,
        ];
    }

    public function onPreCommandRun(PreCommandRunEvent $event): void
    {
        if ($event->getCommand() !== 'require') {
            return;
        }

        $input = $event->getInput();
        if (!$input->hasArgument('packages')) {
            return;
        }

        $packages = $input->getArgument('packages');
        if (!is_array($packages)) {
            return;
        }

        $input->setArgument(
            'packages',
            ModuleRequirementNormalizer::normalize($packages)
        );
    }

    public function onPostInstall(Event $event): void
    {
        Installer::postInstall($event);
    }

    public function onPostUpdate(Event $event): void
    {
        Installer::postUpdate($event);
    }
}
