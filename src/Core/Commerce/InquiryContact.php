<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class InquiryContact
{
    private readonly string $name;
    private readonly string $email;
    private readonly ?string $phone;
    private readonly ?string $message;

    public function __construct(
        string $name,
        string $email,
        ?string $phone = null,
        ?string $message = null
    ) {
        $this->name = CommerceInput::text($name, 180);
        $email = strtolower(trim($email));
        if (
            strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new CommerceValidationException('Invalid inquiry email.');
        }
        $this->email = $email;
        $this->phone = CommerceInput::nullableText($phone, 64);
        $this->message = CommerceInput::nullableText($message, 8_000);
    }

    public function name(): string { return $this->name; }
    public function email(): string { return $this->email; }
    public function phone(): ?string { return $this->phone; }
    public function message(): ?string { return $this->message; }

    /** @return array{name: string, email: string, phone: ?string, message: ?string} */
    public function normalizedPayload(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'message' => $this->message,
        ];
    }
}
