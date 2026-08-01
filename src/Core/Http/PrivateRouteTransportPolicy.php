<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Environment\ProjectRuntimeProfile;
use InvalidArgumentException;

/**
 * Keeps private routes HTTPS-only, with one narrow exception for the local
 * development server declared by the project's own RAIZ and DEV_MODE values.
 *
 * Forwarded headers are intentionally irrelevant: Request reports only the
 * transport and peer asserted by the directly connected web server. The Host
 * value must also use the exact canonical authority stored in RAIZ (including
 * its port and lowercase DNS spelling), which rejects aliases and ambiguous
 * multiple Host values.
 */
final class PrivateRouteTransportPolicy
{
    /** @param array<string, mixed> $environment */
    public function accepts(
        Request $request,
        #[\SensitiveParameter] array $environment
    ): bool {
        if ($request->isSecureTransport()) {
            return true;
        }

        if (!$request->hasValidHeaders()) {
            return false;
        }

        try {
            $profile = ProjectRuntimeProfile::fromEnvironment($environment);
        } catch (InvalidArgumentException) {
            return false;
        }

        if (!$profile->isDevelopmentLoopbackHttp()) {
            return false;
        }

        if (!self::isLoopbackPeer($request->clientIp())) {
            return false;
        }

        return $request->header('host') === $profile->authority();
    }

    private static function isLoopbackPeer(?string $address): bool
    {
        if ($address === null || $address === '') {
            return false;
        }

        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return false;
        }

        foreach (['127.0.0.1', '::1', '::ffff:127.0.0.1'] as $loopback) {
            $candidate = inet_pton($loopback);
            if (is_string($candidate) && hash_equals($candidate, $packed)) {
                return true;
            }
        }

        return false;
    }
}
