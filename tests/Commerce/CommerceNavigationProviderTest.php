<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\CommerceCapabilities;
use App\Core\Modules\Commerce\CommerceInquiryWebAdminNavigationProvider;
use App\Core\Modules\Commerce\CommerceTaxonomyWebAdminNavigationProvider;
use App\Core\Modules\Commerce\CommerceWebAdminNavigationProvider;
use PHPUnit\Framework\TestCase;

final class CommerceNavigationProviderTest extends TestCase
{
    public function testCommerceNavigationUsesIndependentCapabilities(): void
    {
        $catalog = (new CommerceWebAdminNavigationProvider())
            ->webAdminNavigationItem();
        $taxonomies = (new CommerceTaxonomyWebAdminNavigationProvider())
            ->webAdminNavigationItem();
        $inquiries = (new CommerceInquiryWebAdminNavigationProvider())
            ->webAdminNavigationItem();

        self::assertSame('commerce', $catalog->module());
        self::assertSame('/commerce', $catalog->suffix());
        self::assertSame(
            CommerceCapabilities::PRODUCTS_VIEW,
            $catalog->requiredCapability()
        );
        self::assertSame('/commerce/taxonomies', $taxonomies->suffix());
        self::assertSame(
            CommerceCapabilities::TAXONOMIES_VIEW,
            $taxonomies->requiredCapability()
        );
        self::assertSame('/commerce/inquiries', $inquiries->suffix());
        self::assertSame(
            CommerceCapabilities::INQUIRIES_VIEW,
            $inquiries->requiredCapability()
        );
        self::assertSame(9, count(CommerceCapabilities::all()));
        self::assertSame(
            CommerceCapabilities::all(),
            array_values(array_unique(CommerceCapabilities::all()))
        );
    }
}
