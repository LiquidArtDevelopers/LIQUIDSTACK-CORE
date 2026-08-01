<?php

declare(strict_types=1);

use App\Core\WebAdmin\Outbox\WebAdminOutboxLease;
use App\Core\WebAdmin\Security\OpaqueSecret;
use PHPUnit\Framework\TestCase;

final class WebAdminOutboxLeaseSecurityTest extends TestCase
{
    public function testRecipientAndTokensStayOutsideObjectExports(): void
    {
        $recipient = 'private-recipient@example.test';
        $leaseToken = 'lease-secret-value';
        $actionToken = 'action-secret-value';
        $lease = new WebAdminOutboxLease(
            1,
            2,
            1,
            'invite',
            $recipient,
            'und',
            OpaqueSecret::fromString($leaseToken),
            OpaqueSecret::fromString($actionToken)
        );

        self::assertSame($recipient, $lease->recipientEmail());
        foreach ([
            var_export($lease, true),
            print_r($lease, true),
            var_export((array) $lease, true),
        ] as $export) {
            self::assertStringNotContainsString($recipient, $export);
            self::assertStringNotContainsString($leaseToken, $export);
            self::assertStringNotContainsString($actionToken, $export);
        }
    }

    public function testLeaseCannotBeSerialized(): void
    {
        $lease = new WebAdminOutboxLease(
            1,
            2,
            1,
            'invite',
            'private-recipient@example.test',
            'und',
            OpaqueSecret::fromString('lease-secret-value'),
            OpaqueSecret::fromString('action-secret-value')
        );

        $this->expectException(\LogicException::class);
        serialize($lease);
    }
}
