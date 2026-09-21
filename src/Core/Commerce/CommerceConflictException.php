<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommerceConflictException extends CommerceException
{
    public const NOT_FOUND = 'not_found';
    public const STALE = 'stale';
    public const DUPLICATE = 'duplicate';
    public const CATEGORY_CYCLE = 'category_cycle';
    public const INACTIVE_PRODUCT = 'inactive_product';
    public const BASKET_UNAVAILABLE = 'basket_unavailable';
    public const IDEMPOTENCY_MISMATCH = 'idempotency_mismatch';

    public function __construct(private readonly string $kind)
    {
        parent::__construct('Commerce operation conflicts with current state.');
    }

    public function kind(): string
    {
        return $this->kind;
    }
}
