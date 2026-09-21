<?php

declare(strict_types=1);

namespace App\Core\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use Normalizer;

final class CommerceInput
{
    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const LOCALE_PATTERN = '/\A[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?\z/';
    private const SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';
    private const CODE_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';
    private const UTC_FORMAT = 'Y-m-d H:i:s.u';

    public static function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new CommerceValidationException('Invalid public identifier.');
        }

        return $value;
    }

    public static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    public static function locale(string $value): string
    {
        $value = trim($value);
        if (preg_match(self::LOCALE_PATTERN, $value) !== 1) {
            throw new CommerceValidationException('Invalid locale.');
        }

        return $value;
    }

    /** @param list<string> $locales @return list<string> */
    public static function locales(array $locales): array
    {
        if ($locales === [] || count($locales) > 32) {
            throw new CommerceValidationException('Invalid active locales.');
        }
        $result = [];
        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                throw new CommerceValidationException('Invalid active locales.');
            }
            $result[self::locale($locale)] = true;
        }

        return array_keys($result);
    }

    public static function slug(string $value): string
    {
        $value = trim($value);
        if (strlen($value) > 180 || preg_match(self::SLUG_PATTERN, $value) !== 1) {
            throw new CommerceValidationException('Invalid localized slug.');
        }

        return $value;
    }

    public static function code(string $value): string
    {
        $value = trim($value);
        if (preg_match(self::CODE_PATTERN, $value) !== 1) {
            throw new CommerceValidationException('Invalid machine code.');
        }

        return $value;
    }

    public static function text(string $value, int $maxBytes, bool $allowEmpty = false): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new CommerceValidationException('Invalid UTF-8 text.');
        }
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_C)
            : $value;
        if (!is_string($normalized)) {
            throw new CommerceValidationException('Invalid text.');
        }
        $normalized = trim($normalized);
        if ((!$allowEmpty && $normalized === '') || strlen($normalized) > $maxBytes) {
            throw new CommerceValidationException('Text is outside allowed bounds.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $normalized) === 1) {
            throw new CommerceValidationException('Text contains control characters.');
        }

        return $normalized;
    }

    public static function nullableText(?string $value, int $maxBytes): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::text($value, $maxBytes);
    }

    public static function basePath(string $value): string
    {
        $value = rtrim(trim($value), '/');
        if (
            $value === ''
            || strlen($value) > 512
            || $value[0] !== '/'
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, '//')
            || preg_match('#\A(?:/[a-z0-9]+(?:-[a-z0-9]+)*)+\z#', $value) !== 1
        ) {
            throw new CommerceValidationException('Invalid public base path.');
        }

        return $value;
    }

    public static function publicPath(string $value): string
    {
        $value = rtrim(trim($value), '/');
        if (
            $value === ''
            || strlen($value) > 1024
            || $value[0] !== '/'
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, '//')
            || preg_match('#\A(?:/[a-z0-9]+(?:-[a-z0-9]+)*)+\z#', $value) !== 1
        ) {
            throw new CommerceValidationException('Invalid public path.');
        }

        return $value;
    }

    public static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }

    public static function formatUtc(DateTimeImmutable $value): string
    {
        return self::utc($value)->format(self::UTC_FORMAT);
    }

    public static function parseUtc(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new CommerceException('Invalid stored timestamp.');
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!' . self::UTC_FORMAT,
            $value,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(self::UTC_FORMAT) !== $value
        ) {
            throw new CommerceException('Invalid stored timestamp.');
        }

        return $parsed;
    }
}
