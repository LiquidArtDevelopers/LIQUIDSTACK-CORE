<?php

declare(strict_types=1);

use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionException;
use App\Core\Blog\StructuredContent\Adoption\BlogUnifiedTextAdoptionResult;
use App\Core\Composer\BlogUnifiedTextAdoptionCommandRuntimeFactoryInterface;
use App\Core\Composer\BlogUnifiedTextAdoptionCommandRuntimeInterface;
use App\Core\Composer\Command\BlogUnifiedTextAdoptionCommand;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UnifiedTextAdoptionRuntimeFixture implements
    BlogUnifiedTextAdoptionCommandRuntimeInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function run(
        array $postPublicIds,
        array $locales,
        array $statuses,
        int $maximumCandidates,
        ?string $actorPublicId,
        bool $apply
    ): BlogUnifiedTextAdoptionResult {
        $this->calls[] = compact(
            'postPublicIds',
            'locales',
            'statuses',
            'maximumCandidates',
            'actorPublicId',
            'apply'
        );

        if ($apply) {
            return new BlogUnifiedTextAdoptionResult(
                true,
                'sqlite',
                1,
                0,
                0,
                0,
                0,
                1,
                [[
                    'post_public_id' =>
                        '11111111-1111-4111-8111-111111111111',
                    'locale' => 'es',
                    'status' => 'published',
                    'lock_version' => 4,
                    'snapshot_sha256' => str_repeat('a', 64),
                ]],
                1
            );
        }

        return new BlogUnifiedTextAdoptionResult(
            false,
            'sqlite',
            4,
            1,
            0,
            1,
            1,
            1,
            [
                [
                    'post_public_id' =>
                        '11111111-1111-4111-8111-111111111111',
                    'locale' => 'es',
                    'status' => 'draft',
                    'lock_version' => 2,
                    'snapshot_sha256' => str_repeat('a', 64),
                ],
                [
                    'post_public_id' =>
                        '33333333-3333-4333-8333-333333333333',
                    'locale' => 'eu',
                    'status' => 'published',
                    'lock_version' => 7,
                    'snapshot_sha256' => str_repeat('b', 64),
                ],
            ],
            0
        );
    }
}

final class UnifiedTextAdoptionFactoryFixture implements
    BlogUnifiedTextAdoptionCommandRuntimeFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly BlogUnifiedTextAdoptionCommandRuntimeInterface
            $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): BlogUnifiedTextAdoptionCommandRuntimeInterface {
        ++$this->calls;

        return $this->runtime;
    }
}

final class BlogUnifiedTextAdoptionCommandTest extends TestCase
{
    private const ACTOR = '22222222-2222-4222-8222-222222222222';
    private const POST = '11111111-1111-4111-8111-111111111111';

