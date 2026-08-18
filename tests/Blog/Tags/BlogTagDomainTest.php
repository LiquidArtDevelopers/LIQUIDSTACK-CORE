<?php

declare(strict_types=1);

namespace Tests\Blog\Tags;

use App\Core\Blog\Tags\BlogTagException;
use App\Core\Blog\Tags\BlogTagSetNormalizer;
use App\Core\Blog\Tags\BlogTagSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlogTagDomainTest extends TestCase
{
    public function testActualUtf8LatinCharactersFoldToStableAscii(): void
    {
        $name = 'áéíóúñçü';
        $folded = mb_convert_case($name, MB_CASE_FOLD, 'UTF-8');
        $hash = hash('sha256', $folded);

        self::assertSame(
            'aeiouncu',
            BlogTagSlug::base($folded, $hash)
        );
        self::assertSame(
            'aeiouncu-' . substr($hash, 0, 16),
            BlogTagSlug::collision('aeiouncu', $hash)
        );
    }

    public function testNormalizerAcceptsZeroOneThirtyAndExact4096Bytes(): void
    {
        $normalizer = new BlogTagSetNormalizer();
        self::assertSame([], $normalizer->normalize(''));
        self::assertSame('Fiscal', $normalizer->normalize(' Fiscal ')[0]->name());

        $thirty = array_map(
            static fn (int $index): string => 'Etiqueta ' . $index,
            range(1, 30)
        );
        self::assertCount(30, $normalizer->normalize(implode(',', $thirty)));

        $names = array_map(
            static fn (int $index): string =>
                sprintf('T%03d', $index) . str_repeat('😀', 59),
            range(1, 17)
        );
        $csv = implode(',', $names);
        self::assertSame(4096, strlen($csv));
        self::assertCount(17, $normalizer->normalize($csv));
        $this->expectTagIssue(
            BlogTagException::INVALID_INPUT,
            static fn () => $normalizer->normalize($csv . 'x')
        );
    }

    public function testIdentityIsCasefoldedFirstWriterAndDeduplicated(): void
    {
        $tags = (new BlogTagSetNormalizer())->normalize(
            ' Inteligencia Artificial ,INTELIGENCIA ARTIFICIAL, Fiscal '
        );
        self::assertCount(2, $tags);
        $byHash = [];
        foreach ($tags as $tag) {
            $byHash[$tag->normalizedSha256()] = $tag;
        }
        $identity = hash('sha256', 'inteligencia artificial');
        self::assertSame('Inteligencia Artificial', $byHash[$identity]->name());
        self::assertSame(
            'inteligencia-artificial',
            $byHash[$identity]->baseSlug()
        );
    }

    public function testNfcConvergesComposedAndDecomposedNames(): void
    {
        $tags = (new BlogTagSetNormalizer())->normalize(
            "Cafe\u{0301},Caf\u{00E9}"
        );

        self::assertCount(1, $tags);
        self::assertSame("Caf\u{00E9}", $tags[0]->name());
        self::assertSame(
            hash('sha256', "caf\u{00E9}"),
            $tags[0]->normalizedSha256()
        );
    }

    public function testUnicodeCasefoldDoesNotPromiseLocaleSpecificIdentity(): void
    {
        $german = (new BlogTagSetNormalizer())->normalize(
            "Stra\u{00DF}e,STRASSE"
        );
        self::assertCount(1, $german);
        self::assertSame("Stra\u{00DF}e", $german[0]->name());

        $turkish = (new BlogTagSetNormalizer())->normalize(
            "\u{0130},i,i\u{0307}"
        );
        self::assertCount(2, $turkish);
        self::assertSame(
            2,
            count(array_unique(array_map(
                static fn ($tag): string => $tag->normalizedSha256(),
                $turkish
            )))
        );
    }

    /** @return array<string, array{string}> */
    public static function invalidCsvProvider(): array
    {
        return [
            '31 values' => [implode(',', array_map(
                static fn (int $index): string => 'tag' . $index,
                range(1, 31)
            ))],
            'control' => ["fiscal\tlegal"],
            'newline' => ["fiscal\nlegal"],
            'C1 next line' => ["fiscal\u{0085}legal"],
            'C1 application control' => ["fiscal\u{009F}legal"],
            'Unicode line separator' => ["fiscal\u{2028}legal"],
            'Unicode paragraph separator' => ["fiscal\u{2029}legal"],
            'bidi embedding' => ["fiscal\u{202A}legal"],
            'bidi override' => ["fiscal\u{202E}legal"],
            'bidi isolate' => ["fiscal\u{2066}legal"],
            'bidi pop isolate' => ["fiscal\u{2069}legal"],
            'zero width space' => ["fiscal\u{200B}legal"],
            'zero width non joiner' => ["fiscal\u{200C}legal"],
            'word joiner' => ["fiscal\u{2060}legal"],
            'byte order mark' => ["fiscal\u{FEFF}legal"],
            'soft hyphen' => ["fiscal\u{00AD}legal"],
            'invisible only' => ["\u{200B}"],
            'invalid utf8' => ["fiscal\xFF"],
            '65 codepoints' => [str_repeat('a', 65)],
            '256 bytes' => [str_repeat('😀', 64)],
        ];
    }

    #[DataProvider('invalidCsvProvider')]
    public function testNormalizerRejectsInvalidBoundaries(string $csv): void
    {
        $this->expectTagIssue(
            BlogTagException::INVALID_INPUT,
            static fn () => (new BlogTagSetNormalizer())->normalize($csv)
        );
    }

    public function testNormalizerPreservesPrintableUnicodeAndEmojiZwj(): void
    {
        $tags = (new BlogTagSetNormalizer())->normalize(
            "Fiscal — legal, Familia 👩‍💻"
        );

        $names = array_map(static fn ($tag): string => $tag->name(), $tags);
        sort($names, SORT_STRING);
        $expected = ["Familia 👩‍💻", "Fiscal — legal"];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $names);
    }

    /** @param callable(): mixed $operation */
    private function expectTagIssue(string $issue, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected Blog tag issue ' . $issue);
        } catch (BlogTagException $exception) {
            self::assertSame($issue, $exception->issueCode());
        }
    }
}
