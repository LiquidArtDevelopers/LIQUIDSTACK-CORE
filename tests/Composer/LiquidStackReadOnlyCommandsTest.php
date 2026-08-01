<?php

declare(strict_types=1);

use App\Core\Composer\Command\DoctorCommand;
use App\Core\Composer\Command\MigrateCommand;
use App\Core\Modules\ModuleProviderInterface;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class GenericRouteProviderForDoctorFixture implements ModuleProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }
}

final class LiquidStackReadOnlyCommandsTest extends TestCase
{
    private string $root;
    private string $projectRoot;
    private Filesystem $filesystem;
    private string $previousExceptionTraceSetting;

    protected function setUp(): void
    {
        $this->previousExceptionTraceSetting = (string) ini_get(
            'zend.exception_ignore_args'
        );
        ini_set('zend.exception_ignore_args', '1');
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-readonly-commands-'
            . bin2hex(random_bytes(8));
        $this->projectRoot = $this->root . '/project';
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config',
            $this->projectRoot . '/src/scss',
            $this->root . '/modules/webadmin',
            $this->root . '/modules/blog',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/config.php',
            "<?php\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/src/scss/_config.scss',
            '$color00: #fff;' . PHP_EOL
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/.env',
            "APP_ENV=testing\n"
                . "BBDD_SERVER=localhost\n"
                . "BBDD_USER=tester\n"
                . "BBDD_PASS=must-not-leak\n"
                . "BBDD_NAME=liquidstack_test\n"
                . 'LIQUIDSTACK_WEBADMIN_SECURITY_KEY='
                . $this->securityKey()
                . "\n"
                . "LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN=https://example.test\n"
                . "PRIVATE_FIXTURE_SECRET=must-not-leak\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\n\nreturn ['es', 'en'];\n"
        );
        $this->writeManifest('webadmin', []);
        $this->writeManifest('blog', ['webadmin']);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/composer.json',
            json_encode([
                'require' => [
                    'liquidstack/core' => '^1.9',
                    'liquidstack/blog' => '*',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }

    protected function tearDown(): void
    {
        ini_set(
            'zend.exception_ignore_args',
            $this->previousExceptionTraceSetting
        );
        $this->filesystem->remove($this->root);
    }

    public function testDoctorJsonReportsDependencyClosureWithoutSecrets(): void
    {
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            Command::SUCCESS,
            $status,
            $tester->getDisplay()
        );
        self::assertTrue($payload['ok']);
        self::assertSame(['blog'], $payload['modules']['requested']);
        self::assertSame(
            ['webadmin', 'blog'],
            $payload['modules']['enabled']
        );
        self::assertSame(0, $payload['migrations']['count']);
        self::assertStringNotContainsString(
            'must-not-leak',
            $tester->getDisplay()
        );
    }

    public function testDoctorHumanOutputNamesReadOnlyState(): void
    {
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(
            'LiquidStack doctor (solo lectura)',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'Activos: core, webadmin, blog',
            $tester->getDisplay()
        );
    }

    public function testMigrationPlanIsStrictlyReadOnly(): void
    {
        $before = $this->snapshot($this->projectRoot);
        $tester = $this->tester(new MigrateCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute([
            '--plan' => true,
            '--format' => 'json',
        ]);
        $after = $this->snapshot($this->projectRoot);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($before, $after);
        self::assertSame('migrate-plan', $payload['operation']);
        self::assertTrue($payload['migrations']['read_only']);
        self::assertSame(
            'not_evaluated',
            $payload['migrations']['database_state']
        );
    }

    public function testMigrationCommandRequiresExactlyOneExplicitMode(): void
    {
        $tester = $this->tester(new MigrateCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute([]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString(
            'migrate.mode_required',
            $tester->getDisplay()
        );

        $status = $tester->execute([
            '--plan' => true,
            '--dry-run' => true,
        ]);
        self::assertSame(Command::INVALID, $status);
    }

    public function testDoctorRejectsLocalizedWebAdminPrefix(): void
    {
        $this->filesystem->mkdir(
            $this->projectRoot . '/App/config/modules'
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['path' => '/es/admin'];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/langs.php',
            "<?php\nreturn ['es', 'en'];\n"
        );
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertFalse(
            $payload['module_diagnostics']['webadmin']['configuration']['ready']
        );
        self::assertContains(
            [
                'code' => 'config.localized_base_path',
                'key' => 'path',
            ],
            $payload['module_diagnostics']['webadmin']['configuration']['issues']
        );
    }

    public function testDoctorReportsRouteCollisionAndSafeFallback(): void
    {
        $this->filesystem->mkdir([
            $this->projectRoot . '/App/config/modules',
            $this->projectRoot . '/App/config/routes',
        ]);
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/modules/webadmin.php',
            "<?php\nreturn ['path' => '/contacto'];\n"
        );
        $this->filesystem->dumpFile(
            $this->projectRoot . '/App/config/routes/get.php',
            "<?php\nreturn ['es' => ['/contacto' => ['view' => 'contacto.php']]];\n"
        );
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $diagnostic = $payload['module_diagnostics']['webadmin'];

        self::assertSame(Command::FAILURE, $status);
        self::assertFalse($diagnostic['routing']['ready']);
        self::assertTrue($diagnostic['routing']['available']);
        self::assertSame(
            '/admin',
            $diagnostic['routing']['registered_path']
        );
        self::assertContains([
            'code' => 'config.route_collision',
            'key' => 'path',
        ], $diagnostic['configuration']['issues']);
        self::assertSame(
            ['/contacto'],
            array_column($diagnostic['routing']['collisions'], 'route')
        );
    }

    public function testDoctorValidatesTypedRouteProviders(): void
    {
        $this->writeManifest('webadmin', [], [
            'routes' => [GenericRouteProviderForDoctorFixture::class],
        ]);
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::FAILURE, $status);
        self::assertContains(
            'modules.providers.routes',
            array_column($payload['checks'], 'id')
        );
    }

    public function testInvalidEnvNeverLeaksParserDetailsOrValues(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/.env',
            "BROKEN='must-not-leak\n"
        );
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute(['--format' => 'json']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringNotContainsString(
            'must-not-leak',
            $tester->getDisplay()
        );
        self::assertStringContainsString(
            'no se muestran detalles',
            $tester->getDisplay()
        );
    }

    public function testDoctorHumanOutputDistinguishesInvalidDatabaseNames(): void
    {
        $this->filesystem->dumpFile(
            $this->projectRoot . '/.env',
            "BBDD_SERVER=localhost;must-not-leak\n"
                . "BBDD_USER=tester\n"
                . "BBDD_PASS=must-not-leak\n"
                . "BBDD_NAME=liquidstack_test\n"
        );
        $tester = $this->tester(new DoctorCommand(
            $this->projectRoot,
            $this->root
        ));

        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'formato inválido: BBDD_SERVER',
            $display
        );
        self::assertStringNotContainsString('must-not-leak', $display);
        self::assertStringNotContainsString('Faltan variables DB', $display);
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(string $root): array
    {
        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $snapshot[substr($path, strlen(str_replace('\\', '/', $root)) + 1)] =
                hash_file('sha256', $file->getPathname()) ?: '';
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function tester(Command $command): CommandTester
    {
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }

    private function securityKey(): string
    {
        return rtrim(strtr(
            base64_encode(str_repeat('C', 32)),
            '+/',
            '-_'
        ), '=');
    }

    /**
     * @param list<string> $requires
     */
    private function writeManifest(
        string $id,
        array $requires,
        array $providers = []
    ): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/modules/' . $id . '/module.json',
            json_encode([
                'schema' => 1,
                'id' => $id,
                'package' => 'liquidstack/' . $id,
                'requires' => $requires,
                'providers' => $providers,
                'project_files' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL
        );
    }
}