    public function testNoModeFlagIsReadOnlyByDefault(): void
    {
        [$tester, $runtime, $factory] = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, $factory->calls);
        self::assertFalse($runtime->calls[0]['apply']);
        self::assertNull($runtime->calls[0]['actorPublicId']);
        self::assertSame(250, $runtime->calls[0]['maximumCandidates']);
        self::assertStringContainsString(
            'Solo lectura; QA Dummy y papelera excluidas',
            $tester->getDisplay()
        );
    }

    public function testApplyRequiresBothConfirmationAndActorBeforeFactory(): void
    {
        [$tester, $runtime, $factory] = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
        ]));
        self::assertSame(0, $factory->calls);
        self::assertSame([], $runtime->calls);
        self::assertStringContainsString(
            'confirmation_required',
            $tester->getDisplay()
        );

        [$tester, $runtime, $factory] = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
        ]));
        self::assertSame(0, $factory->calls);
        self::assertSame([], $runtime->calls);
        self::assertStringContainsString(
            'actor_required',
            $tester->getDisplay()
        );

        [$tester, $runtime, $factory] = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--actor' => self::ACTOR,
        ]));
        self::assertSame(0, $factory->calls);
        self::assertSame([], $runtime->calls);
        self::assertStringContainsString(
            'single_variant_required',
            $tester->getDisplay()
        );
    }

    public function testConfirmedApplyCarriesBoundedExplicitFilters(): void
    {
        [$tester, $runtime, $factory] = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--apply' => true,
            '--yes' => true,
            '--actor' => self::ACTOR,
            '--post' => [self::POST],
            '--locale' => ['es'],
            '--status' => ['published'],
            '--max' => '8',
            '--format' => 'json',
        ]));
        self::assertSame(1, $factory->calls);
        self::assertTrue($runtime->calls[0]['apply']);
        self::assertSame(self::ACTOR, $runtime->calls[0]['actorPublicId']);
        self::assertSame([self::POST], $runtime->calls[0]['postPublicIds']);
        self::assertSame(['es'], $runtime->calls[0]['locales']);
        self::assertSame(['published'], $runtime->calls[0]['statuses']);
        self::assertSame(8, $runtime->calls[0]['maximumCandidates']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(0, $payload['result']['published_automatically']);
        self::assertSame(
            1,
            $payload['result'][
                'published_private_workspaces_created_or_advanced'
            ]
        );
    }

    public function testApplyRejectsRepeatedVariantSelectorsBeforeFactory(): void
    {
        foreach ([
            [
                '--post' => [self::POST, self::POST],
                '--locale' => ['es'],
            ],
            [
                '--post' => [self::POST],
                '--locale' => ['es', 'es'],
            ],
        ] as $filters) {
            [$tester, $runtime, $factory] = $this->tester();
            self::assertSame(Command::FAILURE, $tester->execute($filters + [
                '--apply' => true,
                '--yes' => true,
                '--actor' => self::ACTOR,
            ]));
            self::assertSame(0, $factory->calls);
            self::assertSame([], $runtime->calls);
            self::assertStringContainsString(
                'single_variant_required',
                $tester->getDisplay()
            );
        }
    }

    public function testGlobalDryRunJsonContainsOnlySafeOrderedPlanRows(): void
    {
        [$tester] = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--format' => 'json',
        ]));
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame([
            'post_public_id',
            'locale',
            'status',
            'lock_version',
            'snapshot_sha256',
        ], array_keys($payload['result']['pending_variants'][0]));
        self::assertSame(
            [self::POST, '33333333-3333-4333-8333-333333333333'],
            array_column(
                $payload['result']['pending_variants'],
                'post_public_id'
            )
        );
        self::assertArrayNotHasKey(
            'title',
            $payload['result']['pending_variants'][0]
        );
        self::assertArrayNotHasKey(
            'content',
            $payload['result']['pending_variants'][0]
        );
    }

    public function testResultRejectsAnyUnexpectedPlanField(): void
    {
        $this->expectException(BlogUnifiedTextAdoptionException::class);

        new BlogUnifiedTextAdoptionResult(
            false,
            'sqlite',
            1,
            0,
            0,
            0,
            1,
            0,
            [[
                'post_public_id' => self::POST,
                'locale' => 'es',
                'status' => 'draft',
                'lock_version' => 2,
                'snapshot_sha256' => str_repeat('a', 64),
                'content' => 'must never be emitted',
            ]],
            0
        );
    }

    public function testConflictingOrUnconfirmedModesNeverBuildRuntime(): void
    {
        foreach ([
            ['--dry-run' => true, '--apply' => true, '--yes' => true],
            ['--yes' => true],
            ['--locale' => ['not_a_locale']],
            ['--status' => ['deleted']],
        ] as $arguments) {
            [$tester, $runtime, $factory] = $this->tester();
            self::assertNotSame(Command::SUCCESS, $tester->execute($arguments));
            self::assertSame(0, $factory->calls);
            self::assertSame([], $runtime->calls);
        }
    }

    /**
     * @return array{CommandTester, UnifiedTextAdoptionRuntimeFixture, UnifiedTextAdoptionFactoryFixture}
     */
    private function tester(): array
    {
        $runtime = new UnifiedTextAdoptionRuntimeFixture();
        $factory = new UnifiedTextAdoptionFactoryFixture($runtime);
        $command = new BlogUnifiedTextAdoptionCommand(
            dirname(__DIR__, 2),
            dirname(__DIR__, 2),
            $factory
        );
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return [new CommandTester($command), $runtime, $factory];
    }
}
