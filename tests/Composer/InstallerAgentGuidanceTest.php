<?php

declare(strict_types=1);

use App\Core\Composer\Installer;
use App\Core\Composer\Plugin;
use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Plugin\PluginEvents;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/src/Core/Composer/Installer.php';
require_once dirname(__DIR__, 2) . '/src/Core/Composer/Plugin.php';

final class InstallerAgentGuidanceTest extends TestCase
{
    private string $projectRoot;
    private Filesystem $filesystem;
    /** @var list<string> */
    private array $redirectPaths = [];
    /** @var list<string> */
    private array $externalRoots = [];

    protected function setUp(): void
    {
        $this->filesystem  = new Filesystem();
        $this->projectRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-agent-guidance-'
            . bin2hex(random_bytes(8));

        $this->filesystem->mkdir($this->projectRoot . DIRECTORY_SEPARATOR . 'vendor');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->redirectPaths) as $redirectPath) {
            if (DIRECTORY_SEPARATOR === '\\' && is_dir($redirectPath)) {
                @rmdir($redirectPath);
            } elseif (is_link($redirectPath)) {
                @unlink($redirectPath);
            } elseif (is_dir($redirectPath)) {
                @rmdir($redirectPath);
            }
        }

        $this->filesystem->remove($this->projectRoot);

        foreach ($this->externalRoots as $externalRoot) {
            $this->filesystem->remove($externalRoot);
        }
    }

    public function testPluginSubscribesComposerLifecycleAndRequireEvents(): void
    {
        self::assertSame([
            PluginEvents::PRE_COMMAND_RUN => 'onPreCommandRun',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstall',
            ScriptEvents::POST_UPDATE_CMD  => 'onPostUpdate',
        ], Plugin::getSubscribedEvents());
    }

    public function testNewProjectUsesCodexSkillsPathAndInstallsDefaultConfig(): void
    {
        [$event] = $this->createEvent();

        Installer::syncAgentGuidance($event);

        self::assertFileEquals(
            dirname(__DIR__, 2) . '/.codex/config.toml',
            $this->projectRoot . '/.codex/config.toml'
        );
        self::assertFileEquals(
            dirname(__DIR__, 2) . '/.codex/skills/dev-stack/SKILL.md',
            $this->projectRoot . '/.codex/skills/dev-stack/SKILL.md'
        );
        self::assertFileEquals(
            dirname(__DIR__, 2)
                . '/.codex/skills/liquidstack-module-operations/SKILL.md',
            $this->projectRoot
                . '/.codex/skills/liquidstack-module-operations/SKILL.md'
        );
        self::assertDirectoryDoesNotExist($this->projectRoot . '/.agents');
        self::assertSame(
            [
                'dev-stack',
                'liquidstack-module-operations',
                'liquidstack-resource-migration',
                'seo-content',
            ],
            $this->readManagedSkills($this->projectRoot . '/.codex/skills')
        );
    }

    public function testLegacyProjectUpdatesCoreSkillsAndPreservesLocalFiles(): void
    {
        $configPath    = $this->projectRoot . '/.codex/config.toml';
        $localSkill    = $this->projectRoot . '/.codex/skills/project-extra/SKILL.md';
        $obsoleteFile  = $this->projectRoot . '/.codex/skills/dev-stack/obsolete.txt';
        $retiredSkill  = $this->projectRoot . '/.codex/skills/retired-core/old.txt';
        $manifestPath  = $this->projectRoot . '/.codex/skills/.liquidstack-core-skills.json';
        $customConfig  = "[project]\nname = \"custom\"\n";
        $customSkill   = "---\nname: project-extra\ndescription: Local fixture.\n---\n\nKeep me.\n";

        $this->writeFile($configPath, $customConfig);
        $this->writeFile($localSkill, $customSkill);
        $this->writeFile($obsoleteFile, 'obsolete');
        $this->writeFile($retiredSkill, 'retired');
        $this->writeFile(
            $manifestPath,
            json_encode(['managed' => ['dev-stack', 'retired-core']], JSON_THROW_ON_ERROR)
        );

        [$event] = $this->createEvent();
        Installer::syncAgentGuidance($event);
        Installer::syncAgentGuidance($event);

        self::assertSame($customConfig, file_get_contents($configPath));
        self::assertSame($customSkill, file_get_contents($localSkill));
        self::assertFileEquals(
            dirname(__DIR__, 2) . '/.codex/skills/dev-stack/SKILL.md',
            $this->projectRoot . '/.codex/skills/dev-stack/SKILL.md'
        );
        self::assertFileDoesNotExist($obsoleteFile);
        self::assertDirectoryDoesNotExist(dirname($retiredSkill));
        self::assertDirectoryDoesNotExist($this->projectRoot . '/.agents');
        self::assertSame(
            [
                'dev-stack',
                'liquidstack-module-operations',
                'liquidstack-resource-migration',
                'seo-content',
            ],
            $this->readManagedSkills($this->projectRoot . '/.codex/skills')
        );
    }

    public function testExistingAgentsSkillsRemainLocalWhileCoreUsesCodex(): void
    {
        $officialExtra = $this->projectRoot . '/.agents/skills/project-modern/SKILL.md';
        $legacyExtra   = $this->projectRoot . '/.codex/skills/project-legacy/SKILL.md';

        $this->writeFile($officialExtra, 'official-local');
        $this->writeFile($legacyExtra, 'legacy-local');

        [$event] = $this->createEvent();
        Installer::syncAgentGuidance($event);

        self::assertFileExists($this->projectRoot . '/.codex/skills/dev-stack/SKILL.md');
        self::assertSame('official-local', file_get_contents($officialExtra));
        self::assertSame('legacy-local', file_get_contents($legacyExtra));
        self::assertFileDoesNotExist($this->projectRoot . '/.agents/skills/dev-stack/SKILL.md');
    }

    public function testAlternateManagedCopiesAreMigratedToCodex(): void
    {
        $officialRoot  = $this->projectRoot . '/.agents/skills';
        $officialExtra = $this->projectRoot . '/.agents/skills/project-modern/SKILL.md';

        $this->writeFile(
            $officialRoot . '/.liquidstack-core-skills.json',
            json_encode(['managed' => ['dev-stack']], JSON_THROW_ON_ERROR)
        );
        $this->writeFile($officialRoot . '/dev-stack/old.txt', 'old-core-file');
        $this->writeFile($officialExtra, 'official-local');

        [$event] = $this->createEvent();
        Installer::syncAgentGuidance($event);

        self::assertFileExists($this->projectRoot . '/.codex/skills/dev-stack/SKILL.md');
        self::assertDirectoryDoesNotExist($officialRoot . '/dev-stack');
        self::assertFileDoesNotExist($officialRoot . '/.liquidstack-core-skills.json');
        self::assertSame('official-local', file_get_contents($officialExtra));
    }

    public function testRedirectedCodexDirectoryIsRejectedWithoutWritingOutsideProject(): void
    {
        $externalRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-agent-external-'
            . bin2hex(random_bytes(8));
        $markerPath = $externalRoot . DIRECTORY_SEPARATOR . 'keep.txt';

        $this->externalRoots[] = $externalRoot;
        $this->writeFile($markerPath, 'keep');

        if (!$this->createDirectoryRedirect($externalRoot, $this->projectRoot . '/.codex')) {
            self::markTestSkipped('This environment cannot create a directory symlink or junction.');
        }

        [$event, $io] = $this->createEvent();
        Installer::syncAgentGuidance($event);

        self::assertSame('keep', file_get_contents($markerPath));
        self::assertFileDoesNotExist($externalRoot . '/config.toml');
        self::assertDirectoryDoesNotExist($externalRoot . '/skills');
        self::assertStringContainsString('Failed to prepare agent skills directory', $io->getOutput());
    }

    public function testRedirectInsideManagedSkillIsRejectedBeforeMirroring(): void
    {
        [$event] = $this->createEvent();
        Installer::syncAgentGuidance($event);

        $externalRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-agent-skill-external-'
            . bin2hex(random_bytes(8));
        $markerPath = $externalRoot . DIRECTORY_SEPARATOR . 'keep.txt';
        $redirectPath = $this->projectRoot . '/.codex/skills/dev-stack/redirected';

        $this->externalRoots[] = $externalRoot;
        $this->writeFile($markerPath, 'keep');

        if (!$this->createDirectoryRedirect($externalRoot, $redirectPath)) {
            self::markTestSkipped('This environment cannot create a directory symlink or junction.');
        }

        [$event, $io] = $this->createEvent();
        Installer::syncAgentGuidance($event);

        self::assertSame('keep', file_get_contents($markerPath));
        self::assertDirectoryExists($redirectPath);
        self::assertStringContainsString('Failed to sync core agent skill dev-stack', $io->getOutput());
    }

    /**
     * @return array{0: Event, 1: BufferIO}
     */
    private function createEvent(): array
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge([
            'config' => [
                'vendor-dir' => $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor',
            ],
        ]);

        $composer = new Composer();
        $composer->setConfig($config);
        $io = new BufferIO();

        return [new Event('test-agent-guidance', $composer, $io), $io];
    }

    private function createDirectoryRedirect(string $origin, string $target): bool
    {
        $this->filesystem->mkdir($origin);
        $this->filesystem->mkdir(dirname($target));

        if (DIRECTORY_SEPARATOR === '\\') {
            if (!function_exists('exec')) {
                return false;
            }

            $output   = [];
            $exitCode = 1;
            $command  = sprintf(
                'cmd /d /c mklink /J %s %s',
                escapeshellarg($target),
                escapeshellarg($origin)
            );
            @exec($command, $output, $exitCode);
            $created = $exitCode === 0 && is_dir($target);
        } else {
            $created = @symlink($origin, $target);
        }

        if ($created) {
            $this->redirectPaths[] = $target;
        }

        return $created;
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }

    /**
     * @return list<string>
     */
    private function readManagedSkills(string $skillsRoot): array
    {
        $raw = file_get_contents($skillsRoot . '/.liquidstack-core-skills.json');
        self::assertIsString($raw);

        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('managed', $manifest);
        self::assertIsArray($manifest['managed']);

        return array_values($manifest['managed']);
    }
}
