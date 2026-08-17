<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use App\Core\Blog\BlogPostVariant;

/** Resolves persisted preferences plus an optional fail-closed override. */
final class BlogPublicRobotsPolicy
{
    public function __construct(
        private readonly ?BlogPublicRobotsOverrideInterface $override = null
    ) {
    }

    public function effectiveFor(
        BlogPostVariant $variant
    ): BlogRobotsPreferences {
        if ($this->override?->forcesNoIndexNoFollow($variant) === true) {
            return BlogRobotsPreferences::noIndexNoFollow();
        }

        return $variant->draft()->robotsPreferences();
    }
}
