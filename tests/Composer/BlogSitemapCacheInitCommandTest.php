<?php

declare(strict_types=1);

use App\Core\Blog\Sitemap\Cache\BlogSitemapCacheInitializationResult;
use App\Core\Composer\BlogSitemapCacheInitCommandRuntimeFactoryInterface;
use App\Core\Composer\BlogSitemapCacheInitCommandRuntimeInterface;
use App\Core\Composer\Command\BlogSitemapCacheInitCommand;
use Composer\Console\Application as ComposerApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BlogSitemapCacheInitCliRuntimeFixture implements
    BlogSitemapCacheInitCommandRuntimeInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly BlogSitemapCacheInitializationResult $result
    ) {
    }

    public function initialize(): BlogSitemapCacheInitializationResult
    {
        ++$this->calls;

        return $this->result;
    }
}

final class BlogSitemapCacheInitCliFactoryFixture implements
    BlogSitemapCacheInitCommandRuntimeFactoryInterface
{
    public int $calls = 0;
    public ?bool $sharedStorageConfirmed = null;

    public function __construct(
        private readonly BlogSitemapCacheInitCommandRuntimeInterface $runtime
    ) {
    }

    public function create(
        string $projectRoot,
        string $coreRoot,
        bool $sharedStorageConfirmed
    ): BlogSitemapCacheInitCommandRuntimeInterface {
        ++$this->calls;
        $this->sharedStorageConfirmed = $sharedStorageConfirmed;

        return $this->runtime;
    }
}

final class BlogSitemapCacheInitCommandTest extends TestCase
{
    public function testJsonRequiresExplicitConfirmationWithoutSideEffects(): void
    {
        $runtime = new BlogSitemapCacheInitCliRuntimeFixture(
            new BlogSitemapCacheInitializationResult(
                '12345678-1234-4234-8234-123456789abc',
                true
            )
        );
        $factory = new BlogSitemapCacheInitCliFactoryFixture($runtime);
        $command = new BlogSitemapCacheInitCommand(
            dirname(__DIR__, 2),
            dirname(__DIR__, 2),
            $factory
        );

        self::assertSame(
            'liquidstack:blog:sitemap-cache:init',
            $command->getName()
        );
        $tester = $this->tester($command);
        $status = $tester->execute(['--format' => 'json']);
        $payload = json_decode(
            $tester->getDisplay(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(Command::INVALID, $status);
        self::assertSame(
            'blog.sitemap_cache.init.json_requires_yes',
            $payload['error']['code']
        );
        self::assertSame(0, $factory->calls);
        self::assertSame(0, $runtime->calls);
    }

    public function testConfirmedJsonInitializationForwardsProductionDecision(): void
    {
        $generation = '12345678-1234-4234-8234-123456789abc';
        $runtime = new BlogSitemapCacheInitCliRuntimeFixture(
            new BlogSitemapCacheInitializationResult($generation, true)
        );
        $factory = new BlogSitemapCacheInitCliFactoryFixture($runtime);
        $tester = $this->tester(new BlogSitemapCacheInitCommand(
            dirname(__DIR__, 2),
            dirname(__DIR__, 2),
            $factory
        ));

        $status = $tester->execute([
            '--yes' => true,
            '--shared-storage-confirmed' => true,
            '--format' => 'json',
        ]);
        $display = $tester->getDisplay();
        $payload = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $factory->calls);
        self::assertSame(1, $runtime->calls);
        self::assertTrue($factory->sharedStorageConfirmed);
        self::assertSame('initialized', $payload['result']['status']);
        self::assertTrue($payload['result']['changed']);
        self::assertStringNotContainsString($generation, $display);
    }

    private function tester(
        BlogSitemapCacheInitCommand $command
    ): CommandTester {
        $application = new ComposerApplication();
        $application->setAutoExit(false);
        $application->add($command);

        return new CommandTester($command);
    }
}
