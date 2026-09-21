<?php

declare(strict_types=1);

namespace App\Core\Commerce;

enum CommerceTranslationStatus: string
{
    case SOURCE = 'source';
    case TRANSLATED = 'translated';
    case FALLBACK = 'fallback';
}
