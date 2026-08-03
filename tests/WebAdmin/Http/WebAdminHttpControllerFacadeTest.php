<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Http\WebAdminHtmlRenderer;
use App\Core\WebAdmin\Http\WebAdminHttpController;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;
use App\Core\WebAdmin\Http\WebAdminHttpRuntime;
use PHPUnit\Framework\TestCase;

final class WebAdminHttpControllerFacadeTest extends TestCase
{
    /** @var list<string> */
    private const ROUTE_METHODS = [
        'activate',
        'actionUnavailable',
        'activationCompleted',
        'completeActivation',
        'completePasswordReset',
        'editEditor',
        'forgotPasswordForm',
        'forgotPasswordSent',
        'forgotPasswordUnavailable',
        'inviteEditor',
        'inviteEditorForm',
        'login',
        'loginForm',
        'logout',
        'passwordResetCompleted',
        'replaceEditorCapabilities',
        'requestPasswordReset',
        'resendEditorInvitation',
        'resetPassword',
        'resumeEditor',
        'root',
        'suspendEditor',
        'users',
        'usersUpdated',
    ];

    public function testFacadePreservesItsRouteFacingPublicContract(): void
    {
        $reflection = new ReflectionClass(WebAdminHttpController::class);

        self::assertTrue($reflection->isFinal());

        $publicMethods = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool =>
                $method->getName() !== '__construct'
        ));
        $names = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $publicMethods
        );
        sort($names);

        $expected = self::ROUTE_METHODS;
        sort($expected);
        self::assertSame($expected, $names);

        foreach ($publicMethods as $method) {
            self::assertSame(
                WebAdminHttpController::class,
                $method->getDeclaringClass()->getName()
            );
            self::assertCount(1, $method->getParameters());
            self::assertSame(
                Request::class,
                $method->getParameters()[0]->getType()?->getName()
            );
            self::assertSame(
                Response::class,
                $method->getReturnType()?->getName()
            );
        }
    }

    public function testFacadePreservesItsConstructorInjectionContract(): void
    {
        $constructor = (new ReflectionClass(WebAdminHttpController::class))
            ->getConstructor();

        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(3, $parameters);
        self::assertSame(
            ['runtime', 'requestPolicy', 'renderer'],
            array_map(
                static fn (ReflectionParameter $parameter): string =>
                    $parameter->getName(),
                $parameters
            )
        );
        self::assertSame(
            WebAdminHttpRuntime::class,
            $parameters[0]->getType()?->getName()
        );
        self::assertSame(
            WebAdminHttpRequestPolicy::class,
            $parameters[1]->getType()?->getName()
        );
        self::assertTrue($parameters[1]->allowsNull());
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertNull($parameters[1]->getDefaultValue());
        self::assertSame(
            WebAdminHtmlRenderer::class,
            $parameters[2]->getType()?->getName()
        );
        self::assertTrue($parameters[2]->allowsNull());
        self::assertTrue($parameters[2]->isDefaultValueAvailable());
        self::assertNull($parameters[2]->getDefaultValue());
    }
}
