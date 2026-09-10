<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\Composer;
use Composer\IO\NullIO;
use Composer\Script\Event;
use RuntimeException;

final class ManagedProjectSyncRuntime
{
    public function __construct(
        private readonly ManagedFileSynchronizer $synchronizer,
        private readonly bool $standardResourcesReady = true
    ) {
        if (!$this->standardResourcesReady) {
            $this->synchronizer->block(
                'sync.scss_contract_not_satisfied'
            );
        }
    }

    public static function fromComposer(Composer $composer): self
    {
        $io = new NullIO();
        $vendorDir = rtrim(
            (string) $composer->getConfig()->get('vendor-dir'),
            '/\\'
        );
        if ($vendorDir === '') {
            throw new RuntimeException('sync.project_not_resolved');
        }

        $projectRoot = dirname($vendorDir);
        $coreRoot = dirname(__DIR__, 3);
        $scssReady = (new ScssConfigContractSynchronizer($io))->isSatisfied(
            $projectRoot . '/src/scss/_config.scss',
            $coreRoot . '/manifests/scss-config-contract-v2.json'
        );
        $event = new Event(
            'liquidstack:sync',
            $composer,
            $io
        );

        $synchronizer = Installer::prepareManagedProjectFiles(
            $event,
            $scssReady
        );
        $synchronizer->bindPlanInput(
            'scss_config',
            $projectRoot . '/src/scss/_config.scss'
        );
        $synchronizer->bindPlanInput(
            'scss_contract',
            $coreRoot . '/manifests/scss-config-contract-v2.json'
        );

        return new self($synchronizer, $scssReady);
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        $catalog = $this->synchronizer->catalog();
        $catalog['standard_resources_ready'] = $this->standardResourcesReady;
        $catalog['blockers'] = $this->blockers(
            $catalog['blockers'] ?? []
        );

        return $catalog;
    }

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $preview = $this->synchronizer->preview();
        $preview['standard_resources_ready'] =
            $this->standardResourcesReady;
        $preview['blockers'] = $this->blockers(
            $preview['blockers'] ?? []
        );
        if (!$this->standardResourcesReady) {
            $preview['status'] = 'blocked';
        }

        return $preview;
    }

    public function apply(string $expectedPlanHash): void
    {
        $this->synchronizer->apply($expectedPlanHash);
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->synchronizer->stats();
    }

    /** @return list<string> */
    private function blockers(array $synchronizerBlockers = []): array
    {
        if (!$this->standardResourcesReady) {
            $synchronizerBlockers[] = 'sync.scss_contract_not_satisfied';
        }
        $blockers = array_values(array_unique(array_map(
            'strval',
            $synchronizerBlockers
        )));
        sort($blockers, SORT_STRING);

        return $blockers;
    }
}
