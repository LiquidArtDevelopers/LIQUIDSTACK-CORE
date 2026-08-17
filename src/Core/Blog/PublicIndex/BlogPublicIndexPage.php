<?php

declare(strict_types=1);

namespace App\Core\Blog\PublicIndex;

/** Immutable, HTML-free projection consumed by a project-owned index view. */
final class BlogPublicIndexPage
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $categories
     * @param list<array<string, mixed>> $filters
     * @param list<array<string, mixed>> $cards
     * @param list<array{page:int,url?:string,current?:bool}> $paginationPages
     * @param list<array{url:string,label:string,count:int,active:bool}> $archivePeriods
     * @param array<string, mixed> $pageMeta
     * @param array<string, string> $languageNavigationUrls
     */
    public function __construct(
        private readonly string $locale,
        private readonly bool $partialRequest,
        private readonly bool $headRequest,
        private readonly string $state,
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $basePath,
        private readonly int $page,
        private readonly ?string $search,
        private readonly array $categories,
        private readonly string $categoryMode,
        private readonly string $order,
        private readonly array $filters,
        private readonly array $cards,
        private readonly array $paginationPages,
        private readonly ?string $previousUrl,
        private readonly ?string $nextUrl,
        private readonly array $archivePeriods,
        private readonly string $title,
        private readonly string $canonicalUrl,
        private readonly string $robotsDirective,
        private readonly array $pageMeta,
        private readonly array $languageNavigationUrls,
        private readonly BlogPublicIndexTextCatalog $copy
    ) {
    }

    public function locale(): string { return $this->locale; }
    public function isPartialRequest(): bool { return $this->partialRequest; }
    public function isHeadRequest(): bool { return $this->headRequest; }
    public function state(): string { return $this->state; }
    public function statusCode(): int { return $this->statusCode; }

    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }

    public function basePath(): string { return $this->basePath; }
    public function page(): int { return $this->page; }
    public function search(): ?string { return $this->search; }

    /** @return list<string> */
    public function categories(): array { return $this->categories; }

    public function categoryMode(): string { return $this->categoryMode; }
    public function order(): string { return $this->order; }

    /** @return list<array<string, mixed>> */
    public function filters(): array { return $this->filters; }

    /** @return list<array<string, mixed>> */
    public function cards(): array { return $this->cards; }

    public function cardCount(): int { return count($this->cards); }

    public function emptyMessage(): string
    {
        return $this->copy->emptyMessage($this->state);
    }

    /** @return list<array{page:int,url?:string,current?:bool}> */
    public function paginationPages(): array
    {
        return $this->paginationPages;
    }

    public function previousUrl(): ?string { return $this->previousUrl; }
    public function nextUrl(): ?string { return $this->nextUrl; }

    public function hasPagination(): bool
    {
        return $this->previousUrl !== null || $this->nextUrl !== null;
    }

    /** @return list<array{url:string,label:string,count:int,active:bool}> */
    public function archivePeriods(): array { return $this->archivePeriods; }

    public function archiveCount(): int { return count($this->archivePeriods); }
    public function hasArchive(): bool { return $this->archiveCount() >= 2; }
    public function title(): string { return $this->title; }
    public function canonicalUrl(): string { return $this->canonicalUrl; }
    public function robotsDirective(): string { return $this->robotsDirective; }

    /** @return array<string, mixed> */
    public function pageMeta(): array { return $this->pageMeta; }

    /** @return array<string, string> */
    public function languageNavigationUrls(): array
    {
        return $this->languageNavigationUrls;
    }

    /** @return array<string, string> */
    public function searchLabels(): array { return $this->copy->searchLabels(); }

    /** @return array<string, string> */
    public function categoryLabels(): array
    {
        return $this->copy->categoryLabels();
    }

    /** @return array<string, string> */
    public function paginationLabels(): array
    {
        return $this->copy->paginationLabels();
    }

    public function archiveHeading(): string
    {
        return $this->copy->archiveHeading();
    }

    public function archiveSingularLabel(): string
    {
        return $this->copy->archiveSingularLabel();
    }

    public function archivePluralLabel(): string
    {
        return $this->copy->archivePluralLabel();
    }

    public function resultsHeading(): string
    {
        return $this->copy->resultsHeading();
    }

    public function cardCtaLabel(): string
    {
        return $this->copy->cardCtaLabel();
    }

}
