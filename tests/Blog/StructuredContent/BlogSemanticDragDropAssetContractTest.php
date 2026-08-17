<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BlogSemanticDragDropAssetContractTest extends TestCase
{
    public function testDragAndDropMovesOnlyStructurallyValidSubtrees(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            'node',
            $root . '/tests/Blog/StructuredContent/fixtures/'
                . 'blog-editor-drag-drop-harness.mjs',
            $root . '/modules/blog/published/assets/blog-editor.js',
        ]);
        $process->mustRun();
        $result = json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertTrue($result['initialValid']);
        self::assertSame([
            'protectedH2' => false,
            'articleInsideArticle' => false,
            'sectionInsideSection' => false,
            'ownDescendant' => false,
            'fourthDivLevel' => false,
            'secondH3' => true,
            'samePosition' => false,
            'unchanged' => true,
        ], $result['invalid']);

        foreach ([
            'crossSection',
            'crossColumn',
            'nestedMove',
            'articleMove',
            'sectionMove',
        ] as $scenario) {
            self::assertTrue($result[$scenario]['moved']);
            self::assertTrue($result[$scenario]['valid']);
        }
        self::assertNotContains(
            '60000000-0000-4000-8000-000000000003',
            $result['crossSection']['sourceIds']
        );
        self::assertSame(
            '60000000-0000-4000-8000-000000000003',
            $result['crossSection']['targetIds'][1]
        );
        self::assertNotContains(
            '60000000-0000-4000-8000-000000000007',
            $result['crossColumn']['sourceIds']
        );
        self::assertSame([
            '60000000-0000-4000-8000-000000000007',
        ], $result['crossColumn']['targetIds']);
        self::assertSame(
            '60000000-0000-4000-8000-000000000011',
            $result['nestedMove']['targetIds'][1]
        );
        self::assertSame(
            '60000000-0000-4000-8000-000000000004',
            $result['articleMove']['targetIds'][4]
        );
        self::assertSame([
            '60000000-0000-4000-8000-000000000014',
            '60000000-0000-4000-8000-000000000001',
        ], $result['sectionMove']['rootIds']);
    }

    public function testEditorKeepsAccessibleFallbackAndFunctionalDropIndicators(): void
    {
        $root = dirname(__DIR__, 3);
        $javascript = $this->read(
            $root . '/modules/blog/published/assets/blog-editor.js'
        );
        $stylesheet = $this->read(
            $root . '/modules/blog/published/assets/blog-admin.css'
        );

        foreach ([
            'function v2DropPlan(',
            'function v2MoveToTarget(',
            'function v2BindDragDrop(',
            "'drag-handle'",
            "'keyboard-drop'",
            "event.key === 'Escape' && context.keyboardDragNodeId",
            "'aria-pressed'",
            "'application/x-liquidstack-blog-node'",
            "'move-up'",
            "'move-down'",
            'liquidStackBlogDragController',
            'previous.abort()',
        ] as $contract) {
            self::assertStringContainsString($contract, $javascript);
        }
        foreach ([
            "'Mover el elemento a ' + targetLabel",
            "'No se puede mover el elemento a ' + targetLabel",
            'Mover aquí',
            'Posición no válida',
            "' desde ' + v2TargetContextLabel(location)",
            "'Modo mover activado para ' + nodeLabel + ' en '",
            "movedLabel + ' movido de ' + sourceLabel",
            'Esa posición no admite el elemento.',
        ] as $accessibleCopy) {
            self::assertStringContainsString($accessibleCopy, $javascript);
        }
        foreach (['posiciÃ³n', 'aquÃ­', 'vÃ¡lida'] as $mojibake) {
            self::assertStringNotContainsString($mojibake, $javascript);
        }
        foreach ([
            '.blogEditor__builderDragHandle',
            "[data-blog-v2-dragging='true']",
            "[data-blog-v2-drop-state='active']::before",
            "[data-blog-v2-keyboard-drop='available']::before",
            '.blogEditor__insertToggle--drop',
        ] as $contract) {
            self::assertStringContainsString($contract, $stylesheet);
        }
        self::assertStringNotContainsString(
            "[data-blog-v2-drop-state='active'] {\n    border-inline",
            $stylesheet
        );
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
