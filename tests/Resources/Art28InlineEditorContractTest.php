<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Art28InlineEditorContractTest extends TestCase
{
    private const SRCSET_VALUES = [
        'srcset01' => 'assets/img/dummy/dummy_1200.avif 1200w',
        'srcset02' => 'assets/img/dummy/dummy_1800.avif 1800w',
    ];

    public function testControllerUsesTheRelatedSrcsetSuffixRecognisedByTheEditor(): void
    {
        $controller = $this->readFile(
            $this->coreRoot() . '/stubs/App/controllers/art28.php'
        );
        $inlineEditor = $this->readFile(
            $this->coreRoot() . '/resources/js/_inlineEditor.js'
        );

        self::assertStringContainsString('/^srcset\d+$/i', $inlineEditor);
        self::assertStringContainsString('_img_srcset01"', $controller);
        self::assertStringContainsString('_img_srcset02"', $controller);
        self::assertStringNotContainsString('_img_src01"', $controller);
        self::assertStringNotContainsString('_img_src02"', $controller);
    }

    public function testEveryTemplateLanguageHydratesBothCandidatesForEveryImage(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = json_decode(
                $this->readFile(
                    $this->coreRoot()
                        . "/stubs/App/config/languages/templates/{$language}.json"
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertIsArray($catalog);

            foreach (['a', 'b', 'c'] as $item) {
                foreach (self::SRCSET_VALUES as $suffix => $expected) {
                    $key = "art28_00_{$item}_img_{$suffix}";

                    self::assertArrayHasKey($key, $catalog, "{$language}: {$key}");
                    self::assertSame($expected, $catalog[$key], "{$language}: {$key}");
                }
            }
        }
    }

    private function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        self::assertIsString($content, "Could not read {$path}");

        return $content;
    }
}
