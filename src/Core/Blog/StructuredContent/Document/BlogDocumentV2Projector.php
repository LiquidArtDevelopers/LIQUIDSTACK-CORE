<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use Throwable;

/**
 * In-memory, non-persistent adoption of an eligible flat v1 document and
 * canonical Texto normalization for every V2 document.
 */
final class BlogDocumentV2Projector
{
    public function __construct(
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator(),
        private readonly BlogUnifiedTextProjector $unifiedTextProjector =
            new BlogUnifiedTextProjector()
    ) {
    }

    public function tryProject(BlogDocument $document): ?BlogDocument
    {
        try {
            return $this->project($document);
        } catch (Throwable) {
            return null;
        }
    }

    public function project(BlogDocument $document): BlogDocument
    {
        if ($document->version() === BlogDocument::LAYOUT_VERSION) {
            return $this->unifiedTextProjector->project($document);
        }
        if ($document->version() !== BlogDocument::VERSION) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_SCHEMA
            );
        }

        $flat = $document->blocks();
        $layout = [];
        if (BlogDocumentTemplateRegistry::hasCover($document->template())) {
            $cover = array_shift($flat);
            if (!is_array($cover) || ($cover['type'] ?? null) !== 'image') {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_TEMPLATE_CONTRACT
                );
            }
            $cover = $this->module($cover);
            $cover['presentation']['align'] = 'center';
            $layout[] = $cover;
        }
        if (
            $flat === []
            || ($flat[0]['type'] ?? null) !== 'heading'
            || ($flat[0]['level'] ?? null) !== 2
        ) {
            // Introductory legacy blocks cannot become a valid section without
            // inventing or moving editorial copy. They remain editable as v1.
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_HEADING_HIERARCHY
            );
        }

        $section = null;
        $article = null;
        foreach ($flat as $block) {
            if (($block['type'] ?? null) === 'heading' && $block['level'] === 2) {
                if ($section !== null) {
                    $layout[] = $section;
                }
                $section = [
                    'id' => $this->uuid(),
                    'type' => 'section',
                    'children' => [$this->module($block)],
                ];
                $article = null;
                continue;
            }
            if ($section === null) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_HEADING_HIERARCHY
                );
            }
            if (($block['type'] ?? null) === 'heading' && $block['level'] === 3) {
                $section['children'][] = [
                    'id' => $this->uuid(),
                    'type' => 'article',
                    'layout' => [
                        'preset' => '1',
                        'columns' => [[
                            'id' => $this->uuid(),
                            'children' => [$this->module($block)],
                        ]],
                    ],
                ];
                $article = count($section['children']) - 1;
                continue;
            }

            $module = $this->module($block);
            if ($article === null) {
                $section['children'][] = $module;
                continue;
            }
            $section['children'][$article]['layout']['columns'][0]['children'][] =
                $module;
        }
        if ($section !== null) {
            $layout[] = $section;
        }

        return $this->unifiedTextProjector->project(BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::LAYOUT_VERSION,
            'template' => $document->template(),
            'blocks' => $layout,
        ]));
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function module(array $block): array
    {
        $block['presentation'] = [
            'width' => 'full',
            'align' => 'start',
            'text_align' => 'start',
        ];

        return $block;
    }

    private function uuid(): string
    {
        try {
            return $this->uuidGenerator->generateV4();
        } catch (Throwable) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }
    }
}
