<?php

declare(strict_types=1);

use App\Core\Blog\QaFixtures\BlogQaMatrixFixtureResult;
use App\Core\Composer\BlogQaSeedMatrixCommandRuntimeFactoryInterface;
use App\Core\Composer\BlogQaSeedMatrixCommandRuntimeInterface;
use App\Core\Composer\Command\BlogQaSeedMatrixCommand;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BlogQaSeedMatrixRuntimeFixture implements
    BlogQaSeedMatrixCommandRuntimeInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function run(
        array $locales,
        string $mediaAssetPublicId,
        string $actorPublicId,
        bool $apply
    ): BlogQaMatrixFixtureResult {
        $this->calls[] = [
            'locales' => $locales,
            'media' => $mediaAssetPublicId,
            'actor' => $actorPublicId,
            'apply' => $apply,
        ];

        return new BlogQaMatrixFixtureResult(
            $apply,
            'sqlite',
            $locales,
            10,
            10 * count($locales),
            10,
            10 * count($locales),
            0
        );
    }
}

final class BlogQaSeedMatrixFactoryFixture implements
    BlogQaSeedMatrixCommandRuntimeFactoryInterface
{
    /** @var list<bool> */
    public array $allowMysqlCalls = [];

    public function __construct(
        private readonly BlogQaSeedMatrixCommandRuntimeInterface $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $allowMysql
    ): BlogQaSeedMatrixCommandRuntimeInterface {
        $this->allowMysqlCalls[] = $allowMysql;

        return $this->runtime;
    }
}

final class BlogQaSeedMatrixCommandTest extends TestCase
{
    private const MEDIA = '11111111-1111-4111-8111-111111111111';
    private const ACTOR = '22222222-2222-4222-8222-222222222222';

    public function testExplicitModeIsRequiredBeforeRuntimeConstruction(): void
    {
        $runtime = new BlogQaSeedMatrixRuntimeFixture();
        $factory = new BlogQaSeedMatrixFactoryFixture($runtime);
        $tester = $this->tester($factory);

        self::assertSame(Command::INVALID, $tester->execute([
            '--media-asset' => self::MEDIA,
            '--actor' => self::ACTOR,
        ]));
        self::assertSame([], $factory->allowMysqlCalls);
        self::assertSame([], $runtime->calls);
        self::assertStringContainsString(
            'blog.qa_fixture.mode_required',
            $tester->getDisplay()
        );
    }

    public function testDryRunPassesCanonicalLocaleSubsetWithoutMutation(): void
    {
        $runtime = new BlogQaSeedMatrixRuntimeFixture();
        $factory = new BlogQaSeedMatrixFactoryFixture($runtime);
        $tester = $this->tester($factory);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--dry-run' => true,
            '--locales' => 'eu,es',
            '--media-asset' => self::MEDIA,
            '--actor' => self::ACTOR,
        ]));
        self::assertSame([false], $factory->allowMysqlCalls);
        self::assertSame(['es', 'eu'], $runtime->calls[0]['locales']);
        self::assertFalse($runtime->calls[0]['apply']);
        self::assertStringContainsString(
            '20 variantes solicitadas',
            $tester->getDisplay()
        );
    }

    public function testConfirmedJsonApplyCarriesMysqlOptIn(): void
    {
        $runtime = new BlogQaSeedMatrixRuntimeFixture();
        $factory = new BlogQaSeedMatrixFactoryFixture($runtime);
        $tester = $this->tester($factory);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--yes' => true,
            '--allow-mysql' => true,
            '--media-asset' => self::MEDIA,
            '--actor' => self::ACTOR,
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame([true], $factory->allowMysqlCalls);
        self::assertTrue($runtime->calls[0]['apply']);
        self::assertSame('apply', $payload['result']['mode']);
        self::assertSame(30, $payload['result']['variants_mutated']);
    }

    public function testConflictingModesAndInvalidLocalesStaySideEffectFree(): void
    {
        foreach ([
            [
                '--dry-run' => true,
                '--yes' => true,
                '--media-asset' => self::MEDIA,
                '--actor' => self::ACTOR,
            ],
            [
                '--dry-run' => true,
                '--locales' => 'es,fr',
                '--media-asset' => self::MEDIA,
                '--actor' => self::ACTOR,
            ],
        ] as $arguments) {
            $runtime = new BlogQaSeedMatrixRuntimeFixture();
            $factory = new BlogQaSeedMatrixFactoryFixture($runtime);
            $tester = $this->tester($factory);

            self::assertNotSame(Command::SUCCESS, $tester->execute($arguments));
            self::assertSame([], $factory->allowMysqlCalls);
            self::assertSame([], $runtime->calls);
        }
    }

    private function tester(
        BlogQaSeedMatrixCommandRuntimeFactoryInterface $factory
    ): CommandTester {
        $command = new BlogQaSeedMatrixCommand(
            dirname(__DIR__, 2),
            dirname(__DIR__, 2),
            $factory
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }
}
