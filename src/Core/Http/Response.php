<?php

declare(strict_types=1);

namespace App\Core\Http;

use InvalidArgumentException;

final class Response
{
    /** @var list<array{name: string, value: string}> */
    private array $headerLines = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly string $body = '',
        array $headers = []
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('El estado HTTP no es válido.');
        }

        foreach ($headers as $name => $values) {
            $this->assertHeaderName($name);
            $values = is_array($values) ? $values : [$values];
            if ($values === [] || !array_is_list($values)) {
                throw new InvalidArgumentException(
                    'Los valores de la cabecera HTTP no son válidos.'
                );
            }

            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException(
                        'El valor de la cabecera HTTP no es válido.'
                    );
                }
                $this->assertHeaderValue($value);
                $this->headerLines[] = [
                    'name' => $name,
                    'value' => $value,
                ];
            }
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Backwards-compatible single-value view. When a header is repeated, its
     * last value is returned; use headerValues() or headerLines() when order
     * and repetition matter (notably for Set-Cookie).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        $names = [];
        foreach ($this->headerLines as $line) {
            $normalized = strtolower($line['name']);
            $displayName = $names[$normalized] ?? $line['name'];
            $names[$normalized] = $displayName;
            $headers[$displayName] = $line['value'];
        }

        return $headers;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        $this->assertHeaderName($name);
        $normalized = strtolower($name);
        $values = [];
        foreach ($this->headerLines as $line) {
            if (strtolower($line['name']) === $normalized) {
                $values[] = $line['value'];
            }
        }

        return $values;
    }

    /** @return list<array{name: string, value: string}> */
    public function headerLines(): array
    {
        return $this->headerLines;
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $this->assertHeaderName($name);
        $this->assertHeaderValue($value);
        $headers = $this->groupedHeaders();
        $key = $this->existingHeaderName($name) ?? $name;
        $headers[$key] ??= [];
        $headers[$key][] = $value;

        return new self($this->status, $this->body, $headers);
    }

    public function withoutBody(): self
    {
        return new self($this->status, '', $this->groupedHeaders());
    }

    public function emit(): void
    {
        http_response_code($this->status);

        $emitted = [];
        foreach ($this->headerLines as $line) {
            $normalized = strtolower($line['name']);
            $replace = !isset($emitted[$normalized]);
            header($line['name'] . ': ' . $line['value'], $replace);
            $emitted[$normalized] = true;
        }

        echo $this->body;
    }

    /** @return array<string, list<string>> */
    private function groupedHeaders(): array
    {
        $headers = [];
        $names = [];
        foreach ($this->headerLines as $line) {
            $normalized = strtolower($line['name']);
            $displayName = $names[$normalized] ?? $line['name'];
            $names[$normalized] = $displayName;
            $headers[$displayName] ??= [];
            $headers[$displayName][] = $line['value'];
        }

        return $headers;
    }

    private function existingHeaderName(string $name): ?string
    {
        $normalized = strtolower($name);
        foreach ($this->headerLines as $line) {
            if (strtolower($line['name']) === $normalized) {
                return $line['name'];
            }
        }

        return null;
    }

    private function assertHeaderName(mixed $name): void
    {
        if (
            !is_string($name)
            || preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name) !== 1
        ) {
            throw new InvalidArgumentException(
                'El nombre de la cabecera HTTP no es válido.'
            );
        }
    }

    private function assertHeaderValue(string $value): void
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                'El valor de la cabecera HTTP no es válido.'
            );
        }
    }
}
