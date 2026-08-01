<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use Closure;
use PDO;

/** Runtime boundary shared by the private Blog controller and its factory. */
interface BlogAdminHttpRuntimeInterface
{
    public function projectRoot(): string;

    /** @return list<string> */
    public function languages(): array;

    public function blogConfig(): BlogConfig;

    public function webAdminConfig(): WebAdminConfig;

    public function service(): BlogService;

    public function authentication(): WebAdminAuthenticationService;

    public function authorization(): WebAdminAuthorizationService;

    /**
     * Builds the gate that revalidates the browser actor inside the Blog
     * transaction opened by BlogService.
     *
     * @return Closure(PDO): string
     */
    public function mutationGate(
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        string $capability
    ): Closure;
}
