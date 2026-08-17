<?php

declare(strict_types=1);

namespace Tests\Blog\Seo;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Seo\BlogPublicRobotsOverrideInterface;
use App\Core\Blog\Seo\BlogPublicRobotsPolicy;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BlogPublicRobotsPolicyTest extends TestCase
{
    public function testOverrideCanOnlyForceNoIndexNoFollow(): void
    {
        $variant = $this->variant(new BlogRobotsPreferences(true, false));
        self::assertSame(
            'index,nofollow',
            (new BlogPublicRobotsPolicy())->effectiveFor($variant)->directive()
        );

        $override = new class implements BlogPublicRobotsOverrideInterface {
            public function forcesNoIndexNoFollow(
                BlogPostVariant $variant
            ): bool {
                return true;
            }
        };
        self::assertSame(
            'noindex,nofollow',
            (new BlogPublicRobotsPolicy($override))
                ->effectiveFor($variant)->directive()
        );
    }

    private function variant(
        BlogRobotsPreferences $preferences
    ): BlogPostVariant {
        $now = new DateTimeImmutable('2031-01-01T00:00:00Z');

        return new BlogPostVariant(
            '61000000-0000-4000-8000-000000000001',
            '61000000-0000-4000-8000-000000000002',
            'es',
            new BlogDraft(
                'Título',
                'Contenido.',
                'titulo',
                'Título SEO',
                'Descripción.',
                'Extracto.',
                $preferences
            ),
            BlogPostVariant::PUBLISHED,
            $now,
            1,
            '61000000-0000-4000-8000-000000000003',
            '61000000-0000-4000-8000-000000000003',
            $now,
            $now
        );
    }
}
