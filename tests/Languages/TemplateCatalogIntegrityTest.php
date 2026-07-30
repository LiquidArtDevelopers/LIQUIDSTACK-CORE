<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TemplateCatalogIntegrityTest extends TestCase
{
    public function testTemplateCatalogsHaveUniqueTopLevelKeys(): void
    {
        $root = dirname(__DIR__, 2)
            . '/stubs/App/config/languages/templates';

        foreach (['es', 'en', 'eu'] as $language) {
            $path = "{$root}/{$language}.json";
            $contents = file_get_contents($path);

            self::assertIsString($contents);
            self::assertIsArray(json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            ));

            $duplicates = array_keys(array_filter(
                array_count_values(
                    $this->topLevelObjectKeys($contents)
                ),
                static fn (int $count): bool => $count > 1
            ));

            self::assertSame(
                [],
                $duplicates,
                "{$path} contiene claves duplicadas: "
                    . implode(', ', $duplicates)
            );
        }
    }

    /**
     * @return list<string>
     */
    private function topLevelObjectKeys(string $json): array
    {
        $keys = [];
        $depth = 0;
        $length = strlen($json);
        $inString = false;
        $escaped = false;
        $stringStart = null;
        $stringDepth = null;

        for ($index = 0; $index < $length; ++$index) {
            $character = $json[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($character !== '"') {
                    continue;
                }

                $inString = false;
                $next = $index + 1;
                while (
                    $next < $length
                    && ctype_space($json[$next])
                ) {
                    ++$next;
                }

                if (
                    $stringDepth === 1
                    && $next < $length
                    && $json[$next] === ':'
                    && is_int($stringStart)
                ) {
                    $encodedKey = substr(
                        $json,
                        $stringStart,
                        $index - $stringStart + 1
                    );
                    $key = json_decode(
                        $encodedKey,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    if (is_string($key)) {
                        $keys[] = $key;
                    }
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
                $stringStart = $index;
                $stringDepth = $depth;
                continue;
            }

            if ($character === '{' || $character === '[') {
                ++$depth;
            } elseif ($character === '}' || $character === ']') {
                --$depth;
            }
        }

        return $keys;
    }
}
