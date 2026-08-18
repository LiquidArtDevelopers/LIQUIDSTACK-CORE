<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Blog\Http\BlogTagAdminHtmlRenderer;
use App\Core\Blog\Http\BlogTagAdminRequestPolicy;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorTagOption;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class BlogTagAdminPresentationTest extends TestCase
{
    private const POST = '90000000-0000-4000-8000-000000000001';

    public function testAssignmentPolicyAcceptsOnlyTheClosedScalarContract(): void
    {
        $policy = new BlogTagAdminRequestPolicy();
        self::assertTrue($policy->acceptsAssignmentSave($this->request([
            'csrf' => str_repeat('A', 43),
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '7',
            'tag_workspace_version' => '0',
            'tags' => 'Fiscalidad, ahorro',
        ])));
        self::assertTrue($policy->acceptsAssignmentSave($this->request([
            'csrf' => str_repeat('A', 43),
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '7',
            'tag_workspace_version' => '0',
            'tags' => "Familia \u{1F469}\u{200D}\u{1F469}\u{200D}\u{1F467}",
        ])));
        $nativeMultibyteCsv = str_repeat("\u{20AC}", 4096);
        self::assertSame(12288, strlen($nativeMultibyteCsv));
        self::assertTrue($policy->acceptsAssignmentSave($this->request([
            'csrf' => str_repeat('A', 43),
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '7',
            'tag_workspace_version' => '0',
            'tags' => $nativeMultibyteCsv,
        ])));
        $transportBoundary = str_repeat("\u{1F4BC}", 4096);
        self::assertSame(
            BlogTagAdminRequestPolicy::MAX_TRANSPORT_CSV_BYTES,
            strlen($transportBoundary)
        );
        self::assertTrue($policy->acceptsAssignmentSave($this->request([
            'csrf' => str_repeat('A', 43),
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '7',
            'tag_workspace_version' => '0',
            'tags' => $transportBoundary,
        ])));

        foreach ([
            ['tag_workspace_version' => '-1'],
            ['lock_version' => '01'],
            ['locale' => 'ES'],
            ['tags' => "Ahorro\nFiscalidad"],
            ['tags' => "Ahorro\u{0085}Fiscalidad"],
            ['tags' => "Ahorro\u{200B}Fiscalidad"],
            ['tags' => "Ahorro\u{200C}Fiscalidad"],
            ['tags' => "Ahorro\u{00AD}Fiscalidad"],
            ['tags' => "Ahorro\u{200E}Fiscalidad"],
            ['tags' => "Ahorro\u{2028}Fiscalidad"],
            ['tags' => "Ahorro\u{2029}Fiscalidad"],
            ['tags' => "Ahorro\u{202A}Fiscalidad"],
            ['tags' => "Ahorro\u{202E}Fiscalidad"],
            ['tags' => "Ahorro\u{2066}Fiscalidad"],
            ['tags' => "Ahorro\u{2069}Fiscalidad"],
            ['tags' => "Ahorro\u{2060}Fiscalidad"],
            ['tags' => "Ahorro\u{FEFF}Fiscalidad"],
            ['tags' => str_repeat(
                'a',
                BlogTagAdminRequestPolicy::MAX_TRANSPORT_CSV_BYTES + 1
            )],
            ['tags' => ["Ahorro"]],
            ['unexpected' => 'field'],
        ] as $override) {
            $form = [
                'csrf' => str_repeat('A', 43),
                'post' => self::POST,
                'locale' => 'es',
                'lock_version' => '7',
                'tag_workspace_version' => '2',
                'tags' => 'Fiscalidad, ahorro',
            ];
            foreach ($override as $key => $value) {
                $form[$key] = $value;
            }
            self::assertFalse(
                $policy->acceptsAssignmentSave($this->request($form)),
                (string) json_encode($override)
            );
        }

        self::assertFalse($policy->acceptsAssignmentSave(Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/blog/tags/assign',
            'HTTPS' => 'on',
        ])));
        self::assertFalse($policy->acceptsAssignmentSave(Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/blog/tags/assign?retry=1',
            'HTTPS' => 'on',
        ], query: ['retry' => '1'], form: [
            'csrf' => str_repeat('A', 43),
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '7',
            'tag_workspace_version' => '2',
            'tags' => 'Ahorro',
        ], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])));
    }

    public function testEditorTagOptionMirrorsTheSafeUnicodePolicy(): void
    {
        $safe = new BlogEditorTagOption(
            "Familia \u{1F469}\u{200D}\u{1F469}\u{200D}\u{1F467}",
            'familia'
        );
        self::assertSame('familia', $safe->slug());

        self::assertSame(
            'Café',
            (new BlogEditorTagOption('Café', 'cafe'))->name()
        );

        foreach ([
            "Ahorro\u{2028}Fiscalidad",
            "Ahorro\u{2029}Fiscalidad",
            "Ahorro\u{200B}Fiscalidad",
            "Ahorro\u{200C}Fiscalidad",
            "Ahorro\u{00AD}Fiscalidad",
            "Ahorro\u{200E}Fiscalidad",
            "Ahorro\u{202A}Fiscalidad",
            "Ahorro\u{202E}Fiscalidad",
            "Ahorro\u{2066}Fiscalidad",
            "Ahorro\u{2069}Fiscalidad",
            "Ahorro\u{2060}Fiscalidad",
            "Ahorro\u{FEFF}Fiscalidad",
            "Cafe\u{0301}",
        ] as $unsafe) {
            try {
                new BlogEditorTagOption($unsafe, 'unsafe');
                self::fail('Unsafe Unicode tag option was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRecoveryRendererPreservesCsvWithoutIdentifiers(): void
    {
        $renderer = new BlogTagAdminHtmlRenderer();
        $html = $renderer->assignmentFailure(
            '/admin/blog/tags',
            'csrf-safe',
            self::POST,
            'es',
            7,
            2,
            'Ahorro, <Fiscalidad>',
            'No se pudo guardar <ahora>.',
            true
        );

        foreach ([
            'action="/admin/blog/tags/assign"',
            'name="post" value="' . self::POST . '"',
            'name="locale" value="es"',
            'name="lock_version" value="7"',
            'name="tag_workspace_version" value="2"',
            'name="tags" value="Ahorro, &lt;Fiscalidad&gt;"',
            'id="blog-tag-recovery-csv" type="text" dir="auto"',
            'href="/admin/blog/editor?post=' . self::POST
                . '&amp;locale=es#blog-editor-tags-title"',
            'No se pudo guardar &lt;ahora&gt;.',
        ] as $contract) {
            self::assertStringContainsString($contract, $html);
        }
        self::assertStringNotContainsString('tag_public_id', $html);
        self::assertSame(1, substr_count($html, 'name="tags"'));

        $conflict = $renderer->assignmentFailure(
            '/admin/blog/tags',
            'csrf-safe',
            self::POST,
            'es',
            7,
            2,
            'Ahorro',
            'Conflicto',
            false
        );
        self::assertStringNotContainsString(
            'action="/admin/blog/tags/assign"',
            $conflict
        );
        self::assertStringContainsString(
            'readonly aria-readonly="true" value="Ahorro"',
            $conflict
        );
    }

    /** @param array<string, mixed> $form */
    private function request(array $form): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/blog/tags/assign',
            'HTTPS' => 'on',
        ], form: $form, headers: [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
        ]);
    }
}
