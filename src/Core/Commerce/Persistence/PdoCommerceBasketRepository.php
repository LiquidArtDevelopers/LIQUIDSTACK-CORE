<?php

declare(strict_types=1);

namespace App\Core\Commerce\Persistence;

use App\Core\Commerce\BasketLine;
use App\Core\Commerce\BasketSnapshot;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\CommerceValidationException;
use App\Core\Commerce\LocalizedProduct;
use App\Core\Commerce\ProductAvailabilityStatus;
use App\Core\Commerce\ProductEditorialStatus;
use DateTimeImmutable;

final class PdoCommerceBasketRepository extends AbstractPdoCommerceRepository implements CommerceBasketRepositoryInterface
{
    private const MAX_LINES = 50;

    private readonly string $baskets;
    private readonly string $basketItems;
    private readonly string $products;

    public function __construct(
        \PDO $pdo,
        CommerceTableNames $tables,
        private readonly CommerceCatalogRepositoryInterface $catalog
    ) {
        parent::__construct($pdo, $tables);
        $this->baskets = $tables->table('baskets');
        $this->basketItems = $tables->table('basket_items');
        $this->products = $tables->table('products');
    }

    public function create(
        string $publicId,
        string $token,
        string $locale,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now
    ): void {
        $publicId = CommerceInput::uuid($publicId);
        $tokenHash = self::tokenHash($token);
        $locale = CommerceInput::locale($locale);
        $expiresAt = CommerceInput::utc($expiresAt);
        $now = CommerceInput::utc($now);
        if ($expiresAt <= $now) {
            throw new CommerceValidationException('Basket expiry must be in the future.');
        }
        $this->write(
            'INSERT INTO ' . $this->baskets . ' ('
                . 'public_id, token_sha256, locale, status, expires_at, created_at, updated_at) '
                . 'VALUES (:public_id, :token_hash, :locale, :status, :expires_at, '
                . ':created_at, :updated_at)',
            [
                'public_id' => $publicId,
                'token_hash' => $tokenHash,
                'locale' => $locale,
                'status' => 'open',
                'expires_at' => CommerceInput::formatUtc($expiresAt),
                'created_at' => CommerceInput::formatUtc($now),
                'updated_at' => CommerceInput::formatUtc($now),
            ]
        );
    }

