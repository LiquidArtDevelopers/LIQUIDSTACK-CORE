<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use Closure;
use PDO;

interface BlogCategoryAdminHttpRuntimeInterface
{
    /** @return list<string> */
    public function languages(): array;
    public function blogConfig(): BlogConfig;
    public function webAdminConfig(): WebAdminConfig;
    public function blogService(): BlogService;
    public function categoryService(): BlogCategoryService;
    public function authentication(): WebAdminAuthenticationService;
    public function authorization(): WebAdminAuthorizationService;

    /** @return Closure(PDO): string */
    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure;
}
