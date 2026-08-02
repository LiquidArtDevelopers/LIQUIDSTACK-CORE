<?php

declare(strict_types=1);

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use PHPUnit\Framework\TestCase;

final class BlogStructuredDraftTest extends TestCase
{
    public function testBodyAndHashesAreDerivedFromCanonicalDocument(): void
    {
        $document = $this->document([
            $this->paragraph(1, 'Wake up, Neo.'),
            $this->heading(2, 2, 'The Matrix'),
        ]);
        $draft = new BlogStructuredDraft(
            'What is the Matrix?',
            $document,
            'what-is-the-matrix',
            'What is the Matrix? | Zion',
            'A safe description of the Matrix.',
            'A short introduction to the Matrix.'
        );

        self::assertSame(
            "Wake up, Neo.\n\nThe Matrix",
            $draft->compatibilityDraft()->bodyText()
        );
        self::assertSame(
            (new BlogDocumentCodec())->encode($document),
            $draft->canonicalJson()
        );
        self::assertSame(
            hash('sha256', $draft->canonicalJson()),
            $draft->documentSha256()
        );
        self::assertSame(
            hash('sha256', "Wake up, Neo.\n\nThe Matrix"),
            $draft->bodyTextSha256()
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $draft->snapshotSha256()
        );
    }

    public function testSnapshotChangesForEveryEditorialField(): void
    {
        $document = $this->document([$this->paragraph(1, 'Body')]);
        $base = new BlogStructuredDraft(
            'H1',
            $document,
            'slug',
            'SEO title',
            'Description',
            'Excerpt'
        );
        $variants = [
            new BlogStructuredDraft('Another H1', $document, 'slug', 'SEO title', 'Description', 'Excerpt'),
            new BlogStructuredDraft('H1', $document, 'another-slug', 'SEO title', 'Description', 'Excerpt'),
            new BlogStructuredDraft('H1', $document, 'slug', 'Another title', 'Description', 'Excerpt'),
            new BlogStructuredDraft('H1', $document, 'slug', 'SEO title', 'Another description', 'Excerpt'),
            new BlogStructuredDraft('H1', $document, 'slug', 'SEO title', 'Description', 'Another excerpt'),
            new BlogStructuredDraft('H1', $this->document([$this->paragraph(1, 'Another body')]), 'slug', 'SEO title', 'Description', 'Excerpt'),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame(
                $base->snapshotSha256(),
                $variant->snapshotSha256()
            );
        }
    }

    public function testMediaReferencesAreOrderedDeduplicatedForLookupAndTyped(): void
    {
        $asset = $this->id(900);
        $document = $this->document([
            $this->image(1, $asset, 'cover'),
            $this->image(2, $asset, 'wide'),
        ], BlogDocumentTemplateRegistry::ARTICLE_COVER);
        $draft = new BlogStructuredDraft('H1', $document);

        self::assertSame([$asset], $draft->mediaAssetPublicIds());
        self::assertSame(
            ['cover', 'image'],
            array_map(
                static fn ($reference): string => $reference->role(),
                $draft->mediaReferences()
            )
        );
        self::assertSame($this->id(1), $draft->mediaReferences()[0]->blockPublicId());
        self::assertSame($asset, $draft->mediaReferences()[1]->mediaAssetPublicId());
    }

    public function testDebugProjectionNeverContainsEditorialCopy(): void
    {
        $secret = 'private Matrix editorial copy';
        $draft = new BlogStructuredDraft(
            'Secret H1',
            $this->document([$this->paragraph(1, $secret)])
        );

        ob_start();
        var_dump($draft);
        $debug = (string) ob_get_clean();
        self::assertStringNotContainsString($secret, $debug);
        self::assertStringNotContainsString('Secret H1', $debug);
        self::assertStringContainsString('[redacted]', $debug);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function document(
        array $blocks,
        string $template = BlogDocumentTemplateRegistry::ARTICLE_BASIC
    ): BlogDocument {
        return BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => $template,
            'blocks' => $blocks,
        ]);
    }

    private function paragraph(int $id, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ];
    }

    private function heading(int $id, int $level, string $text): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'heading',
            'level' => $level,
            'content' => [[
                'type' => 'text',
                'text' => $text,
                'marks' => [],
            ]],
        ];
    }

    private function image(int $id, string $asset, string $display): array
    {
        return [
            'id' => $this->id($id),
            'type' => 'image',
            'media_asset_public_id' => $asset,
            'alt' => 'Matrix still',
            'title' => null,
            'caption' => null,
            'decorative' => false,
            'display' => $display,
        ];
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
