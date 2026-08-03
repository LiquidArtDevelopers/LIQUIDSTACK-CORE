<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

final class BlogSeoSemanticProjection
{
    /**
     * @param list<string> $tokens
     * @param list<string> $introTokens
     * @param list<array{level:int,text:string}> $headings
     * @param list<array{decorative:bool,alt:string}> $images
     */
    public function __construct(
        private readonly string $bodyText,
        private readonly array $tokens,
        private readonly array $introTokens,
        private readonly array $headings,
        private readonly array $images
    ) {
    }

    public function bodyText(): string { return $this->bodyText; }
    /** @return list<string> */
    public function tokens(): array { return $this->tokens; }
    /** @return list<string> */
    public function introTokens(): array { return $this->introTokens; }
    /** @return list<array{level:int,text:string}> */
    public function headings(): array { return $this->headings; }
    /** @return list<array{decorative:bool,alt:string}> */
    public function images(): array { return $this->images; }
}
