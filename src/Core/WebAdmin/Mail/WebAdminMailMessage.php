<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Mail;

use App\Core\WebAdmin\Security\EmailAddress;
use App\Core\WebAdmin\Security\OpaqueSecret;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** Immutable transport value. Its bodies may contain a one-time credential. */
final class WebAdminMailMessage
{
    private readonly OpaqueSecret $recipientEmail;
    private readonly ?OpaqueSecret $recipientName;
    private readonly OpaqueSecret $subject;
    private readonly OpaqueSecret $textBody;
    private readonly OpaqueSecret $htmlBody;

    public function __construct(
        #[SensitiveParameter]
        string $recipientEmail,
        #[SensitiveParameter]
        ?string $recipientName,
        #[SensitiveParameter]
        string $subject,
        #[SensitiveParameter]
        string $textBody,
        #[SensitiveParameter]
        string $htmlBody
    ) {
        if (EmailAddress::fromString($recipientEmail)->value() !== $recipientEmail) {
            throw new InvalidArgumentException('Invalid mail recipient.');
        }
        if (
            $recipientName !== null
            && (
                trim($recipientName) === ''
                || strlen($recipientName) > 120
                || preg_match('/[\x00-\x1F\x7F]/', $recipientName) === 1
                || preg_match('//u', $recipientName) !== 1
            )
        ) {
            throw new InvalidArgumentException('Invalid mail recipient name.');
        }
        if (
            trim($subject) === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
            || preg_match('//u', $subject) !== 1
        ) {
            throw new InvalidArgumentException('Invalid mail subject.');
        }
        if ($textBody === '' || $htmlBody === '') {
            throw new InvalidArgumentException('Mail bodies cannot be empty.');
        }

        // Message bodies may contain a one-time credential. Keeping every
        // field behind OpaqueSecret prevents accidental disclosure through
        // object casts and exports as well as through debug output.
        $this->recipientEmail = OpaqueSecret::fromString($recipientEmail);
        $this->recipientName = $recipientName === null
            ? null
            : OpaqueSecret::fromString($recipientName);
        $this->subject = OpaqueSecret::fromString($subject);
        $this->textBody = OpaqueSecret::fromString($textBody);
        $this->htmlBody = OpaqueSecret::fromString($htmlBody);
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail->reveal();
    }

    public function recipientName(): ?string
    {
        return $this->recipientName?->reveal();
    }

    public function subject(): string
    {
        return $this->subject->reveal();
    }

    public function textBody(): string
    {
        return $this->textBody->reveal();
    }

    public function htmlBody(): string
    {
        return $this->htmlBody->reveal();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'recipient' => '[redacted]',
            'subject' => '[redacted]',
            'text_body' => '[redacted]',
            'html_body' => '[redacted]',
        ];
    }

    /** @return array<string, never> */
    public function __serialize(): array
    {
        throw new LogicException('WebAdmin mail messages cannot be serialized.');
    }

    private function __clone()
    {
    }
}
