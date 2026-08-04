<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Composer\Command\DoctorCommand;
use App\Core\Composer\Command\BlogSitemapCacheInitCommand;
use App\Core\Composer\Command\BlogAnalyticsPurgeCommand;
use App\Core\Composer\Command\MediaInitCommand;
use App\Core\Composer\Command\MigrateCommand;
use App\Core\Composer\Command\WebAdminBootstrapCommand;
use App\Core\Composer\Command\WebAdminMailDispatchCommand;
use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider;

final class LiquidStackCommandProvider implements CommandProvider
{
    /**
     * Composer passes its runtime services in this forward-compatible bag.
     * Command construction must remain free of project, config and DB access.
     *
     * @param array<string, mixed> $arguments
     */
    public function __construct(array $arguments)
    {
    }

    /**
     * @return list<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new DoctorCommand(),
            new MigrateCommand(),
            new MediaInitCommand(),
            new BlogSitemapCacheInitCommand(),
            new BlogAnalyticsPurgeCommand(),
            new WebAdminBootstrapCommand(),
            new WebAdminMailDispatchCommand(),
        ];
    }
}
