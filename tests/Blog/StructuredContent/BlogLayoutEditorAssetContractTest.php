<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogLayoutEditorAssetContractTest extends TestCase
{
    public function testArticleAndDivPresetTransitionsPreserveColumnsAndReadOrder(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-layout-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach (['article', 'div'] as $type) {
            $scenario = $result[$type];
            self::assertTrue($scenario['initialValid']);
            foreach ([
                'changedToFive',
                'changedToThree',
                'changedToTwo',
                'changedRatio',
                'changedToOne',
            ] as $transition) {
                self::assertTrue($scenario[$transition]);
            }

            foreach ([
                '2-40-60' => 2,
                '2-60-40' => 2,
                '3' => 3,
                '4' => 4,
                '5' => 5,
            ] as $preset => $columnCount) {
                $growth = $scenario['growthMatrix'][$preset];
                self::assertSame((string) $preset, $growth['preset']);
                self::assertTrue($growth['valid']);
                self::assertCount($columnCount, $growth['columnIds']);
                self::assertCount(
                    $columnCount,
                    array_unique($growth['columnIds'])
                );
                self::assertSame(
                    $scenario['originalColumnId'],
                    $growth['columnIds'][0]
                );
                self::assertSame(['A', 'B'], $growth['children'][0]);
                self::assertSame(
                    array_fill(0, $columnCount - 1, []),
                    array_slice($growth['children'], 1)
                );
            }

            self::assertSame('5', $scenario['growth']['preset']);
            self::assertTrue($scenario['growth']['valid']);
            self::assertSame(
                $scenario['originalColumnId'],
                $scenario['growth']['columnIds'][0]
            );
            self::assertCount(5, array_unique($scenario['growth']['columnIds']));
            self::assertSame(
                [['A', 'B'], [], [], [], []],
                $scenario['growth']['children']
            );
            self::assertTrue($scenario['seededFive']['valid']);
            self::assertSame(
                [['A', 'B'], [], ['C'], ['D'], ['E']],
                $scenario['seededFive']['children']
            );

            self::assertSame('3', $scenario['reducedToThree']['preset']);
            self::assertTrue($scenario['reducedToThree']['valid']);
            self::assertSame(
                [['A', 'B', 'C', 'D', 'E'], [], []],
                $scenario['reducedToThree']['children']
            );
            self::assertSame(
                array_slice($scenario['seededFive']['columnIds'], 0, 3),
                $scenario['reducedToThree']['columnIds']
            );
            self::assertSame(
                array_merge(...$scenario['seededFive']['childIds']),
                $scenario['reducedToThree']['childIds'][0]
            );
            self::assertSame(
                '2-40-60',
                $scenario['reducedToTwo']['preset']
            );
            self::assertTrue($scenario['reducedToTwo']['valid']);
            self::assertSame(
                [['A', 'B', 'C', 'D', 'E'], []],
                $scenario['reducedToTwo']['children']
            );
            self::assertSame(
                array_slice($scenario['seededFive']['columnIds'], 0, 2),
                $scenario['reducedToTwo']['columnIds']
            );
            self::assertSame(
                '2-60-40',
                $scenario['ratioChanged']['preset']
            );
            self::assertTrue($scenario['ratioChanged']['valid']);
            self::assertTrue($scenario['ratioStable']);

            self::assertSame('1', $scenario['reducedToOne']['preset']);
            self::assertTrue($scenario['reducedToOne']['valid']);
            self::assertSame(
                [['A', 'B', 'C', 'D', 'E']],
                $scenario['reducedToOne']['children']
            );
            self::assertSame(
                $scenario['originalColumnId'],
                $scenario['reducedToOne']['columnIds'][0]
            );
        }

        $expectedPresets = [
            '1',
            '2-50-50',
            '2-40-60',
            '2-60-40',
            '2-30-70',
            '2-70-30',
            '3',
            '4',
            '5',
        ];
        foreach (['visualArticle', 'visualDiv'] as $key) {
            $scenario = $result[$key];
            self::assertSame('native-radio-change', $scenario['interaction']);
            self::assertSame($expectedPresets, array_column(
                $scenario['models'],
                'value'
            ));
            self::assertSame(1, $scenario['selectedCount']);
            self::assertSame(
                ['2 columnas, 40/60'],
                array_column(array_values(array_filter(
                    $scenario['models'],
                    static fn (array $model): bool => $model['selected']
                )), 'label')
            );
            foreach ($scenario['models'] as $model) {
                self::assertCount(
                    count($model['proportions']),
                    $model['weights']
                );
                self::assertNotEmpty($model['weights']);
            }
            foreach (['toTwo', 'toFive', 'toOne'] as $transition) {
                self::assertTrue($scenario[$transition]);
            }
            self::assertSame(
                ['1', '2-40-60', '5', '1'],
                array_column($scenario['trace'], 'preset')
            );
            foreach ($scenario['trace'] as $state) {
                self::assertTrue($state['valid']);
            }
            self::assertSame(
                $scenario['twoColumnIds'],
                array_slice($scenario['fiveColumnIds'], 0, 2)
            );
            self::assertSame(
                $scenario['fiveColumnIds'][0],
                $scenario['finalState']['columnIds'][0]
            );
            self::assertSame(
                $scenario['fiveChildIds'],
                $scenario['finalState']['childIds'][0]
            );
            self::assertSame(
                [['A', 'B', 'C', 'D', 'E']],
                $scenario['finalState']['children']
            );
        }
    }

    public function testEditorAndPublicLayoutCssShareTheClosedResponsiveMatrix(): void
    {
        $root = dirname(__DIR__, 3);
        $javascript = $this->read(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $adminCss = $this->read(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
        $publicCss = $this->read(
            $root . '/modules/blog/published/assets/blog-public.css'
        );
        $resourceScss = $this->read(
            $root . '/modules/blog/resources/project/src/scss/resources/'
                . '_artBlogArticle01.scss'
        );

        self::assertStringContainsString(
            'columnElement.dataset.contentCount = String(column.children.length)',
            $javascript
        );
        foreach ([
            "data-content-count='0'",
            '--blog-builder-column-guide',
            'var(--blog-builder-guide) 42%',
            'border: 0.08rem dashed var(--blog-builder-column-guide)',
            '.blogEditor__builderColumnLabel',
            '.blogEditor__builderLayoutControl',
            '.blogEditor__layoutPickerSummary',
            '.blogEditor__layoutPickerPanel',
            '.blogEditor__layoutChoiceCheck',
            'min-block-size: 2.75rem',
            'min-block-size: 10rem',
        ] as $contract) {
            self::assertStringContainsString($contract, $adminCss);
        }

        self::assertStringNotContainsString(
            "data-content-count='1'] {\n    align-content: center;",
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogEditor__layoutPicker,.*?'
                . '\.blogEditor__layoutPickerDetails\s*\{[^}]*'
                . 'max-width:\s*15rem;/s',
            $adminCss
        );
        self::assertStringNotContainsString(
            '--blog-builder-column-separator',
            $adminCss
        );
        foreach ([
            'v2RenderLayoutControl',
            'v2ColumnPresetControl',
            'v2ApplyPresetControl',
            "return v2ColumnPresetControl(context, container, 'canvas')",
            "v2ColumnPresetControl(context, node, 'inspector')",
            "radio.type = 'radio'",
            "details.dataset.blogV2LayoutPicker = 'true'",
            "summary.dataset.blogV2LayoutSummary = 'true'",
            'v2ColumnLabel(container, columnIndex)',
            "columnElement.setAttribute('role', 'group')",
            'Todo el contenido se ha mantenido en orden en la columna 1',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        self::assertStringNotContainsString(
            ".blogEditor__builderColumns[data-preset]:not([data-preset='1'])",
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/\.webadmin \.blogEditor__builderColumns\s*\{[^}]*'
                . 'grid-template-columns:\s*minmax\(0, 1fr\);/s',
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/\.blogEditor__layoutChoice:has\(input:focus-visible\).*?'
                . 'outline:/s',
            $adminCss
        );
        self::assertStringContainsString(
            '.webadmin .blogEditor input.blogEditor__layoutChoiceInput:disabled '
                . '{ opacity: 0; }',
            $this->compact($adminCss)
        );
        self::assertMatchesRegularExpression(
            '/\.blogEditor__builderContainer--article\s*\{[^}]*'
                . 'container-type:\s*inline-size;/s',
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/\.blogEditor__builderContainer--div\s*\{[^}]*'
                . 'container-type:\s*inline-size;/s',
            $adminCss
        );
        foreach ([
            '@container (min-width: 24rem)',
            '@container (min-width: 28rem)',
            '@container (min-width: 36rem)',
            '@container (min-width: 37rem)',
            '@container (min-width: 48rem)',
            '@container (min-width: 60rem)',
        ] as $containerContract) {
            self::assertStringContainsString($containerContract, $adminCss);
        }

        $matrix = [
            '2-40-60' => 'minmax(0, 2fr) minmax(0, 3fr)',
            '2-60-40' => 'minmax(0, 3fr) minmax(0, 2fr)',
            '3' => 'repeat(3, minmax(0, 1fr))',
            '4' => 'repeat(4, minmax(0, 1fr))',
            '5' => 'repeat(5, minmax(0, 1fr))',
        ];
        $compactAdmin = $this->compact($adminCss);
        $compactPublic = $this->compact($publicCss);
        $compactResource = $this->compact($resourceScss);
        foreach ($matrix as $preset => $columns) {
            self::assertStringContainsString(
                ".webadmin .blogEditor__builderColumns[data-preset='"
                    . $preset . "'] { grid-template-columns: "
                    . $columns . '; }',
                $compactAdmin
            );
            self::assertStringContainsString(
                '.blogDocument__layout--' . $preset
                    . ' { grid-template-columns: ' . $columns . '; }',
                $compactPublic
            );
            self::assertStringContainsString(
                '&--' . $preset . ' { grid-template-columns: '
                    . $columns . '; }',
                $compactResource
            );
        }

        foreach ([$adminCss, $resourceScss] as $stylesheet) {
            self::assertMatchesRegularExpression(
                '/@media \(min-width: 48rem\).*?'
                    . "(?:data-preset='5'|&--5).*?"
                    . 'repeat\(5, minmax\(0, 1fr\)\)/s',
                $stylesheet
            );
            self::assertMatchesRegularExpression(
                '/@media \(min-width: 64rem\).*?'
                    . "(?:data-width='80'|&--width-80).*?width:\s*80%;"
                    . ".*?(?:data-width='60'|&--width-60).*?width:\s*60%;"
                    . ".*?(?:data-width='40'|&--width-40).*?width:\s*40%;/s",
                $stylesheet
            );
        }
    }

    public function testNewDirectChildrenUseResponsiveDefaultsAndEmptyCopy(): void
    {
        $node = new Process(['node', '--version']);
        $node->run();
        if (!$node->isSuccessful()) {
            self::markTestSkipped('Node.js no está disponible.');
        }

        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-layout-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $defaults = $result['defaults'];

        foreach ([
            'paragraphAdded',
            'articleAdded',
            'nestedParagraphAdded',
            'divisionAdded',
            'validDraft',
        ] as $flag) {
            self::assertTrue($defaults[$flag]);
        }
        self::assertSame('', $defaults['sectionHeading']['text']);
        self::assertSame('', $defaults['directParagraph']['text']);
        self::assertSame('', $defaults['nestedParagraph']['text']);
        foreach ([
            $defaults['sectionHeading']['presentation'],
            $defaults['directParagraph']['presentation'],
            $defaults['directArticle'],
        ] as $presentation) {
            self::assertSame('60', $presentation['width']);
            self::assertSame('center', $presentation['align']);
        }
        self::assertSame('full', $defaults['directDivision']['width']);
        self::assertSame('center', $defaults['directDivision']['align']);
        self::assertSame(
            'full',
            $defaults['nestedParagraph']['presentation']['width']
        );
        self::assertSame(
            'start',
            $defaults['nestedParagraph']['presentation']['align']
        );
    }

    public function testSizingPaddingAndHeadingModalStayMobileFirst(): void
    {
        $root = dirname(__DIR__, 3);
        $javascript = $this->read(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $adminCss = $this->read(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );
        $publicCss = $this->read(
            $root . '/modules/blog/published/assets/blog-public.css'
        );
        $resourceScss = $this->read(
            $root . '/modules/blog/resources/project/src/scss/resources/'
                . '_artBlogArticle01.scss'
        );

        self::assertStringNotContainsString('Nuevo párrafo', $javascript);
        self::assertStringNotContainsString('Nuevo título', $javascript);
        foreach ([
            'richBlockPlaceholder',
            'state.dialog.dataset.blockType = block.type',
            "richInlinePlainText(state.draft) === '' ? 'true' : 'false'",
            'blogEditor__builderTextPlaceholder',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        foreach ([
            "[data-block-type='heading'][data-expanded='false']",
            "[data-empty='true']::before",
            'content: attr(data-placeholder)',
            '--blog-builder-section-inset: clamp(',
            'padding-inline: var(--blog-builder-section-inset)',
            '--blog-builder-section-padding-block: 2rem',
            '--blog-builder-section-padding-block: 3rem',
            '--blog-builder-section-padding-block: 5rem',
            '--blog-builder-content-gap: 2.75rem',
            '--blog-builder-content-gap: 3rem',
            '--blog-builder-content-gap: 3.5rem',
            'padding: 2rem 1.5rem',
            'padding: 3rem',
            'padding: 4rem',
            'padding: 1.5rem',
            'padding: 2rem',
        ] as $contract) {
            self::assertStringContainsString($contract, $adminCss);
        }
        self::assertMatchesRegularExpression(
            '/builderContainer--section\s*>\s*'
                . "\\.blogEditor__builderModule--image\\[data-width='full'\\]"
                . '\s*\{[^}]*width:\s*100%;[^}]*margin-inline:\s*0;/s',
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/builderContainer--section\s*>\s*'
                . "\\.blogEditor__builderModule--image\\[data-width='full'\\]"
                . '\s+\\.blogEditor__previewImage\s*\{'
                . '[^}]*width:\s*100%;'
                . '[^}]*max-width:\s*none;'
                . '[^}]*margin-inline:\s*0;/s',
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/builderContainer--section\s*>\s*'
                . "\\.blogEditor__builderModule--image\\[data-width='full'\\]"
                . '\s+\\.blogEditor__previewImage\s+img\s*,'
                . '[^}]+\\.blogEditor__previewImageMedia\s*\{'
                . '[^}]*border-radius:\s*0;/s',
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/builderContainer--article,[^}]*builderContainer--div[^}]*'
                . 'builderColumn\s*\{\s*gap:\s*0;/s',
            $adminCss
        );
        self::assertMatchesRegularExpression(
            '/builderContainer--section\s*\{[^}]*width:\s*100%;'
                . '[^}]*max-width:\s*none;[^}]*padding-inline:\s*'
                . 'var\(--blog-builder-section-inset\)/s',
            $adminCss
        );
        foreach ([
            'blogDocument__container--width-80',
            'blogDocument__container--width-60',
            'blogDocument__container--width-40',
        ] as $contract) {
            self::assertStringContainsString($contract, $publicCss);
        }
        foreach (['&--width-80', '&--width-60', '&--width-40'] as $contract) {
            self::assertStringContainsString($contract, $resourceScss);
        }
        foreach ([$publicCss, $resourceScss] as $stylesheet) {
            foreach ([
                'padding: 2rem 1.5rem',
                'padding: 3rem',
                'padding: 4rem',
                'padding: 1.5rem',
                'padding: 2rem',
                'padding: 3rem',
            ] as $contract) {
                self::assertStringContainsString($contract, $stylesheet);
            }
        }
        self::assertStringContainsString(
            'main:has(.blogDocument--layout)',
            $publicCss
        );
        self::assertStringContainsString(
            '&:has(.blogDocument--layout)',
            $resourceScss
        );
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function compact(string $value): string
    {
        $compacted = preg_replace('/\s+/u', ' ', trim($value));
        self::assertIsString($compacted);

        return $compacted;
    }
}
