<?php

namespace App\Core\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class Plugin implements PluginInterface, EventSubscriberInterface
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
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD  => 'onPostUpdate',
        ];
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
