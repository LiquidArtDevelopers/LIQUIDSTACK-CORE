<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\ModuleRouteCollection;
use PHPUnit\Framework\TestCase;

final class ModuleRouteChildClaimTest extends TestCase
{
    public function testChildClaimRequiresAndKeepsTheEffectiveParentOwner(): void
    {
        $routes = new ModuleRouteCollection();
        $routes->claimPrefix(
            'webadmin',
            '/gestion',
            $this->notFound('webadmin'),
            $this->methodNotAllowed()
        );
        $routes->claimChildPrefix(
            'blog',
            'webadmin',
            '/gestion',
            '/gestion/blog',
            $this->notFound('blog'),
            $this->methodNotAllowed()
        );
        $routes->add(
            'blog',
            'GET',
            '/gestion/blog',
            static fn (Request $request): Response => new Response(
                200,
                'blog'
            )
        );

        self::assertSame(
            'blog',
            $routes->dispatch($this->request('/gestion/blog'))?->body()
        );
        self::assertSame(
            'blog-not-found',
            $routes->dispatch($this->request('/gestion/blog/unknown'))?->body()
        );
        self::assertSame(
            'webadmin-not-found',
            $routes->dispatch($this->request('/gestion/unknown'))?->body()
        );
    }

    public function testChildClaimRejectsAMissingOrDifferentEffectiveParent(): void
    {
        $routes = new ModuleRouteCollection();
        $routes->claimPrefix(
            'webadmin',
            '/admin',
            $this->notFound('webadmin'),
            $this->methodNotAllowed()
        );
        $routes->claimPrefix(
            'media',
            '/admin/media',
            $this->notFound('media'),
            $this->methodNotAllowed()
        );

        try {
            $routes->claimChildPrefix(
                'blog',
                'webadmin',
                '/admin',
                '/admin/media/blog',
                $this->notFound('blog'),
                $this->methodNotAllowed()
            );
            self::fail('A child cannot bypass the effective nested owner.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'no pertenece al modulo requerido webadmin',
                $exception->getMessage()
            );
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'no pertenece al modulo requerido webadmin'
        );

        (new ModuleRouteCollection())->claimChildPrefix(
            'blog',
            'webadmin',
            '/admin',
            '/admin/blog',
            $this->notFound('blog'),
            $this->methodNotAllowed()
        );
    }

    public function testChildClaimMustBeAStrictDescendant(): void
    {
        $routes = new ModuleRouteCollection();
        $routes->claimPrefix(
            'webadmin',
            '/admin',
            $this->notFound('webadmin'),
            $this->methodNotAllowed()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('debe descender estrictamente');

        $routes->claimChildPrefix(
            'blog',
            'webadmin',
            '/admin',
            '/administrator/blog',
            $this->notFound('blog'),
            $this->methodNotAllowed()
        );
    }

    /** @return Closure(Request): Response */
    private function notFound(string $owner): Closure
    {
        return static fn (Request $request): Response => new Response(
            404,
            $owner . '-not-found'
        );
    }

    /** @return Closure(Request, list<string>): Response */
    private function methodNotAllowed(): Closure
    {
        return static fn (Request $request, array $allowed): Response =>
            new Response(405, '', ['Allow' => implode(', ', $allowed)]);
    }

    private function request(string $path): Request
    {
        return Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ]);
    }
}
