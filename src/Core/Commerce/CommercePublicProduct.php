<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class CommercePublicProduct
{
    /**
     * @param list<CommercePublicTaxonomyTerm> $categories
     * @param list<CommercePublicTaxonomyTerm> $tags
     * @param list<CommercePublicAttribute> $attributes
     * @param list<CommercePublicMediaReference> $media
     */
    public function __construct(
        private readonly LocalizedProduct $product,
        private readonly array $categories,
        private readonly array $tags,
        private readonly array $attributes,
        private readonly array $media
    ) {
        $this->assertList($categories, CommercePublicTaxonomyTerm::class);
        $this->assertList($tags, CommercePublicTaxonomyTerm::class);
        $this->assertList($attributes, CommercePublicAttribute::class);
        $this->assertList($media, CommercePublicMediaReference::class);
        foreach ($categories as $term) {
            if ($term->kind() !== CommercePublicTaxonomyTerm::CATEGORY) {
                throw new CommerceValidationException('Invalid public categories.');
            }
        }
        foreach ($tags as $term) {
            if ($term->kind() !== CommercePublicTaxonomyTerm::TAG) {
                throw new CommerceValidationException('Invalid public tags.');
            }
        }
    }

    public function product(): LocalizedProduct { return $this->product; }
    /** @return list<CommercePublicTaxonomyTerm> */
    public function categories(): array { return $this->categories; }
    /** @return list<CommercePublicTaxonomyTerm> */
    public function tags(): array { return $this->tags; }
    /** @return list<CommercePublicAttribute> */
    public function attributes(): array { return $this->attributes; }
    /** @return list<CommercePublicMediaReference> */
    public function media(): array { return $this->media; }

    public function cover(): ?CommercePublicMediaReference
    {
        foreach ($this->media as $media) {
            if ($media->role() === 'cover') {
                return $media;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product' => $this->product->toArray(),
            'categories' => array_map(
                static fn (CommercePublicTaxonomyTerm $term): array =>
                    $term->toArray(),
                $this->categories
            ),
            'tags' => array_map(
                static fn (CommercePublicTaxonomyTerm $term): array =>
                    $term->toArray(),
                $this->tags
            ),
            'attributes' => array_map(
                static fn (CommercePublicAttribute $attribute): array =>
                    $attribute->toArray(),
                $this->attributes
            ),
            'media' => array_map(
                static fn (CommercePublicMediaReference $media): array =>
                    $media->toArray(),
                $this->media
            ),
        ];
    }

    /** @param array<mixed> $values */
    private function assertList(array $values, string $class): void
    {
        if (!array_is_list($values)) {
            throw new CommerceValidationException('Invalid public projection.');
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new CommerceValidationException('Invalid public projection.');
            }
        }
    }
}
