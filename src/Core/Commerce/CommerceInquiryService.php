<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use App\Core\Commerce\Persistence\CommerceInquiryRepositoryInterface;
use DateTimeImmutable;
use JsonException;

final class CommerceInquiryService
{
    public function __construct(
        private readonly CommerceInquiryRepositoryInterface $repository,
        private readonly string $primaryLocale
    ) {
        CommerceInput::locale($primaryLocale);
    }

    public function submit(
        string $operationId,
        string $basketToken,
        InquiryContact $contact,
        string $privacyVersion,
        DateTimeImmutable $now
    ): InquiryResult {
        $operationId = CommerceInput::uuid($operationId);
        $privacyVersion = CommerceInput::text($privacyVersion, 64);
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $basketToken) !== 1) {
            throw new CommerceValidationException('Invalid basket token.');
        }
        try {
            $json = json_encode(
                [
                    'basket_token_sha256' => hash('sha256', $basketToken),
                    'contact' => $contact->normalizedPayload(),
                    'privacy_version' => $privacyVersion,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new CommerceValidationException('Invalid inquiry payload.');
        }

        return $this->repository->submit(
            CommerceInput::newUuid(),
            $operationId,
            hash('sha256', $json),
            $basketToken,
            $contact,
            $privacyVersion,
            $this->primaryLocale,
            $now
        );
    }
}
