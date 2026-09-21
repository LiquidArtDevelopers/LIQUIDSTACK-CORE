<?php

declare(strict_types=1);

namespace App\Core\Commerce;

enum ProductEditorialStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ARCHIVED = 'archived';
}
