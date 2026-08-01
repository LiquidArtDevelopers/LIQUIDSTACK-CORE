<?php

declare(strict_types=1);

use App\Core\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testFromServerRemainsAStandaloneRoutingFactory(): void
    {
        $originalGet = $_GET;
        try {
            $_GET = ['global' => 'must-not-be-read'];

            $request = Request::fromServer([
                'REQUEST_METHOD' => ' get ',
                'REQUEST_URI' => '/admin/login?next=%2Fadmin',
                'HTTP_ACCEPT' => 'text/html',
                'REMOTE_ADDR' => '127.0.0.1',
            ]);

            self::assertSame('GET', $request->method());
            self::assertSame('/admin/login', $request->path());
            self::assertSame([], $request->queryParams());
            self::assertSame('text/html', $request->header('ACCEPT'));
            self::assertSame('127.0.0.1', $request->clientIp());
            self::assertTrue($request->isValid());
        } finally {
            $_GET = $originalGet;
        }
    }

    public function testFromInputExposesIsolatedRequestData(): void
    {
        $request = Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/auth/login',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_REQUEST_ID' => 'from-server',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
                'REMOTE_ADDR' => '192.0.2.10',
            ],
            ['return' => '/admin'],
            ['email' => 'user@example.test', 'roles' => ['editor']],
            ['LS_WEBADMIN_SID' => 'opaque-token'],
            ['X-Request-Id' => 'explicit-value'],
            'email=user%40example.test'
        );

        self::assertSame('/admin', $request->query('return'));
        self::assertSame('user@example.test', $request->form('email'));
        self::assertSame(['editor'], $request->form('roles'));
        self::assertSame(
            'opaque-token',
            $request->cookie('LS_WEBADMIN_SID')
        );
        self::assertSame(
            'application/x-www-form-urlencoded',
            $request->header('content-type')
        );
        self::assertSame('explicit-value', $request->header('X-Request-ID'));
        self::assertSame('email=user%40example.test', $request->body());
        self::assertSame(25, $request->bodySize());
        self::assertSame(
            '192.0.2.10',
            $request->clientIp(),
            'Forwarded headers must never replace REMOTE_ADDR implicitly.'
        );
        self::assertTrue($request->isValid());

        $query = $request->queryParams();
        $query['return'] = '/changed';
        self::assertSame('/admin', $request->query('return'));
    }

    public function testForwardedAddressIsNeverTrustedWithoutRemoteAddress(): void
    {
        $request = Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.12',
        ]);

        self::assertNull($request->clientIp());
        self::assertSame(
            '198.51.100.12',
            $request->header('x-forwarded-for')
        );
    }

    public function testSecureTransportUsesOnlyLocalServerAssertions(): void
    {
        foreach ([
            ['HTTPS' => 'on'],
            ['HTTPS' => '1'],
            ['REQUEST_SCHEME' => 'https'],
            ['SERVER_PORT' => '443'],
        ] as $secureServer) {
            $request = Request::fromServer($secureServer + [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin',
            ]);
            self::assertTrue($request->isSecureTransport());
        }

        $forwardedOnly = Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin',
            'SERVER_PORT' => '80',
            'HTTP_FORWARDED' => 'for=192.0.2.4;proto=https',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        self::assertFalse($forwardedOnly->isSecureTransport());
    }

    #[DataProvider('validPathProvider')]
    public function testValidPathsAreDecodedExactlyOnce(
        string $uri,
        string $expected
    ): void {
        $request = Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
        ]);

        self::assertTrue($request->hasValidPath());
        self::assertSame($expected, $request->path());
    }

    /** @return iterable<string, array{string, string}> */
    public static function validPathProvider(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'query is not path' => ['/admin?next=%2Fadmin', '/admin'];
        yield 'encoded space' => ['/admin/my%20account', '/admin/my account'];
        yield 'utf8' => ['/admin/caf%C3%A9', '/admin/café'];
        yield 'plus remains plus' => ['/admin/a+b', '/admin/a+b'];
    }

    #[DataProvider('invalidPathProvider')]
    public function testAmbiguousOrDangerousPathsFailClosed(string $uri): void
    {
        $request = Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
        ]);

        self::assertFalse($request->hasValidPath());
        self::assertFalse($request->isValid());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPathProvider(): iterable
    {
        yield 'encoded slash' => ['/admin%2Flogin'];
        yield 'encoded backslash' => ['/admin%5Clogin'];
        yield 'double encoded slash' => ['/admin%252Flogin'];
        yield 'malformed percent' => ['/admin/%GG'];
        yield 'encoded null' => ['/admin/%00'];
        yield 'raw dot segment' => ['/admin/../public'];
        yield 'encoded dot segment' => ['/admin/%2e%2e/public'];
        yield 'raw backslash' => ['/admin\\login'];
        yield 'double slash' => ['/admin//login'];
        yield 'network path' => ['//example.test/admin'];
        yield 'invalid utf8' => ["/admin/%FF"];
    }

    public function testInvalidMethodAndRemoteAddressAreExposedSafely(): void
    {
        $request = Request::fromServer([
            'REQUEST_METHOD' => "POST\r\nX-Evil: yes",
            'REQUEST_URI' => '/admin',
            'REMOTE_ADDR' => '127.0.0.1, 198.51.100.2',
        ]);

        self::assertFalse($request->hasValidMethod());
        self::assertNull($request->clientIp());
        self::assertFalse($request->isValid());
    }

    public function testBodyLimitAndDeclaredLengthFailClosed(): void
    {
        $atLimit = str_repeat('a', Request::MAX_BODY_BYTES);
        $valid = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/auth/login',
            'CONTENT_LENGTH' => (string) strlen($atLimit),
        ], body: $atLimit);

        self::assertTrue($valid->hasValidBody());
        self::assertSame(Request::MAX_BODY_BYTES, $valid->bodySize());

        $tooLarge = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/auth/login',
        ], body: $atLimit . 'x');
        self::assertFalse($tooLarge->hasValidBody());
        self::assertSame('', $tooLarge->body());

        $declaredTooLarge = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/auth/login',
            'CONTENT_LENGTH' => (string) (Request::MAX_BODY_BYTES + 1),
        ]);
        self::assertFalse($declaredTooLarge->hasValidBody());

        $malformedLength = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/auth/login',
            'CONTENT_LENGTH' => '12x',
        ]);
        self::assertFalse($malformedLength->hasValidBody());
    }

    public function testNestedInputAndCollectionLimitsFailClosed(): void
    {
        $tooMany = [];
        for ($index = 0; $index <= Request::MAX_INPUT_ITEMS; ++$index) {
            $tooMany['key-' . $index] = 'value';
        }

        $query = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin'],
            $tooMany
        );
        self::assertFalse($query->hasValidQuery());
        self::assertSame([], $query->queryParams());

        $tooDeep = ['one' => ['two' => ['three' => ['four' => ['five']]]]];
        $form = Request::fromInput(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin'],
            form: $tooDeep
        );
        self::assertFalse($form->hasValidForm());
        self::assertSame([], $form->formParams());

        $wrongType = Request::fromInput(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin'],
            form: ['attempts' => 3]
        );
        self::assertFalse($wrongType->hasValidForm());
    }

    public function testLargeArticleTextIsAllowedOnlyInBoundedFormInput(): void
    {
        $article = str_repeat('a', Request::MAX_INPUT_VALUE_BYTES + 1);
        $form = Request::fromInput(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/blog'],
            form: ['body_text' => $article]
        );
        self::assertTrue($form->hasValidForm());
        self::assertSame($article, $form->form('body_text'));

        $query = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/blog'],
            query: ['body_text' => $article]
        );
        self::assertFalse($query->hasValidQuery());
        self::assertSame([], $query->queryParams());

        $oversized = Request::fromInput(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/blog'],
            form: [
                'body_text' => str_repeat(
                    'a',
                    Request::MAX_FORM_VALUE_BYTES + 1
                ),
            ]
        );
        self::assertFalse($oversized->hasValidForm());
    }

    public function testHeadersAreCaseInsensitiveAndRejectInjection(): void
    {
        $valid = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin'],
            headers: ['X-Custom-Header' => ['one', 'two']]
        );
        self::assertTrue($valid->hasValidHeaders());
        self::assertSame('one, two', $valid->header('x-CUSTOM-header'));

        $invalid = Request::fromInput(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin'],
            headers: ['X-Custom' => "safe\r\nSet-Cookie: injected=1"]
        );
        self::assertFalse($invalid->hasValidHeaders());
        self::assertSame([], $invalid->headers());
    }

    public function testCookieHeaderIsParsedAndDuplicateNamesAreRejected(): void
    {
        $valid = Request::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin',
            'HTTP_COOKIE' => 'first=one%20two; LS_WEBADMIN_SID=abc_def-123',
        ]);
        self::assertTrue($valid->hasValidCookies());
        self::assertSame('one two', $valid->cookie('first'));
        self::assertSame('abc_def-123', $valid->cookie('LS_WEBADMIN_SID'));

        $duplicate = Request::fromInput(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin',
                'HTTP_COOKIE' => 'LS_WEBADMIN_SID=first; LS_WEBADMIN_SID=second',
            ],
            cookies: ['LS_WEBADMIN_SID' => 'second']
        );
        self::assertFalse($duplicate->hasValidCookies());
        self::assertSame([], $duplicate->cookies());
    }

    public function testFromGlobalsCopiesCurrentRequestState(): void
    {
        $original = [$_SERVER, $_GET, $_POST, $_COOKIE];
        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/auth/login?source=test',
                'CONTENT_LENGTH' => '0',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => '::1',
            ];
            $_GET = ['source' => 'test'];
            $_POST = ['email' => 'admin@example.test'];
            $_COOKIE = ['LS_WEBADMIN_SID' => 'session-token'];

            $request = Request::fromGlobals();

            self::assertSame('/admin/auth/login', $request->path());
            self::assertSame('test', $request->query('source'));
            self::assertSame('admin@example.test', $request->form('email'));
            self::assertSame(
                'session-token',
                $request->cookie('LS_WEBADMIN_SID')
            );
            self::assertSame('application/json', $request->header('accept'));
            self::assertSame('::1', $request->clientIp());
            self::assertTrue($request->isValid());
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE] = $original;
        }
    }
}
