<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\Blog\Audit\BlogMutationAuditEvent;
use PHPUnit\Framework\TestCase;

final class BlogDomainValidationTest extends TestCase
{
    /** @dataProvider invalidUuidProvider */
    public function testPublicUuidContractIsStrict(string $value): void
    {
        $this->expectException(BlogException::class);
        BlogInput::publicId($value);
    }

    public static function invalidUuidProvider(): iterable
    {
        yield 'uppercase' => ['AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'];
        yield 'missing hyphens' => ['aaaaaaaaaaaa4aaa8aaaaaaaaaaaaaaaaaaa'];
        yield 'invalid version' => ['aaaaaaaa-aaaa-0aaa-8aaa-aaaaaaaaaaaa'];
        yield 'invalid variant' => ['aaaaaaaa-aaaa-4aaa-7aaa-aaaaaaaaaaaa'];
        yield 'trailing data' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaax'];
        yield 'nil uuid' => ['00000000-0000-0000-0000-000000000000'];
    }

    public function testPublicUuidAcceptsCanonicalVersionsAndGeneratedRequiresV4(): void
    {
        self::assertSame(
            '018f47a8-7e75-7cc4-9a67-85d4b38e0021',
            BlogInput::publicId('018f47a8-7e75-7cc4-9a67-85d4b38e0021')
        );
        self::assertSame(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            BlogInput::generatedPublicId(
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
            )
        );

        $this->expectException(BlogException::class);
        BlogInput::generatedPublicId(
            '018f47a8-7e75-7cc4-9a67-85d4b38e0021'
        );
    }

    /** @dataProvider invalidLocaleProvider */
    public function testLocaleMustBeCanonicalAndBounded(string $locale): void
    {
        $this->expectException(BlogException::class);
        BlogInput::locale($locale);
    }

    public static function invalidLocaleProvider(): iterable
    {
        yield 'uppercase' => ['es-ES'];
        yield 'underscore' => ['es_es'];
        yield 'relative-looking' => ['../es'];
        yield 'double separator' => ['es--es'];
        yield 'too short' => ['e'];
        yield 'too long' => ['abc-12345678-xyzz'];
        yield 'leading whitespace' => [' es'];
    }

    public function testLocaleAcceptsCanonicalLanguageTags(): void
    {
        self::assertSame('es', BlogInput::locale('es'));
        self::assertSame('es-es', BlogInput::locale('es-es'));
        self::assertSame('zh-hans', BlogInput::locale('zh-hans'));
    }

    /** @dataProvider invalidSlugProvider */
    public function testSlugMustBeCanonicalAscii(string $slug): void
    {
        $this->expectException(BlogException::class);
        BlogInput::slug($slug);
    }

    public static function invalidSlugProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Matrix'];
        yield 'accent' => ['matríz'];
        yield 'slash' => ['matrix/reloaded'];
        yield 'double hyphen' => ['matrix--reloaded'];
        yield 'leading hyphen' => ['-matrix'];
        yield 'trailing hyphen' => ['matrix-'];
        yield 'query' => ['matrix?x=1'];
        yield 'too long' => [str_repeat('a', BlogDraft::MAX_SLUG_BYTES + 1)];
    }

    public function testDraftNormalizesLineEndingsAndKeepsPlainText(): void
    {
        $draft = new BlogDraft(
            h1: 'Matrix & philosophy',
            bodyText: "First\r\n\r\nSecond\rThird",
            slug: 'matrix-philosophy',
            seoTitle: 'Matrix: a philosophy',
            metaDescription: 'A plain-text description.',
            excerpt: "Line one\r\nLine two"
        );

        self::assertSame(
            "First\n\nSecond\nThird",
            $draft->bodyText()
        );
        self::assertSame("Line one\nLine two", $draft->excerpt());
        self::assertTrue($draft->isPublishable());
    }

    /** @dataProvider invalidDraftProvider */
    public function testDraftRejectsInvalidUtf8HtmlControlsAndByteOverflow(
        string $h1,
        string $body,
        ?string $seoTitle,
        ?string $metaDescription,
        ?string $excerpt
    ): void {
        try {
            new BlogDraft(
                h1: $h1,
                bodyText: $body,
                seoTitle: $seoTitle,
                metaDescription: $metaDescription,
                excerpt: $excerpt
            );
            self::fail('Invalid Blog draft must be rejected.');
        } catch (BlogException $exception) {
            self::assertSame(
                BlogException::INVALID_INPUT,
                $exception->issueCode()
            );
            self::assertSame('Invalid Blog input.', $exception->getMessage());
        }
    }

    public static function invalidDraftProvider(): iterable
    {
        yield 'blank h1' => ['   ', 'Body', null, null, null];
        yield 'invalid utf8' => ["Bad \xC3\x28", 'Body', null, null, null];
        yield 'h1 html' => ['<strong>H1</strong>', 'Body', null, null, null];
        yield 'body html' => ['H1', '<script>alert(1)</script>', null, null, null];
        yield 'body nul' => ['H1', "Body\0hidden", null, null, null];
        yield 'body control' => ['H1', "Body\x07", null, null, null];
        yield 'title html' => ['H1', 'Body', '<b>SEO</b>', null, null];
        yield 'description newline' => ['H1', 'Body', null, "Meta\nline", null];
        yield 'excerpt html' => ['H1', 'Body', null, null, '<em>Excerpt</em>'];
        yield 'h1 byte overflow' => [
            str_repeat('é', intdiv(BlogDraft::MAX_H1_BYTES, 2) + 1),
            'Body',
            null,
            null,
            null,
        ];
        yield 'body byte overflow' => [
            'H1',
            str_repeat('a', BlogDraft::MAX_BODY_BYTES + 1),
            null,
            null,
            null,
        ];
        yield 'excerpt byte overflow' => [
            'H1',
            'Body',
            null,
            null,
            str_repeat('a', BlogDraft::MAX_EXCERPT_BYTES + 1),
        ];
    }

    public function testDraftCanRemainIncompleteUntilExplicitPublication(): void
    {
        $draft = new BlogDraft('Draft title', '');

        self::assertFalse($draft->isPublishable());
        self::assertNull($draft->slug());
        self::assertNull($draft->seoTitle());
        self::assertNull($draft->metaDescription());
        self::assertNull($draft->excerpt());
    }

    public function testInputErrorsNeverIncludeSubmittedContent(): void
    {
        $secret = 'private-client-content';

        try {
            new BlogDraft('<b>' . $secret . '</b>', 'Body');
            self::fail('HTML must be rejected.');
        } catch (BlogException $exception) {
            self::assertStringNotContainsString(
                $secret,
                $exception->getMessage()
            );
            self::assertNull($exception->getPrevious());
        }
    }

    public function testMutationAuditEventIsMinimalAndNormalizesUtc(): void
    {
        $event = new BlogMutationAuditEvent(
            BlogMutationAuditEvent::PUBLISH,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            new DateTimeImmutable('2030-01-01 10:00:00.123456 +02:00')
        );

        self::assertSame([
            'operation' => 'publish',
            'actor_public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'post_public_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'occurred_at' => '2030-01-01 08:00:00.123456',
        ], $event->toArray());
        self::assertSame('UTC', $event->occurredAt()->getTimezone()->getName());
    }

    public function testMutationAuditEventRejectsUnknownOperation(): void
    {
        $this->expectException(BlogException::class);
        new BlogMutationAuditEvent(
            'delete',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            new DateTimeImmutable('2030-01-01 08:00:00 UTC')
        );
    }
}
