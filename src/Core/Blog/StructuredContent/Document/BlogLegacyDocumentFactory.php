<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogInput;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;

/** Pure adapter from the legacy body_text contract to Blog document v1. */
final class BlogLegacyDocumentFactory
{
    public function __construct(
        private readonly UuidGeneratorInterface $uuidGenerator =
            new RandomUuidV4Generator()
    ) {
    }

    public function create(
        #[\SensitiveParameter] string $bodyText
    ): BlogDocument {
        try {
            $bodyText = BlogInput::multiline(
                $bodyText,
                BlogDocument::MAX_BODY_TEXT_BYTES
            );
        } catch (BlogException) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        // Tabs were valid in the plain-text contract but are controls in the
        // structured inline contract. A space preserves their textual role.
        $bodyText = str_replace("\t", ' ', $bodyText);
        $trimmed = trim($bodyText);
        if ($trimmed === '') {
            return BlogDocument::fromArray($this->document([]));
        }

        $paragraphs = preg_split('/\n[ ]*\n+/u', $trimmed);
        if (!is_array($paragraphs)) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        $paragraphs = array_values(array_filter(
            array_map('trim', $paragraphs),
            static fn (string $paragraph): bool => $paragraph !== ''
        ));
        if (count($paragraphs) > BlogDocument::MAX_BLOCKS) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_DOCUMENT
            );
        }

        $blocks = [];
        foreach ($paragraphs as $paragraph) {
            $content = $this->inlineContent($paragraph);
            if (count($content) > BlogDocumentValidator::MAX_INLINE_NODES) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }
            $blocks[] = [
                'id' => $this->uuidGenerator->generateV4(),
                'type' => 'paragraph',
                'content' => $content,
            ];
        }

        return BlogDocument::fromArray($this->document($blocks));
    }

    /** @return list<array<string, mixed>> */
    private function inlineContent(string $paragraph): array
    {
        $content = [];
        $lines = explode("\n", $paragraph);
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content[] = ['type' => 'break'];
            }
            foreach ($this->chunks($line) as $chunk) {
                $content[] = [
                    'type' => 'text',
                    'text' => $chunk,
                    'marks' => [],
                ];
            }
        }

        return $content;
    }

    /** @return list<string> */
    private function chunks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $chunks = [];
        while ($text !== '') {
            $cut = min(
                strlen($text),
                BlogDocumentValidator::MAX_INLINE_TEXT_BYTES
            );
            while (
                $cut < strlen($text)
                && $cut > 0
                && (ord($text[$cut]) & 0xC0) === 0x80
            ) {
                --$cut;
            }
            if ($cut < 1) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_INLINE
                );
            }

            $chunks[] = substr($text, 0, $cut);
            $text = substr($text, $cut);
        }

        return $chunks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function document(array $blocks): array
    {
        return [
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => BlogDocumentTemplateRegistry::ARTICLE_BASIC,
            'blocks' => $blocks,
        ];
    }
}
