<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Routing\ModuleRouteCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testSingleValueHeaderApiRemainsCompatible(): void
    {
        $response = new Response(200, 'ok', [
            'Content-Type' => 'text/plain',
            'X-Frame-Options' => 'DENY',
        ]);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $response->body());
        self::assertSame([
            'Content-Type' => 'text/plain',
            'X-Frame-Options' => 'DENY',
        ], $response->headers());
    }

    public function testRepeatedHeadersPreserveOrderAndRemainUncombined(): void
    {
        $response = new Response(200, 'ok', [
            'Set-Cookie' => [
                'LS_WEBADMIN_SID=one; Path=/admin; Secure; HttpOnly',
                'PREFERENCE=two; Path=/admin; Secure',
            ],
            'Cache-Control' => 'no-store',
        ]);

        self::assertSame([
            'LS_WEBADMIN_SID=one; Path=/admin; Secure; HttpOnly',
            'PREFERENCE=two; Path=/admin; Secure',
        ], $response->headerValues('set-cookie'));
        self::assertSame([
            [
                'name' => 'Set-Cookie',
                'value' => 'LS_WEBADMIN_SID=one; Path=/admin; Secure; HttpOnly',
            ],
            [
                'name' => 'Set-Cookie',
                'value' => 'PREFERENCE=two; Path=/admin; Secure',
            ],
            ['name' => 'Cache-Control', 'value' => 'no-store'],
        ], $response->headerLines());
        self::assertSame(
            'PREFERENCE=two; Path=/admin; Secure',
            $response->headers()['Set-Cookie'],
            'The legacy associative view documents and returns the last value.'
        );
    }

    public function testAddingAHeaderReturnsANewResponse(): void
    {
        $original = new Response(204, '', [
            'Set-Cookie' => 'first=1; Path=/',
        ]);
        $changed = $original->withAddedHeader(
            'set-cookie',
            'second=2; Path=/'
        );

        self::assertSame(['first=1; Path=/'], $original->headerValues(
            'Set-Cookie'
        ));
        self::assertSame([
            'first=1; Path=/',
            'second=2; Path=/',
        ], $changed->headerValues('Set-Cookie'));
    }

    public function testWithoutBodyPreservesRepeatedHeaders(): void
    {
        $response = new Response(200, 'must-not-be-emitted-for-head', [
            'Set-Cookie' => ['one=1', 'two=2'],
            'Content-Length' => '29',
        ]);

        $withoutBody = $response->withoutBody();

        self::assertSame('', $withoutBody->body());
        self::assertSame(
            ['one=1', 'two=2'],
            $withoutBody->headerValues('set-cookie')
        );
        self::assertSame('29', $withoutBody->headers()['Content-Length']);
    }

    public function testHeadDispatchRemovesBodyButKeepsRepeatedHeaders(): void
    {
        $routes = new ModuleRouteCollection();
        $routes->claimPrefix(
            'fixture',
            '/admin',
            static fn (Request $request): Response => new Response(404),
            static fn (Request $request, array $allowed): Response =>
                new Response(405)
        );
        $routes->add(
            'fixture',
            'GET',
            '/admin',
            static fn (Request $request): Response => new Response(
                200,
                'dashboard',
                ['Set-Cookie' => ['one=1', 'two=2']]
            )
        );

        $response = $routes->dispatch(Request::fromServer([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin',
        ]));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('', $response->body());
        self::assertSame(
            ['one=1', 'two=2'],
            $response->headerValues('Set-Cookie')
        );
    }

    #[DataProvider('invalidHeaderProvider')]
    public function testInvalidHeaderDataIsRejected(
        string $name,
        mixed $value
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new Response(200, '', [$name => $value]);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidHeaderProvider(): iterable
    {
        yield 'invalid name' => ['Bad Header', 'value'];
        yield 'CRLF' => ['X-Test', "safe\r\nX-Injected: yes"];
        yield 'null byte' => ['X-Test', "safe\0unsafe"];
        yield 'non-list values' => ['Set-Cookie', [1 => 'cookie=1']];
        yield 'non-string value' => ['X-Test', [123]];
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response(99);
    }

    public function testEmitWritesTheBodyAndStatus(): void
    {
        $response = new Response(202, 'accepted');

        ob_start();
        $response->emit();
        $body = (string) ob_get_clean();

        self::assertSame('accepted', $body);
        self::assertSame(202, http_response_code());
        http_response_code(200);
    }
}
