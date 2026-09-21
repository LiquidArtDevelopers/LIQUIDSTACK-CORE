<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\BasketSnapshot;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\InquiryContact;
use App\Core\Http\Request;
use App\Core\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class CommerceInquiryHttpController
{
    public function __construct(
        private readonly CommerceInquiryHttpRuntime $runtime
    ) {
    }

    public function add(Request $request): Response
    {
        if (!$this->validBrowserWrite($request)) {
            return $this->plain(400, 'Bad request');
        }
        try {
            $locale = $this->locale($request);
            $productPublicId = CommerceInput::uuid(
                (string) $request->form('product')
            );
            $now = $this->now();
            $token = $request->cookie($this->runtime->cookie()->name());
            $basket = $this->basket($token, $locale, $now);
            $alreadyPresent = false;
            foreach ($basket->lines() as $line) {
                if ($line->product()->publicId() === $productPublicId) {
                    $alreadyPresent = true;
                    break;
                }
            }
            if (
                !$alreadyPresent
                && count($basket->lines())
                    >= $this->runtime->config()->basketMaxItems()
            ) {
                return $this->plain(409, 'Interest list is full');
            }
            $basket = $this->runtime->baskets()->put(
                $basket->token(),
                $productPublicId,
                1,
                $now
            );

            return $this->redirect(
                $this->returnPath($request, $locale),
                $this->runtime->cookie()->issue($basket->token())
            );
        } catch (CommerceConflictException) {
            return $this->plain(409, 'Product is unavailable');
        } catch (Throwable) {
            return $this->plain(400, 'Bad request');
        }
    }

    public function remove(Request $request): Response
    {
        if (!$this->validBrowserWrite($request)) {
            return $this->plain(400, 'Bad request');
        }
        try {
            $locale = $this->locale($request);
            $productPublicId = CommerceInput::uuid(
                (string) $request->form('product')
            );
            $token = $request->cookie($this->runtime->cookie()->name());
            if (!is_string($token) || $token === '') {
                return $this->redirect($this->returnPath($request, $locale));
            }
            $basket = $this->runtime->baskets()->remove(
                $token,
                $productPublicId,
                $this->now()
            );

            return $this->redirect(
                $this->returnPath($request, $locale),
                $this->runtime->cookie()->issue($basket->token())
            );
        } catch (CommerceConflictException) {
            return $this->plain(409, 'Interest list is unavailable');
        } catch (Throwable) {
            return $this->plain(400, 'Bad request');
        }
    }

    public function submit(Request $request): Response
    {
        if (!$this->validBrowserWrite($request)) {
            return $this->plain(400, 'Bad request');
        }
        if (trim((string) $request->form('company_website')) !== '') {
            return $this->plain(400, 'Bad request');
        }
        if ((string) $request->form('privacy_accepted') !== '1') {
            return $this->plain(422, 'Privacy consent is required');
        }
        try {
            $locale = $this->locale($request);
            $token = $request->cookie($this->runtime->cookie()->name());
            if (!is_string($token) || $token === '') {
                throw new CommerceConflictException(
                    CommerceConflictException::BASKET_UNAVAILABLE
                );
            }
            $now = $this->now();
            $contact = new InquiryContact(
                (string) $request->form('name'),
                (string) $request->form('email'),
                $this->optional($request->form('phone')),
                $this->optional($request->form('message'))
            );
            $abuseGuard = $this->runtime->abuseGuard();
            if (
                $abuseGuard !== null
                && !$abuseGuard->allows($request->clientIp(), $contact, $now)
            ) {
                return $this->rateLimited();
            }
            $this->runtime->inquiries()->submit(
                CommerceInput::uuid((string) $request->form('operation_id')),
                $token,
                $contact,
                $this->runtime->inquiryEnvironment()->privacyVersion(),
                $now
            );
            $path = $this->runtime->config()->inquiryPath($locale);
            if ($path === null) {
                throw new \RuntimeException('Inquiry route unavailable.');
            }

            return $this->redirect(
                $path . '#solicitud-enviada',
                $this->runtime->cookie()->expire()
            );
        } catch (CommerceConflictException) {
            return $this->plain(409, 'Interest list is unavailable');
        } catch (Throwable) {
            return $this->plain(422, 'Invalid inquiry');
        }
    }

    private function basket(
        ?string $token,
        string $locale,
        DateTimeImmutable $now
    ): BasketSnapshot {
        if (is_string($token) && $token !== '') {
            try {
                $basket = $this->runtime->baskets()->view($token, $now);
                if ($basket instanceof BasketSnapshot) {
                    return $basket;
                }
            } catch (Throwable) {
                // An opaque invalid/expired cookie is replaced uniformly.
            }
        }

        return $this->runtime->baskets()->create(
            $locale,
            $now,
            $this->runtime->config()->basketTtlSeconds()
        );
    }

    private function validBrowserWrite(Request $request): bool
    {
        if (!$request->isValid()) {
            return false;
        }
        $fetchSite = strtolower((string) $request->header('Sec-Fetch-Site', ''));
        if ($fetchSite !== '' && !in_array(
            $fetchSite,
            ['same-origin', 'none'],
            true
        )) {
            return false;
        }
        $origin = $request->header('Origin');

        return $origin === null || hash_equals($this->runtime->origin(), $origin);
    }

    private function locale(Request $request): string
    {
        $locale = strtolower(trim((string) $request->form('locale')));
        if (!array_key_exists(
            $locale,
            $this->runtime->config()->publicPaths()
        )) {
            throw new \InvalidArgumentException('Invalid locale.');
        }

        return $locale;
    }

    private function returnPath(Request $request, string $locale): string
    {
        $candidate = (string) $request->form('return_to');
        $base = $this->runtime->config()->publicPath($locale);
        if (
            $base === null
            || ($candidate !== $base
                && !str_starts_with($candidate, $base . '/'))
            || str_contains($candidate, '?')
            || str_contains($candidate, '#')
            || str_starts_with($candidate, '//')
        ) {
            return $this->runtime->config()->inquiryPath($locale) ?? $base ?? '/';
        }

        return $candidate;
    }

    private function optional(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function redirect(string $path, ?string $cookie = null): Response
    {
        $headers = [
            'Location' => $path,
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($cookie !== null) {
            $headers['Set-Cookie'] = $cookie;
        }

        return new Response(303, '', $headers);
    }

    private function plain(int $status, string $message): Response
    {
        return new Response($status, $message, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function rateLimited(): Response
    {
        return new Response(429, 'Too many requests', [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '600',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
