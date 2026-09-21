<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\CommerceBasketService;
use App\Core\Commerce\CommerceInquiryAbuseGuard;
use App\Core\Commerce\CommerceInquiryService;
use App\Core\Commerce\Configuration\CommerceConfig;

final class CommerceInquiryHttpRuntime
{
    public function __construct(
        private readonly CommerceConfig $config,
        private readonly CommerceBasketService $baskets,
        private readonly CommerceInquiryService $inquiries,
        private readonly CommerceBasketCookie $cookie,
        private readonly CommerceInquiryEnvironment $inquiryEnvironment,
        private readonly string $origin,
        private readonly ?CommerceInquiryAbuseGuard $abuseGuard = null
    ) {
    }

    public function config(): CommerceConfig { return $this->config; }
    public function baskets(): CommerceBasketService { return $this->baskets; }
    public function inquiries(): CommerceInquiryService { return $this->inquiries; }
    public function cookie(): CommerceBasketCookie { return $this->cookie; }
    public function inquiryEnvironment(): CommerceInquiryEnvironment { return $this->inquiryEnvironment; }
    public function origin(): string { return $this->origin; }
    public function abuseGuard(): ?CommerceInquiryAbuseGuard { return $this->abuseGuard; }
}
