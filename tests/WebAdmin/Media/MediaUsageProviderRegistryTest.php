<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\Usage\MediaDeletionReferenceGate;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderInterface;
use App\Core\WebAdmin\Media\Usage\MediaUsageProviderRegistry;
use App\Core\WebAdmin\Media\Usage\MediaUsageStatus;
use PHPUnit\Framework\TestCase;

final class MediaUsageProviderRegistryTest extends TestCase
{
    private const USED = '10000000-0000-4000-8000-000000000001';
    private const FREE = '10000000-0000-4000-8000-000000000002';

    public function testAggregatesPositiveUsageAndMarksTheRestUnused(): void
    {
        $statuses = (new MediaUsageProviderRegistry([
            new MediaUsageTestProvider([self::USED]),
        ]))->statuses([self::USED, self::FREE]);

        self::assertSame(MediaUsageStatus::Used, $statuses[self::USED]);
        self::assertSame(MediaUsageStatus::Unused, $statuses[self::FREE]);
    }

    public function testIncompleteProviderSetNeverClaimsAnAssetIsUnused(): void
    {
        $statuses = (new MediaUsageProviderRegistry([
            new MediaUsageTestProvider([self::USED]),
        ], false))->statuses([self::USED, self::FREE]);

        self::assertSame(MediaUsageStatus::Used, $statuses[self::USED]);
        self::assertSame(MediaUsageStatus::Unknown, $statuses[self::FREE]);
    }

    public function testProviderCannotReturnAnUnrequestedIdentifier(): void
    {
        $this->expectException(MediaException::class);
        (new MediaUsageProviderRegistry([
            new MediaUsageTestProvider([
                '10000000-0000-4000-8000-000000000099',
            ]),
        ]))->statuses([self::USED]);
    }

    public function testDeletionGateFailsClosedForUsedOrUnknownAssets(): void
    {
        $usedGate = new MediaDeletionReferenceGate(
            new MediaUsageProviderRegistry([
                new MediaUsageTestProvider([self::USED]),
            ])
        );
        try {
            $usedGate->assertUnreferenced(self::USED);
            self::fail('A referenced asset must not pass the deletion gate.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.delete_asset_in_use',
                $exception->issueCode()
            );
        }

        $unknownGate = new MediaDeletionReferenceGate(
            new MediaUsageProviderRegistry([], false)
        );
        try {
            $unknownGate->assertUnreferenced(self::FREE);
            self::fail('Incomplete usage knowledge must fail closed.');
        } catch (MediaException $exception) {
            self::assertSame(
                'webadmin.media.delete_usage_unavailable',
                $exception->issueCode()
            );
        }

        (new MediaDeletionReferenceGate(new MediaUsageProviderRegistry()))
            ->assertUnreferenced(self::FREE);
        self::addToAssertionCount(1);
    }
}

final class MediaUsageTestProvider implements MediaUsageProviderInterface
{
    /** @param list<string> $used */
    public function __construct(private readonly array $used)
    {
    }

    public function usedPublicIds(array $mediaPublicIds): array
    {
        return $this->used;
    }
}