    public function put(
        string $token,
        string $productPublicId,
        int $quantity,
        DateTimeImmutable $now
    ): void {
        $tokenHash = self::tokenHash($token);
        $productPublicId = CommerceInput::uuid($productPublicId);
        if ($quantity < 1 || $quantity > 99) {
            throw new CommerceValidationException('Invalid basket quantity.');
        }
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use (
            $tokenHash,
            $productPublicId,
            $quantity,
            $timestamp
        ): void {
            $basket = $this->openBasket($tokenHash, $timestamp, true);
            $product = $this->one(
                'SELECT id, editorial_status, availability_status FROM '
                    . $this->products . ' WHERE public_id = :public_id'
                    . $this->forUpdate(),
                ['public_id' => $productPublicId]
            );
            if ($product === null) {
                throw new CommerceConflictException(CommerceConflictException::NOT_FOUND);
            }
            if (
                ($product['editorial_status'] ?? null) !== ProductEditorialStatus::ACTIVE->value
                || !ProductAvailabilityStatus::from(
                    (string) ($product['availability_status'] ?? '')
                )->acceptsInquiries()
            ) {
                throw new CommerceConflictException(CommerceConflictException::INACTIVE_PRODUCT);
            }
            $basketId = $this->positiveInt($basket['id'] ?? null);
            $productId = $this->positiveInt($product['id'] ?? null);
            $existing = $this->one(
                'SELECT quantity FROM ' . $this->basketItems
                    . ' WHERE basket_id = :basket_id AND product_id = :product_id'
                    . $this->forUpdate(),
                ['basket_id' => $basketId, 'product_id' => $productId]
            );
            if ($existing === null) {
                $count = $this->one(
                    'SELECT COUNT(*) AS total FROM ' . $this->basketItems
                        . ' WHERE basket_id = :basket_id',
                    ['basket_id' => $basketId]
                );
                if ($count === null || $this->nonNegativeInt($count['total'] ?? null) >= self::MAX_LINES) {
                    throw new CommerceValidationException('Basket item limit reached.');
                }
                $this->write(
                    'INSERT INTO ' . $this->basketItems . ' ('
                        . 'basket_id, product_id, quantity, created_at, updated_at) '
                        . 'VALUES (:basket_id, :product_id, :quantity, :created_at, :updated_at)',
                    [
                        'basket_id' => $basketId,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]
                );
            } elseif ($this->positiveInt($existing['quantity'] ?? null) !== $quantity) {
                $this->write(
                    'UPDATE ' . $this->basketItems . ' SET quantity = :quantity, '
                        . 'updated_at = :updated_at WHERE basket_id = :basket_id '
                        . 'AND product_id = :product_id',
                    [
                        'quantity' => $quantity,
                        'updated_at' => $timestamp,
                        'basket_id' => $basketId,
                        'product_id' => $productId,
                    ]
                );
            }
            $this->write(
                'UPDATE ' . $this->baskets . ' SET updated_at = :updated_at WHERE id = :id',
                ['updated_at' => $timestamp, 'id' => $basketId]
            );
        });
    }

    public function remove(
        string $token,
        string $productPublicId,
        DateTimeImmutable $now
    ): void {
        $tokenHash = self::tokenHash($token);
        $productPublicId = CommerceInput::uuid($productPublicId);
        $timestamp = CommerceInput::formatUtc($now);
        $this->transactional(function () use ($tokenHash, $productPublicId, $timestamp): void {
            $basket = $this->openBasket($tokenHash, $timestamp, true);
            $product = $this->one(
                'SELECT id FROM ' . $this->products . ' WHERE public_id = :public_id',
                ['public_id' => $productPublicId]
            );
            if ($product !== null) {
                $this->write(
                    'DELETE FROM ' . $this->basketItems . ' WHERE basket_id = :basket_id '
                        . 'AND product_id = :product_id',
                    [
                        'basket_id' => $this->positiveInt($basket['id'] ?? null),
                        'product_id' => $this->positiveInt($product['id'] ?? null),
                    ]
                );
            }
            $this->write(
                'UPDATE ' . $this->baskets . ' SET updated_at = :updated_at WHERE id = :id',
                [
                    'updated_at' => $timestamp,
                    'id' => $this->positiveInt($basket['id'] ?? null),
                ]
            );
        });
    }

    public function snapshot(
        string $token,
        string $primaryLocale,
        DateTimeImmutable $now
    ): ?BasketSnapshot {
        $tokenHash = self::tokenHash($token);
        $primaryLocale = CommerceInput::locale($primaryLocale);
        $timestamp = CommerceInput::formatUtc($now);
        $basket = $this->one(
            'SELECT id, public_id, locale, status, expires_at FROM '
                . $this->baskets . ' WHERE token_sha256 = :token_hash',
            ['token_hash' => $tokenHash]
        );
        if ($basket === null) {
            return null;
        }
        $status = is_string($basket['status'] ?? null)
            ? $basket['status']
            : throw new CommercePersistenceException();
        $expiresAt = CommerceInput::parseUtc($basket['expires_at'] ?? null);
        if ($status === 'open' && CommerceInput::formatUtc($expiresAt) <= $timestamp) {
            $status = 'expired';
        }
        $locale = is_string($basket['locale'] ?? null)
            ? CommerceInput::locale($basket['locale'])
            : throw new CommercePersistenceException();
        $rows = $this->all(
            'SELECT p.public_id, i.quantity FROM ' . $this->basketItems
                . ' i JOIN ' . $this->products . ' p ON p.id = i.product_id '
                . 'WHERE i.basket_id = :basket_id '
                . 'ORDER BY i.created_at ASC, p.id ASC',
            ['basket_id' => $this->positiveInt($basket['id'] ?? null)]
        );
        $lines = [];
        foreach ($rows as $row) {
            $product = $this->catalog->localizedProduct(
                (string) ($row['public_id'] ?? ''),
                $locale,
                $primaryLocale
            );
            if (!$product instanceof LocalizedProduct) {
                throw new CommercePersistenceException();
            }
            $lines[] = new BasketLine($product, $this->positiveInt($row['quantity'] ?? null));
        }

        return new BasketSnapshot(
            CommerceInput::uuid((string) ($basket['public_id'] ?? '')),
            $token,
            $locale,
            $status,
            $expiresAt,
            $lines
        );
    }

    /** @return array<string, mixed> */
    private function openBasket(string $tokenHash, string $timestamp, bool $lock): array
    {
        $basket = $this->one(
            'SELECT id, status, expires_at FROM ' . $this->baskets
                . ' WHERE token_sha256 = :token_hash' . ($lock ? $this->forUpdate() : ''),
            ['token_hash' => $tokenHash]
        );
        if (
            $basket === null
            || ($basket['status'] ?? null) !== 'open'
            || !is_string($basket['expires_at'] ?? null)
            || $basket['expires_at'] <= $timestamp
        ) {
            throw new CommerceConflictException(CommerceConflictException::BASKET_UNAVAILABLE);
        }

        return $basket;
    }

    private static function tokenHash(string $token): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new CommerceValidationException('Invalid basket token.');
        }

        return hash('sha256', $token);
    }
}
