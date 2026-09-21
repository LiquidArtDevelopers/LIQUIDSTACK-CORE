<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Environment\ProjectRuntimeProfile;

final class CommerceBasketCookie
{
    private const PREFIX = 'ls_commerce_basket';

    public function __construct(
        private readonly string $name,
        private readonly bool $secure,
        private readonly int $ttlSeconds
    ) {
    }

    /** @param array<string, mixed> $environment */
    public static function forProject(
        string $projectRoot,
        array $environment,
        int $ttlSeconds
    ): self {
        $profile = ProjectRuntimeProfile::fromEnvironment($environment);
        $name = self::PREFIX;
        if ($profile->isDevelopmentLoopbackHttp()) {
            $identity = $environment['LIQUIDSTACK_DEV_PROJECT_ID'] ?? null;
            if (!is_string($identity) || trim($identity) === '') {
                $resolved = realpath($projectRoot);
                $identity = is_string($resolved) ? $resolved : $projectRoot;
            }
            $name .= '_' . substr(hash('sha256', $identity), 0, 12);
        }

        return new self(
            $name,
            str_starts_with($profile->origin(), 'https://'),
            $ttlSeconds
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function issue(string $token): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
            throw new \InvalidArgumentException('Invalid Commerce basket token.');
        }

        return $this->serialize($token, $this->ttlSeconds);
    }

    public function expire(): string
    {
        return $this->serialize('', 0);
    }

    private function serialize(string $value, int $maxAge): string
    {
        return $this->name . '=' . $value
            . '; Path=/; Max-Age=' . $maxAge
            . '; HttpOnly; SameSite=Lax'
            . ($this->secure ? '; Secure' : '');
    }
}
