<?php

declare(strict_types=1);

namespace App\Core\Commerce;

enum CommerceAttributeType: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case SELECT = 'select';
    case MULTISELECT = 'multiselect';
    case DATE = 'date';
}
