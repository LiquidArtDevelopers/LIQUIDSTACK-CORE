<?php

declare(strict_types=1);

use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Environment\ProjectEnvironmentLoadResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectEnvironmentLoaderTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . '/liquidstack-project-environment-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testParsesFileWithoutExposingItThroughStatus(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/.env',
            "BBDD_USER=project-user\nPRIVATE_SECRET=must-not-leak\n"
        );

        $result = (new ProjectEnvironmentLoader())->load($this->root, []);

        self::assertSame(ProjectEnvironmentLoadResult::VALID, $result->status());
        self::assertTrue($result->isUsable());
        self::assertSame('project-user', $result->values()['BBDD_USER']);
        self::assertSame('must-not-leak', $result->values()['PRIVATE_SECRET']);
    }

    public function testExistingEnvironmentOverridesProjectFile(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/.env',
            "BBDD_USER=project-user\nBBDD_NAME=project-db\n"
        );

        $result = (new ProjectEnvironmentLoader())->load($this->root, [
            'BBDD_USER' => 'server-user',
        ]);

        self::assertSame('server-user', $result->values()['BBDD_USER']);
        self::assertSame('project-db', $result->values()['BBDD_NAME']);
    }

    public function testProjectValuesCanReferenceProcessEnvironment(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/.env',
            "SECRET_FROM_PROCESS=must-not-win\n"
                . "BBDD_PASS=\${SECRET_FROM_PROCESS}\n"
        );

        $result = (new ProjectEnvironmentLoader())->load($this->root, [
            'SECRET_FROM_PROCESS' => 'injected-secret',
        ]);

        self::assertSame(
            'injected-secret',
            $result->values()['SECRET_FROM_PROCESS']
        );
        self::assertSame(
            'injected-secret',
            $result->values()['BBDD_PASS']
        );
    }

    public function testProjectValuesCanReferenceEarlierProjectValues(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/.env',
            "PROJECT_BASE=liquidstack\nPROJECT_VALUE=\${PROJECT_BASE}-core\n"
        );

        $result = (new ProjectEnvironmentLoader())->load($this->root, []);

        self::assertSame('liquidstack', $result->values()['PROJECT_BASE']);
        self::assertSame(
            'liquidstack-core',
            $result->values()['PROJECT_VALUE']
        );
    }

    public function testMissingFileKeepsBaseEnvironmentUsable(): void
    {
        $result = (new ProjectEnvironmentLoader())->load($this->root, [
            'BBDD_NAME' => 'server-db',
        ]);

        self::assertSame(
            ProjectEnvironmentLoadResult::MISSING,
            $result->status()
        );
        self::assertTrue($result->isUsable());
        self::assertSame('server-db', $result->values()['BBDD_NAME']);
    }

    public function testInvalidSyntaxReturnsOnlyAGenericStatus(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/.env',
            "BROKEN='must-not-leak\n"
        );

        $result = (new ProjectEnvironmentLoader())->load($this->root, []);

        self::assertSame(
            ProjectEnvironmentLoadResult::PARSE_FAILED,
            $result->status()
        );
        self::assertFalse($result->isUsable());
        self::assertArrayNotHasKey('BROKEN', $result->values());
    }
}
