<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use App\Core\Commerce\InquiryContact;
use App\Core\Commerce\InquiryResult;
use DateTimeImmutable;

interface CommerceInquiryRepositoryInterface
{
    public function submit(
        string $inquiryPublicId,
        string $operationId,
        string $payloadSha256,
        string $basketToken,
        InquiryContact $contact,
        string $privacyVersion,
        string $primaryLocale,
        DateTimeImmutable $now
    ): InquiryResult;
}
