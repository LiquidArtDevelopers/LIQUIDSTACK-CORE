<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogUnifiedTextEditorAssetContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $result;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root
                . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-rich-text-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $this->result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function testNewContentUsesOnlyTheUnifiedTextModule(): void
    {
        self::assertContains('paragraph', $this->result['insertableTypes']);
        foreach (['heading', 'list', 'quote', 'callout'] as $legacyType) {
            self::assertNotContains(
                $legacyType,
                $this->result['insertableTypes']
            );
        }
    }

    public function testVisualAndSourceFlowRoundTripKeepsMarksAndLinks(): void
    {
        self::assertSame(
            '<p><strong>Wake up</strong>, '
                . '<a href="/neo" title="Neo" target="_blank">'
                . '<em>Neo</em></a></p>' . "\n"
                . '<ol>' . "\n"
                . '    <li><span data-ls-text-color="color02">'
                . 'Follow the white rabbit</span></li>' . "\n"
                . '</ol>',
            $this->result['serializedFlow']
        );
        self::assertSame(
            ['strong'],
            $this->result['bullets'][0]['items'][0]['content'][0]['marks']
        );
        self::assertSame(
            'link',
            $this->result['bullets'][0]['items'][0]['content'][2]['type']
        );
        self::assertSame(
            '/neo',
            $this->result['unwrapped'][0]['content'][2]['href']
        );
        self::assertSame(
            ['underline'],
            $this->result['unwrapped'][1]['content'][0]['marks']
        );
        self::assertTrue($this->result['combined']['stable']);
        self::assertSame(
            '<p><a href="/trinity" title="Trinity" target="_blank">'
                . '<span data-ls-size="xlarge" '
                . 'data-ls-background-color="color03" '
                . 'data-ls-text-rgba="rgba(12, 34, 56, 0.5)">'
                . '<strong><em><u>Trinity</u></em></strong></span></a></p>',
            $this->result['combined']['serialized']
        );
        self::assertSame(
            [
                'strong',
                'em',
                'underline',
                'size-xlarge',
                'background-color03',
                'text-rgba:rgba(12, 34, 56, 0.5)',
            ],
            $this->result['combined']['roundTrip'][0]['content'][0]['marks']
        );
        self::assertSame(
            '/trinity',
            $this->result['combined']['roundTrip'][0]['content'][0]['href']
        );
        self::assertSame(
            [
                ['type' => 'heading', 'level' => 3],
                ['type' => 'quote'],
                ['type' => 'callout'],
            ],
            array_map(
                static function (array $node): array {
                    $shape = ['type' => $node['type']];
                    if (isset($node['level'])) {
                        $shape['level'] = $node['level'];
                    }

                    return $shape;
                },
                $this->result['semanticFlow']
            )
        );
        self::assertSame(
            '<h3>Encabezado</h3>' . "\n"
                . '<blockquote>Cita</blockquote>' . "\n"
                . '<aside data-content-callout="true" role="note">'
                . 'Destacado</aside>',
            $this->result['semanticFlowHtml']
        );
    }

    public function testListStylesChangeAtomicallyWithoutLosingItems(): void
    {
        $styles = $this->result['listStyles'];
        $item = [
            'id' => '50000000-0000-4000-8000-000000000099',
            'content' => [[
                'type' => 'text',
                'text' => 'Styled',
                'marks' => ['strong'],
            ]],
        ];

        self::assertTrue($styles['sourcePreserved']);
        self::assertSame([[
            'type' => 'list',
            'ordered' => false,
            'items' => [$item],
            'marker' => 'circle',
        ]], $styles['markerRestyled']);
        self::assertSame([[
            'type' => 'list',
            'ordered' => true,
            'items' => [$item],
            'marker' => 'upper-alpha',
        ]], $styles['typeRestyled']);
        self::assertSame('paragraph', $styles['listRemoved'][0]['type']);
        self::assertSame('lower-alpha', $styles['paragraphsNumbered'][0]['marker']);
        self::assertCount(2, $styles['paragraphsNumbered'][0]['items']);
        self::assertNull($styles['invalidListStyle']);
    }

    public function testLegacyListProjectionIsExplicitAndNonDestructive(): void
    {
        $legacy = $this->result['legacy'];

        self::assertTrue($legacy['canStayList']);
        self::assertSame('noop', $legacy['noop']);
        self::assertTrue($legacy['noopHashPreserved']);
        self::assertSame('list', $legacy['listEdit']);
        self::assertSame('paragraph', $legacy['flowEdit']);
        self::assertTrue($legacy['applyNoopHashPreserved']);
        self::assertTrue($legacy['applyNoopClosed']);
        self::assertSame(
            'Sin cambios: la lista conserva su tipo e identificadores.',
            $legacy['applyNoopAnnouncement']
        );
        self::assertSame(
            'link',
            $legacy['projected'][0]['items'][0]['content'][2]['type']
        );
    }

    public function testUnifiedSectionHeadingRemainsValidAndProtected(): void
    {
        self::assertSame([
            'startsHeading' => true,
            'retaggedStartsHeading' => true,
            'valid' => true,
            'validDraft' => true,
            'protected' => true,
            'canMove' => false,
            'canDrag' => false,
            'canDuplicate' => false,
            'canDelete' => false,
            'visualParagraphBlocked' => true,
            'visualQuoteBlocked' => true,
            'visualHeadingAllowed' => true,
            'laterParagraphAllowed' => true,
            'validFlowApply' => true,
            'invalidFlowApply' => false,
        ], $this->result['protectedUnifiedHeading']);
    }

    public function testUnifiedTextContextMetadataAndCanonicalProjection(): void
    {
        $result = $this->result['unifiedTextP1'];

        self::assertSame('accent-line', $result['sameModuleHeadingPreset']);
        self::assertSame('accent-line', $result['externalHeadingPreset']);
        self::assertSame(255, $result['quoteAuthorBytes']);
        self::assertSame('accent', $result['quotePreset']);
        self::assertSame('square', $result['listMarker']);
        self::assertSame('warning', $result['calloutTone']);
        self::assertSame([
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
        ], $result['sparsePresentation']);
        self::assertSame([
            'width' => '80',
            'align' => 'center',
            'text_align' => 'start',
            'size' => 'm',
            'font_weight' => 'default',
            'text_color' => 'color02',
            'spacing_before' => 's',
            'spacing_after' => 'm',
        ], $result['listPresentation']);
        self::assertSame('cta', $result['legacyLinkType']);
        self::assertSame('start', $result['legacyLinkAlignment']);
        self::assertFalse($result['justifiedCtaValid']);
        self::assertFalse($result['presentationNormalizerChanged']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $result['documentSha256']
        );
    }

    public function testHeadingSourceIsStructuralAndContextLimited(): void
    {
        $heading = $this->result['heading'];

        self::assertSame(
            '<h3><strong>Structured heading</strong></h3>',
            $heading['html']
        );
        self::assertSame(['h3', 'h4'], $heading['rootSuggestions']);
        self::assertSame(
            ['a', 'strong', 'em', 'u', 'span'],
            $heading['inlineSuggestions']
        );
        self::assertSame(
            'rich-heading-level-not-allowed',
            $heading['forbiddenLevel']
        );
        self::assertSame(
            'rich-html-not-allowed',
            $heading['paragraphWrapper']
        );
        self::assertNull($heading['headingInsideText']);
        self::assertSame(
            'rich-html-not-allowed',
            $heading['boldAliasInSource']
        );
        self::assertSame(
            'rich-link-invalid',
            $heading['unsafeLinkInText']
        );
        self::assertSame([2, 3, 4, 5, 6], $heading['sectionAllowedLevels']);
        self::assertSame(
            [2, 3, 4, 5, 6],
            $heading['articleAllowedLevels']
        );
    }
}
