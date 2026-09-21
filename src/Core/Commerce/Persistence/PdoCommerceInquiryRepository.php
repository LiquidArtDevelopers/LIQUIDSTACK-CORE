<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\CommerceValidationException;
use App\Core\Commerce\InquiryContact;
use App\Core\Commerce\InquiryResult;
use App\Core\Commerce\LocalizedProduct;
use DateTimeImmutable;
use JsonException;

final class PdoCommerceInquiryRepository extends AbstractPdoCommerceRepository implements CommerceInquiryRepositoryInterface
{
    private readonly string $baskets;
    private readonly string $basketItems;
    private readonly string $products;
    private readonly string $productMedia;
    private readonly string $inquiries;
    private readonly string $inquiryLines;
    private readonly string $inquiryOutbox;
    private readonly string $productInquiryStats;
    private readonly string $adminRecipientEmail;

    public function __construct(
        \PDO $pdo,
        CommerceTableNames $tables,
        private readonly CommerceCatalogRepositoryInterface $catalog,
        string $adminRecipientEmail
    ) {
        parent::__construct($pdo, $tables);
        $adminRecipientEmail = strtolower(trim($adminRecipientEmail));
        if (
            strlen($adminRecipientEmail) > 254
            || filter_var($adminRecipientEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new CommerceValidationException('Invalid Commerce admin recipient.');
        }
        $this->adminRecipientEmail = $adminRecipientEmail;
        $this->baskets = $tables->table('baskets');
        $this->basketItems = $tables->table('basket_items');
        $this->products = $tables->table('products');
        $this->productMedia = $tables->table('product_media');
        $this->inquiries = $tables->table('inquiries');
        $this->inquiryLines = $tables->table('inquiry_lines');
        $this->inquiryOutbox = $tables->table('inquiry_outbox');
        $this->productInquiryStats = $tables->table('product_inquiry_stats');
    }

    public function submit(
        string $inquiryPublicId,
        string $operationId,
        string $payloadSha256,
        string $basketToken,
        InquiryContact $contact,
        string $privacyVersion,
        string $primaryLocale,
        DateTimeImmutable $now
    ): InquiryResult {
        $inquiryPublicId = CommerceInput::uuid($inquiryPublicId);
        $operationId = CommerceInput::uuid($operationId);
        if (preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1) {
            throw new CommerceValidationException('Invalid inquiry payload digest.');
        }
        $basketTokenHash = self::tokenHash($basketToken);
        $privacyVersion = CommerceInput::text($privacyVersion, 64);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $timestamp = CommerceInput::formatUtc($now);

        $existing = $this->existingResult($operationId, $payloadSha256, false);
        if ($existing instanceof InquiryResult) {
            return $existing;
        }

        try {
            return $this->transactional(function () use (
                $inquiryPublicId,
                $operationId,
                $payloadSha256,
                $basketTokenHash,
                $contact,
                $privacyVersion,
                $primaryLocale,
                $timestamp
            ): InquiryResult {
            $existing = $this->existingResult($operationId, $payloadSha256, true);
            if ($existing instanceof InquiryResult) {
                return $existing;
            }
            $basket = $this->one(
                'SELECT id, locale, status, expires_at FROM ' . $this->baskets
                    . ' WHERE token_sha256 = :token_hash' . $this->forUpdate(),
                ['token_hash' => $basketTokenHash]
            );
            if (
                $basket === null
                || ($basket['status'] ?? null) !== 'open'
                || !is_string($basket['expires_at'] ?? null)
                || $basket['expires_at'] <= $timestamp
            ) {
                throw new CommerceConflictException(CommerceConflictException::BASKET_UNAVAILABLE);
            }
            $basketId = $this->positiveInt($basket['id'] ?? null);
            $locale = is_string($basket['locale'] ?? null)
                ? CommerceInput::locale($basket['locale'])
                : throw new CommercePersistenceException();
            $items = $this->all(
                'SELECT p.id AS product_id, p.public_id, p.sku, i.quantity '
                    . 'FROM ' . $this->basketItems . ' i JOIN ' . $this->products
                    . ' p ON p.id = i.product_id WHERE i.basket_id = :basket_id '
                    . 'ORDER BY p.id ASC' . $this->forUpdate(),
                ['basket_id' => $basketId]
            );
            if ($items === [] || count($items) > 50) {
                throw new CommerceConflictException(CommerceConflictException::BASKET_UNAVAILABLE);
            }
            $snapshots = [];
            foreach ($items as $item) {
                $publicId = CommerceInput::uuid((string) ($item['public_id'] ?? ''));
                $product = $this->catalog->localizedProduct($publicId, $locale, $primaryLocale);
                if (
                    !$product instanceof LocalizedProduct
                    || $product->editorialStatus()->value !== 'active'
                    || !$product->availabilityStatus()->acceptsInquiries()
                    || $product->publicPath() === null
                ) {
                    throw new CommerceConflictException(CommerceConflictException::INACTIVE_PRODUCT);
                }
                $media = $this->one(
                    'SELECT media_asset_public_id FROM ' . $this->productMedia
                        . ' WHERE product_id = :product_id AND role = :role '
                        . 'ORDER BY sort_order ASC LIMIT 1',
                    [
                        'product_id' => $this->positiveInt($item['product_id'] ?? null),
                        'role' => 'cover',
                    ]
                );
                $cover = $media['media_asset_public_id'] ?? null;
                if ($cover !== null && !is_string($cover)) {
                    throw new CommercePersistenceException();
                }
                $snapshots[] = [
                    'product_id' => $this->positiveInt($item['product_id'] ?? null),
                    'product_public_id' => $product->publicId(),
                    'sku' => is_string($item['sku'] ?? null) ? $item['sku'] : null,
                    'requested_locale' => $product->requestedLocale(),
                    'resolved_locale' => $product->resolvedLocale(),
                    'title' => $product->title(),
                    'public_path' => $product->publicPath(),
                    'cover_media_public_id' => $cover,
                    'quantity' => $this->positiveInt($item['quantity'] ?? null),
                    'unit_price_minor' => $product->price()?->minorUnits(),
                    'currency' => $product->price()?->currency(),
                    'availability_status' => $product->availabilityStatus()->value,
                ];
            }
            $this->write(
                'INSERT INTO ' . $this->inquiries . ' ('
                    . 'public_id, operation_id, payload_sha256, basket_id, locale, '
                    . 'contact_name, email, phone, message, privacy_version, created_at) '
                    . 'VALUES (:public_id, :operation_id, :payload_sha256, :basket_id, '
                    . ':locale, :contact_name, :email, :phone, :message, '
                    . ':privacy_version, :created_at)',
                [
                    'public_id' => $inquiryPublicId,
                    'operation_id' => $operationId,
                    'payload_sha256' => $payloadSha256,
                    'basket_id' => $basketId,
                    'locale' => $locale,
                    'contact_name' => $contact->name(),
                    'email' => $contact->email(),
                    'phone' => $contact->phone(),
                    'message' => $contact->message(),
                    'privacy_version' => $privacyVersion,
                    'created_at' => $timestamp,
                ]
            );
            $inquiryId = $this->lastInsertId();
            foreach ($snapshots as $snapshot) {
                $this->write(
                    'INSERT INTO ' . $this->inquiryLines . ' ('
                        . 'inquiry_id, product_public_id, sku, requested_locale, '
                        . 'resolved_locale, title, public_path, cover_media_public_id, '
                        . 'quantity, unit_price_minor, currency, availability_status) '
                        . 'VALUES (:inquiry_id, :product_public_id, :sku, '
                        . ':requested_locale, :resolved_locale, :title, :public_path, '
                        . ':cover_media_public_id, :quantity, :unit_price_minor, '
                        . ':currency, :availability_status)',
                    [
                        'inquiry_id' => $inquiryId,
                        'product_public_id' => $snapshot['product_public_id'],
                        'sku' => $snapshot['sku'],
                        'requested_locale' => $snapshot['requested_locale'],
                        'resolved_locale' => $snapshot['resolved_locale'],
                        'title' => $snapshot['title'],
                        'public_path' => $snapshot['public_path'],
                        'cover_media_public_id' => $snapshot['cover_media_public_id'],
                        'quantity' => $snapshot['quantity'],
                        'unit_price_minor' => $snapshot['unit_price_minor'],
                        'currency' => $snapshot['currency'],
                        'availability_status' => $snapshot['availability_status'],
                    ]
                );
                $this->incrementCounter((int) $snapshot['product_id'], $timestamp);
            }
            $publicSnapshots = array_map(
                static function (array $snapshot): array {
                    unset($snapshot['product_id']);

                    return $snapshot;
                },
                $snapshots
            );
            $this->enqueueInquiryMessage(
                $inquiryId,
                'requester',
                $contact->email(),
                'commerce.inquiry.requester',
                [
                    'inquiry_public_id' => $inquiryPublicId,
                    'locale' => $locale,
                    'items' => $publicSnapshots,
                ],
                $timestamp
            );
            $this->enqueueInquiryMessage(
                $inquiryId,
                'admin',
                $this->adminRecipientEmail,
                'commerce.inquiry.admin',
                [
                    'inquiry_public_id' => $inquiryPublicId,
                    'locale' => $locale,
                    'contact' => $contact->normalizedPayload(),
                    'items' => $publicSnapshots,
                ],
                $timestamp
            );
            if ($this->write(
                'UPDATE ' . $this->baskets . ' SET status = :status, '
                    . 'updated_at = :updated_at WHERE id = :id AND status = :open',
                [
                    'status' => 'submitted',
                    'updated_at' => $timestamp,
                    'id' => $basketId,
                    'open' => 'open',
                ]
            ) !== 1) {
                throw new CommerceConflictException(CommerceConflictException::BASKET_UNAVAILABLE);
            }

                return new InquiryResult(
                    $inquiryPublicId,
                    false,
                    count($snapshots),
                    CommerceInput::parseUtc($timestamp)
                );
            });
        } catch (CommercePersistenceException $exception) {
            // A concurrent request may have won the unique operation_id race.
            // Only an exact durable result converts that storage conflict into
            // a replay; every other persistence failure remains closed.
            $replayed = $this->existingResult($operationId, $payloadSha256, false);
            if ($replayed instanceof InquiryResult) {
                return $replayed;
            }

            throw $exception;
        }
    }

    private function existingResult(
        string $operationId,
        string $payloadSha256,
        bool $lock
    ): ?InquiryResult {
        $row = $this->one(
            'SELECT i.id, i.public_id, i.payload_sha256, i.created_at, '
                . '(SELECT COUNT(*) FROM ' . $this->inquiryLines
                . ' l WHERE l.inquiry_id = i.id) AS line_count FROM '
                . $this->inquiries . ' i WHERE i.operation_id = :operation_id'
                . ($lock ? $this->forUpdate() : ''),
            ['operation_id' => $operationId]
        );
        if ($row === null) {
            return null;
        }
        $storedHash = $row['payload_sha256'] ?? null;
        if (!is_string($storedHash) || !hash_equals($storedHash, $payloadSha256)) {
            throw new CommerceConflictException(CommerceConflictException::IDEMPOTENCY_MISMATCH);
        }

        return new InquiryResult(
            CommerceInput::uuid((string) ($row['public_id'] ?? '')),
            true,
            $this->positiveInt($row['line_count'] ?? null),
            CommerceInput::parseUtc($row['created_at'] ?? null)
        );
    }

    private function incrementCounter(int $productId, string $timestamp): void
    {
        if ($this->driver === 'sqlite') {
            $this->write(
                'INSERT INTO ' . $this->productInquiryStats
                    . ' (product_id, inquiry_count, updated_at) '
                    . 'VALUES (:product_id, 1, :updated_at) '
                    . 'ON CONFLICT(product_id) DO UPDATE SET '
                    . 'inquiry_count = inquiry_count + 1, '
                    . 'updated_at = excluded.updated_at',
                ['product_id' => $productId, 'updated_at' => $timestamp]
            );

            return;
        }
        $this->write(
            'INSERT INTO ' . $this->productInquiryStats
                . ' (product_id, inquiry_count, updated_at) '
                . 'VALUES (:product_id, 1, :updated_at) '
                . 'ON DUPLICATE KEY UPDATE inquiry_count = inquiry_count + 1, '
                . 'updated_at = VALUES(updated_at)',
            ['product_id' => $productId, 'updated_at' => $timestamp]
        );
    }

    /** @param array<string, mixed> $payload */
    private function enqueueInquiryMessage(
        int $inquiryId,
        string $audience,
        string $recipientEmail,
        string $templateKey,
        array $payload,
        string $timestamp
    ): void {
        try {
            $payloadJson = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new CommercePersistenceException();
        }
        $this->write(
            'INSERT INTO ' . $this->inquiryOutbox . ' ('
                . 'public_id, inquiry_id, audience, recipient_email, template_key, '
                . 'payload_json, status, attempts, available_at, created_at, updated_at) '
                . 'VALUES (:public_id, :inquiry_id, :audience, :recipient_email, '
                . ':template_key, :payload_json, :status, 0, :available_at, '
                . ':created_at, :updated_at)',
            [
                'public_id' => CommerceInput::newUuid(),
                'inquiry_id' => $inquiryId,
                'audience' => $audience,
                'recipient_email' => $recipientEmail,
                'template_key' => $templateKey,
                'payload_json' => $payloadJson,
                'status' => 'pending',
                'available_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private static function tokenHash(string $token): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new CommerceValidationException('Invalid basket token.');
        }

        return hash('sha256', $token);
    }
}
