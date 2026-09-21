<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use App\Core\Commerce\Http\CommercePublicMediaRoute;
use App\Core\WebAdmin\Mail\WebAdminMailConfiguration;
use App\Core\WebAdmin\Mail\WebAdminMailMessage;
use InvalidArgumentException;
use JsonException;

/** Builds bounded requester/admin messages solely from the durable snapshot. */
final class CommerceInquiryMailMessageFactory implements
    CommerceInquiryMailMessageFactoryInterface
{
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly WebAdminMailConfiguration $configuration
    ) {
    }

    public function create(CommerceOutboxLease $lease): WebAdminMailMessage
    {
        if ($lease->templateKey() !== 'commerce.inquiry.' . $lease->audience()) {
            throw new InvalidArgumentException('Invalid Commerce mail template.');
        }
        try {
            $payload = json_decode(
                $lease->payloadJson(),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid Commerce mail payload.');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Invalid Commerce mail payload.');
        }
        $allowed = $lease->audience() === 'admin'
            ? ['inquiry_public_id', 'locale', 'contact', 'items']
            : ['inquiry_public_id', 'locale', 'items'];
        $this->assertExactKeys($payload, $allowed);
        $inquiryId = $this->uuid($payload['inquiry_public_id'] ?? null);
        $locale = $this->locale($payload['locale'] ?? null);
        $items = $this->items($payload['items'] ?? null);
        $contact = $lease->audience() === 'admin'
            ? $this->contact($payload['contact'] ?? null)
            : null;
        $copy = $this->copy($locale);
        $subject = $lease->audience() === 'admin'
            ? $copy['admin_subject']
            : $copy['requester_subject'];
        $intro = $lease->audience() === 'admin'
            ? $copy['admin_intro']
            : $copy['requester_intro'];

        $text = $subject . "\n\n" . $intro . "\n"
            . $copy['reference'] . ': ' . $inquiryId . "\n\n";
        $html = '<!doctype html><html lang="' . $this->escape($locale)
            . '"><body><h1>' . $this->escape($subject) . '</h1><p>'
            . $this->escape($intro) . '</p><p><strong>'
            . $this->escape($copy['reference']) . ':</strong> '
            . $this->escape($inquiryId) . '</p>';

        if (is_array($contact)) {
            $text .= $copy['contact'] . "\n" . $contact['name'] . "\n"
                . $contact['email'] . "\n";
            if ($contact['phone'] !== null) {
                $text .= $contact['phone'] . "\n";
            }
            if ($contact['message'] !== null) {
                $text .= $contact['message'] . "\n";
            }
            $html .= '<h2>' . $this->escape($copy['contact']) . '</h2><p>'
                . $this->escape($contact['name']) . '<br>'
                . $this->escape($contact['email']);
            if ($contact['phone'] !== null) {
                $html .= '<br>' . $this->escape($contact['phone']);
            }
            $html .= '</p>';
            if ($contact['message'] !== null) {
                $html .= '<p>'
                    . nl2br($this->escape($contact['message'])) . '</p>';
            }
        }

        $text .= "\n" . $copy['products'] . "\n";
        $html .= '<h2>' . $this->escape($copy['products']) . '</h2><ul>';
        foreach ($items as $item) {
            $url = $this->absoluteUrl($item['public_path']);
            $price = $this->price($item, $copy['price_on_request']);
            $reference = $item['sku'] ?? $item['product_public_id'];
            $text .= '- ' . $item['title'] . ' × ' . $item['quantity']
                . ' — ' . $copy['reference'] . ': ' . $reference
                . ' — ' . $price . ' — ' . $url . "\n";
            $html .= '<li>';
            if ($item['cover_media_public_id'] !== null) {
                $image = $this->absoluteUrl(CommercePublicMediaRoute::path(
                    $item['cover_media_public_id'],
                    640
                ));
                $html .= '<a href="' . $this->escape($url) . '"><img src="'
                    . $this->escape($image) . '" alt="'
                    . $this->escape($item['title'])
                    . '" width="640"></a><br>';
            }
            $html .= '<a href="' . $this->escape($url) . '">'
                . $this->escape($item['title']) . '</a> × '
                . $item['quantity'] . '<br><small>'
                . $this->escape($copy['reference'] . ': ' . $reference)
                . ' · ' . $this->escape($price) . '</small></li>';
        }
        $html .= '</ul></body></html>';

        return new WebAdminMailMessage(
            $lease->recipientEmail(),
            null,
            $subject,
            $text,
            $html
        );
    }

    /**
     * @param mixed $value
     * @return list<array{
     *   product_public_id:string,sku:?string,requested_locale:string,
     *   resolved_locale:string,title:string,public_path:string,
     *   cover_media_public_id:?string,quantity:int,unit_price_minor:?int,
     *   currency:?string,availability_status:string
     * }>
     */
    private function items(mixed $value): array
    {
        if (
            !is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > self::MAX_ITEMS
        ) {
            throw new InvalidArgumentException('Invalid Commerce mail items.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('Invalid Commerce mail item.');
            }
            $this->assertExactKeys($item, [
                'product_public_id', 'sku', 'requested_locale',
                'resolved_locale', 'title', 'public_path',
                'cover_media_public_id', 'quantity', 'unit_price_minor',
                'currency', 'availability_status',
            ]);
            $productPublicId = $this->uuid($item['product_public_id'] ?? null);
            $sku = $item['sku'] ?? null;
            $title = $item['title'] ?? null;
            $path = $item['public_path'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $requestedLocale = $this->locale($item['requested_locale'] ?? null);
            $resolvedLocale = $this->locale($item['resolved_locale'] ?? null);
            $cover = $item['cover_media_public_id'] ?? null;
            $minor = $item['unit_price_minor'] ?? null;
            $currency = $item['currency'] ?? null;
            $availability = $item['availability_status'] ?? null;
            if (
                !is_string($title)
                || trim($title) === ''
                || strlen($title) > 255
                || preg_match('//u', $title) !== 1
                || !is_string($path)
                || !$this->validPath($path)
                || !is_int($quantity)
                || $quantity < 1
                || $quantity > 50
                || ($sku !== null && (
                    !is_string($sku) || strlen($sku) > 190
                ))
                || ($cover !== null && $this->uuid($cover) !== $cover)
                || (($minor === null) !== ($currency === null))
                || ($minor !== null && (!is_int($minor) || $minor < 0))
                || ($currency !== null && (
                    !is_string($currency)
                    || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
                ))
                || !is_string($availability)
                || !in_array($availability, [
                    'available', 'reserved', 'sold', 'unavailable',
                ], true)
            ) {
                throw new InvalidArgumentException('Invalid Commerce mail item.');
            }
            $items[] = [
                'product_public_id' => $productPublicId,
                'sku' => $sku,
                'requested_locale' => $requestedLocale,
                'resolved_locale' => $resolvedLocale,
                'title' => trim($title),
                'public_path' => $path,
                'cover_media_public_id' => $cover,
                'quantity' => $quantity,
                'unit_price_minor' => $minor,
                'currency' => $currency,
                'availability_status' => $availability,
            ];
        }

        return $items;
    }

    /** @return array{name:string,email:string,phone:?string,message:?string} */
    private function contact(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Invalid Commerce mail contact.');
        }
        $this->assertExactKeys($value, ['name', 'email', 'phone', 'message']);
        $name = $value['name'] ?? null;
        $email = $value['email'] ?? null;
        $phone = $value['phone'] ?? null;
        $message = $value['message'] ?? null;
        if (
            !is_string($name)
            || trim($name) === ''
            || strlen($name) > 180
            || !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ($phone !== null && (
                !is_string($phone) || strlen($phone) > 64
            ))
            || ($message !== null && (
                !is_string($message) || strlen($message) > 8_000
            ))
        ) {
            throw new InvalidArgumentException('Invalid Commerce mail contact.');
        }

        return [
            'name' => trim($name),
            'email' => $email,
            'phone' => $phone,
            'message' => $message,
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException(
                'Invalid Commerce mail payload shape.'
            );
        }
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value
        ) !== 1) {
            throw new InvalidArgumentException(
                'Invalid Commerce inquiry reference.'
            );
        }

        return $value;
    }

    private function locale(mixed $value): string
    {
        if (!is_string($value) || preg_match(
            '/\A[a-z]{2}(?:-[a-z0-9]{2,8})?\z/',
            $value
        ) !== 1) {
            throw new InvalidArgumentException('Invalid Commerce mail locale.');
        }

        return $value;
    }

    private function validPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !str_contains($path, "\0")
            && !str_contains($path, '..')
            && !str_contains($path, '?')
            && !str_contains($path, '#')
            && strlen($path) <= 1_024;
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim($this->configuration->publicOrigin(), '/') . $path;
    }

    /** @param array{unit_price_minor:?int,currency:?string} $item */
    private function price(array $item, string $fallback): string
    {
        if ($item['unit_price_minor'] === null || $item['currency'] === null) {
            return $fallback;
        }

        return number_format(
            $item['unit_price_minor'] / 100,
            2,
            ',',
            '.'
        ) . ' ' . $item['currency'];
    }

    /** @return array<string, string> */
    private function copy(string $locale): array
    {
        $language = strtolower(explode('-', $locale, 2)[0]);
        $catalog = [
            'es' => [
                'admin_subject' => 'Nueva solicitud de información',
                'requester_subject' => 'Hemos recibido tu solicitud',
                'admin_intro' => 'Se ha registrado una nueva solicitud con los siguientes datos.',
                'requester_intro' => 'Este es el resumen de los productos por los que has pedido información.',
                'reference' => 'Referencia',
                'contact' => 'Contacto',
                'products' => 'Productos',
                'price_on_request' => 'Precio bajo consulta',
            ],
            'eu' => [
                'admin_subject' => 'Informazio-eskaera berria',
                'requester_subject' => 'Zure eskaera jaso dugu',
                'admin_intro' => 'Informazio-eskaera berria erregistratu da datu hauekin.',
                'requester_intro' => 'Hauei buruz eskatu duzun informazioaren laburpena da hau.',
                'reference' => 'Erreferentzia',
                'contact' => 'Kontaktua',
                'products' => 'Produktuak',
                'price_on_request' => 'Prezioa kontsultatu',
            ],
            'en' => [
                'admin_subject' => 'New information request',
                'requester_subject' => 'We received your request',
                'admin_intro' => 'A new request was registered with the following details.',
                'requester_intro' => 'This is the summary of the products you asked about.',
                'reference' => 'Reference',
                'contact' => 'Contact',
                'products' => 'Products',
                'price_on_request' => 'Price on request',
            ],
        ];

        return $catalog[$language] ?? $catalog['en'];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
