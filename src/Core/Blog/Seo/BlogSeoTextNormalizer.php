<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

/** Unicode-aware, locale-stable text operations for deterministic checks. */
final class BlogSeoTextNormalizer
{
    /** @var array<string, list<string>> */
    private const STOP_WORDS = [
        'es' => ['a','al','con','de','del','el','en','es','la','las','lo','los','o','para','por','que','se','sin','su','sus','un','una','y'],
        'en' => ['a','an','and','are','as','at','be','by','for','from','in','is','it','of','on','or','the','to','with'],
        'eu' => ['bat','da','eta','edo','ere','ez','hau','hori','izan','nola','nor','zer'],
    ];

    public function __construct(private readonly bool $useMbstring = true)
    {
    }

    public function length(string $value): int
    {
        $value = $this->normalizeUnicode($value);
        $matched = preg_match_all('/./us', $value, $matches);
        if ($matched === false) {
            throw new \InvalidArgumentException('Invalid Unicode text.');
        }

        return $matched;
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $value = $this->lower($this->normalizeUnicode($value));
        $matched = preg_match_all(
            "/[\\p{L}\\p{N}]+(?:[’'][\\p{L}\\p{N}]+)*/u",
            $value,
            $matches
        );
        if ($matched === false) {
            throw new \InvalidArgumentException('Invalid Unicode text.');
        }

        return array_values($matches[0]);
    }

    /** @return list<string> */
    public function meaningfulTokens(string $value, string $locale): array
    {
        $language = explode('-', $locale, 2)[0];
        $stops = array_fill_keys(self::STOP_WORDS[$language] ?? [], true);

        return array_values(array_filter(
            $this->tokens($value),
            fn (string $token): bool =>
                !isset($stops[$token]) && $this->length($token) > 2
        ));
    }

    public function phrase(string $value, string $locale): string
    {
        return implode(' ', $this->meaningfulTokens($value, $locale));
    }

    private function normalizeUnicode(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Unicode text.');
        }
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return $value;
    }

    private function lower(string $value): string
    {
        if ($this->useMbstring && function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower(strtr($value, [
            'Á' => 'á', 'À' => 'à', 'Â' => 'â', 'Ä' => 'ä',
            'É' => 'é', 'È' => 'è', 'Ê' => 'ê', 'Ë' => 'ë',
            'Í' => 'í', 'Ì' => 'ì', 'Î' => 'î', 'Ï' => 'ï',
            'Ó' => 'ó', 'Ò' => 'ò', 'Ô' => 'ô', 'Ö' => 'ö',
            'Ú' => 'ú', 'Ù' => 'ù', 'Û' => 'û', 'Ü' => 'ü',
            'Ñ' => 'ñ', 'Ç' => 'ç',
        ]));
    }
}
