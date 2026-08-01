<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;
use PHPUnit\Framework\TestCase;

final class WebAdminHttpRequestPolicyTest extends TestCase
{
    public function testCredentialActionNavigationAllowsOnlyAnOptionalSingleToken(): void
    {
        $policy = new WebAdminHttpRequestPolicy();

        self::assertTrue($policy->acceptsCredentialActionNavigation(
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/activate?token=opaque',
            ], ['token' => 'opaque'])
        ));
        self::assertTrue($policy->acceptsCredentialActionNavigation(
            Request::fromInput([
                'REQUEST_METHOD' => 'HEAD',
                'REQUEST_URI' => '/admin/activate',
            ])
        ));

        foreach ([
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/activate?token=x&next=evil',
            ], ['token' => 'x', 'next' => 'evil']),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/activate?token=x',
            ], ['token' => 'x']),
            Request::fromInput([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/admin/activate',
            ], [], ['csrf' => 'unexpected']),
        ] as $request) {
            self::assertFalse(
                $policy->acceptsCredentialActionNavigation($request)
            );
        }
    }

    public function testFormPostsRejectEveryQueryParameter(): void
    {
        $request = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/password/reset?token=leaked',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], ['token' => 'leaked'], [
            'csrf' => 'csrf',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        self::assertFalse((new WebAdminHttpRequestPolicy())->acceptsFormPost(
            $request,
            ['csrf', 'password', 'password_confirmation']
        ));
    }

    public function testUserListNavigationAcceptsEmptyQueryOrOnlyAfter(): void
    {
        $policy = new WebAdminHttpRequestPolicy();

        foreach (['GET', 'HEAD'] as $method) {
            self::assertTrue($policy->acceptsUserListNavigation(
                Request::fromInput([
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => '/admin/users',
                ])
            ));
            self::assertTrue($policy->acceptsUserListNavigation(
                Request::fromInput([
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => '/admin/users?after=cursor',
                ], ['after' => 'cursor'])
            ));
        }
    }

    public function testUserListNavigationRejectsWrongChannelsAndQueryShape(): void
    {
        $policy = new WebAdminHttpRequestPolicy();
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/users',
        ];

        foreach ([
            Request::fromInput($server, ['user' => 'opaque']),
            Request::fromInput($server, [
                'after' => 'cursor',
                'next' => 'unexpected',
            ]),
            Request::fromInput($server, ['after' => ['nested']]),
            Request::fromInput($server, ['after' => 'cursor'], ['x' => 'y']),
            Request::fromInput(
                $server,
                ['after' => 'cursor'],
                body: 'unexpected'
            ),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users',
            ], ['after' => 'cursor']),
        ] as $request) {
            self::assertFalse($policy->acceptsUserListNavigation($request));
        }
    }

    public function testUserDetailNavigationAcceptsOnlyRequiredUser(): void
    {
        $policy = new WebAdminHttpRequestPolicy();

        foreach (['GET', 'HEAD'] as $method) {
            self::assertTrue($policy->acceptsUserDetailNavigation(
                Request::fromInput([
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => '/admin/users/edit?user=opaque',
                ], ['user' => 'opaque'])
            ));
        }
    }

    public function testUserDetailNavigationRejectsWrongChannelsAndQueryShape(): void
    {
        $policy = new WebAdminHttpRequestPolicy();
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/users/edit',
        ];

        foreach ([
            Request::fromInput($server),
            Request::fromInput($server, ['after' => 'cursor']),
            Request::fromInput($server, [
                'user' => 'opaque',
                'after' => 'cursor',
            ]),
            Request::fromInput($server, ['user' => ['nested']]),
            Request::fromInput($server, ['user' => 'opaque'], ['x' => 'y']),
            Request::fromInput(
                $server,
                ['user' => 'opaque'],
                body: 'unexpected'
            ),
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/edit',
            ], ['user' => 'opaque']),
        ] as $request) {
            self::assertFalse($policy->acceptsUserDetailNavigation($request));
        }
    }

    public function testCapabilitiesFormPostAcceptsAbsentEmptyAndCanonicalLists(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/users/capabilities',
        ];
        $headers = [
            'Content-Type' =>
                'application/x-www-form-urlencoded; charset=UTF-8',
        ];
        $scalars = ['csrf' => 'token', 'user' => 'opaque'];

        foreach ([
            $scalars,
            $scalars + ['capabilities' => []],
            $scalars + ['capabilities' => [
                'blog.posts.manage',
                'webadmin.users_view-v2',
            ]],
        ] as $form) {
            self::assertTrue($this->policy->acceptsCapabilitiesFormPost(
                Request::fromInput(
                    $server,
                    form: $form,
                    headers: $headers,
                    body: 'csrf=token&user=opaque'
                ),
                ['csrf', 'user']
            ));
        }
    }

    public function testCapabilitiesFormPostAcceptsAtMostSixtyFourUniqueCodes(): void
    {
        $capabilities = [];
        for ($index = 0; $index < 64; ++$index) {
            $capabilities[] = sprintf('blog.posts.capability_%02d', $index);
        }
        $request = Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/users/capabilities',
        ], form: [
            'csrf' => 'token',
            'user' => 'opaque',
            'capabilities' => $capabilities,
        ], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        self::assertTrue($this->policy->acceptsCapabilitiesFormPost(
            $request,
            ['csrf', 'user']
        ));

        $capabilities[] = 'blog.posts.one_too_many';
        self::assertFalse($this->policy->acceptsCapabilitiesFormPost(
            Request::fromInput([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/users/capabilities',
            ], form: [
                'csrf' => 'token',
                'user' => 'opaque',
                'capabilities' => $capabilities,
            ], headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]),
            ['csrf', 'user']
        ));
    }

    /** @dataProvider invalidCapabilitiesFormProvider */
    public function testCapabilitiesFormPostRejectsInvalidShapes(
        Request $request,
        array $expectedKeys
    ): void {
        self::assertFalse($this->policy->acceptsCapabilitiesFormPost(
            $request,
            $expectedKeys
        ));
    }

    /**
     * @return iterable<string, array{0: Request, 1: array<mixed>}>
     */
    public static function invalidCapabilitiesFormProvider(): iterable
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/users/capabilities',
        ];
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $valid = ['csrf' => 'token', 'user' => 'opaque'];

        $cases = [
            'query parameter' => [
                Request::fromInput(
                    $server,
                    ['next' => 'unexpected'],
                    $valid,
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'wrong method' => [
                Request::fromInput(
                    ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/admin'],
                    form: $valid,
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'wrong media type' => [
                Request::fromInput(
                    $server,
                    form: $valid,
                    headers: ['Content-Type' => 'application/json']
                ),
                ['csrf', 'user'],
            ],
            'invalid declared body size' => [
                Request::fromInput(
                    $server + [
                        'CONTENT_LENGTH' =>
                            (string) (Request::MAX_BODY_BYTES + 1),
                    ],
                    form: $valid,
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'missing scalar' => [
                Request::fromInput(
                    $server,
                    form: ['csrf' => 'token'],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'extra scalar' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['role' => 'editor'],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'nested scalar' => [
                Request::fromInput(
                    $server,
                    form: ['csrf' => 'token', 'user' => ['nested']],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'scalar capabilities' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => 'blog.posts.manage'],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'associative capabilities' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => [
                        'selected' => 'blog.posts.manage',
                    ]],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'nested capabilities' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => [[
                        'blog.posts.manage',
                    ]]],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'duplicate capabilities' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => [
                        'blog.posts.manage',
                        'blog.posts.manage',
                    ]],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'non canonical uppercase' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => [
                        'Blog.posts.manage',
                    ]],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'non canonical short code' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => ['ab']],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'overlong capability' => [
                Request::fromInput(
                    $server,
                    form: $valid + ['capabilities' => [
                        'a' . str_repeat('b', 128),
                    ]],
                    headers: $headers
                ),
                ['csrf', 'user'],
            ],
            'duplicate expected scalar key' => [
                Request::fromInput($server, form: $valid, headers: $headers),
                ['csrf', 'csrf'],
            ],
            'reserved expected scalar key' => [
                Request::fromInput($server, form: $valid, headers: $headers),
                ['csrf', 'capabilities'],
            ],
            'non-list expected scalar keys' => [
                Request::fromInput($server, form: $valid, headers: $headers),
                ['csrf' => 'csrf', 'user' => 'user'],
            ],
        ];

        foreach ($cases as $name => $case) {
            yield $name => $case;
        }
    }

    private WebAdminHttpRequestPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new WebAdminHttpRequestPolicy();
    }

    public function testSafeNavigationAcceptsGetAndHeadWithoutInput(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $request = Request::fromInput([
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/admin/login',
            ]);

            self::assertTrue($this->policy->acceptsSafeNavigation($request));
        }
    }

    public function testSafeNavigationRejectsEveryInputChannel(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/login',
        ];

        self::assertFalse($this->policy->acceptsSafeNavigation(
            Request::fromInput($server, ['next' => '/admin'])
        ));
        self::assertFalse($this->policy->acceptsSafeNavigation(
            Request::fromInput($server, form: ['unexpected' => 'value'])
        ));
        self::assertFalse($this->policy->acceptsSafeNavigation(
            Request::fromInput($server, body: 'unexpected')
        ));
    }

    public function testFormPostRequiresExactScalarFieldsAndMediaType(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/login',
        ];
        $form = [
            'csrf' => 'token',
            'email' => 'admin@example.test',
            'password' => 'password',
        ];

        self::assertTrue($this->policy->acceptsFormPost(
            Request::fromInput(
                $server,
                form: $form,
                headers: [
                    'Content-Type' =>
                        'application/x-www-form-urlencoded; charset=UTF-8',
                ]
            ),
            ['csrf', 'email', 'password']
        ));
        self::assertFalse($this->policy->acceptsFormPost(
            Request::fromInput(
                $server,
                form: $form + ['role' => 'admin'],
                headers: [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ]
            ),
            ['csrf', 'email', 'password']
        ));
        self::assertFalse($this->policy->acceptsFormPost(
            Request::fromInput(
                $server,
                form: $form,
                headers: ['Content-Type' => 'application/json']
            ),
            ['csrf', 'email', 'password']
        ));
        self::assertFalse($this->policy->acceptsFormPost(
            Request::fromInput(
                $server,
                form: ['csrf' => ['nested']],
                headers: [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ]
            ),
            ['csrf']
        ));
    }
}
