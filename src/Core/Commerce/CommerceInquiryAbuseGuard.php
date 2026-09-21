<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use App\Core\Commerce\Persistence\PdoCommerceInquiryRateLimitRepository;
use App\Core\WebAdmin\Security\SecurityKey;
use DateTimeImmutable;

final class CommerceInquiryAbuseGuard
{
    private const IP_WINDOW_SECONDS = 600;
    private const IP_MAXIMUM_ATTEMPTS = 10;
    private const EMAIL_WINDOW_SECONDS = 3_600;
    private const EMAIL_MAXIMUM_ATTEMPTS = 3;

    public function __construct(
        private readonly PdoCommerceInquiryRateLimitRepository $rateLimits,
        private readonly SecurityKey $securityKey
    ) {
    }

    public function allows(
        ?string $clientIp,
        InquiryContact $contact,
        DateTimeImmutable $now
    ): bool {
        if (
            $clientIp !== null
            && filter_var($clientIp, FILTER_VALIDATE_IP) !== false
            && !$this->rateLimits->consume(
                'commerce.inquiry.ip',
                $this->securityKey->subjectHash(
                    'commerce.inquiry.ip',
                    $clientIp
                ),
                $now,
                self::IP_WINDOW_SECONDS,
                self::IP_MAXIMUM_ATTEMPTS
            )
        ) {
            return false;
        }

        return $this->rateLimits->consume(
            'commerce.inquiry.email',
            $this->securityKey->subjectHash(
                'commerce.inquiry.email',
                $contact->email()
            ),
            $now,
            self::EMAIL_WINDOW_SECONDS,
            self::EMAIL_MAXIMUM_ATTEMPTS
        );
    }
}
