<?php

declare(strict_types=1);

use App\Core\Blog\Analytics\BlogAnalyticsPurgeResult;
use App\Core\Composer\BlogAnalyticsPurgeCommandRuntimeFactoryInterface;
use App\Core\Composer\BlogAnalyticsPurgeCommandRuntimeInterface;
use App\Core\Composer\Command\BlogAnalyticsPurgeCommand;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BlogAnalyticsPurgeRuntimeFixture implements
    BlogAnalyticsPurgeCommandRuntimeInterface
{
    public int $calls = 0;

    public function purge(): BlogAnalyticsPurgeResult
    {
        ++$this->calls;

        return new BlogAnalyticsPurgeResult(
            new \DateTimeImmutable('2026-05-01T00:00:00Z'),
            3,
            7
        );
    }
}

final class BlogAnalyticsPurgeFactoryFixture implements
    BlogAnalyticsPurgeCommandRuntimeFactoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly BlogAnalyticsPurgeCommandRuntimeInterface $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): BlogAnalyticsPurgeCommandRuntimeInterface {
        ++$this->calls;
        return $this->runtime;
    }
}

final class BlogAnalyticsPurgeCommandTest extends TestCase
{
    public function testExplicitConfirmationIsRequiredWithoutSideEffects(): void
    {
        $runtime = new BlogAnalyticsPurgeRuntimeFixture();
        $factory = new BlogAnalyticsPurgeFactoryFixture($runtime);
        $tester = $this->tester(new BlogAnalyticsPurgeCommand(
            dirname(__DIR__, 2),
            dirname(__DIR__, 2),
            $factory
        ));

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);
        self::assertStringContainsString(
            'blog.analytics.purge.confirmation_required',
            $tester->getDisplay()
        );
    }

    public function testConfirmedJsonPurgeHasMachineReadableCounts(): void
    {
        $runtime = new BlogAnalyticsPurgeRuntimeFixture();
        $factory = new BlogAnalyticsPurgeFactoryFixture($runtime);
        $tester = $this->tester(new BlogAnalyticsPurgeCommand(
            dirname(__DIR__, 2),
            dirname(__DIR__, 2),
            $factory
        ));

        $status = $tester->execute([
            '--yes' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $factory->calls);
        self::assertSame(1, $runtime->calls);
        self::assertSame(3, $payload['result']['deleted_sessions']);
        self::assertSame(7, $payload['result']['deleted_views']);
    }

    private function tester(BlogAnalyticsPurgeCommand $command): CommandTester
    {
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }
}
