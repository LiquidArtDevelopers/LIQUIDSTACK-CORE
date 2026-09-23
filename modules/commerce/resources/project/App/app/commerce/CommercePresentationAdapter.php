<?php

declare(strict_types=1);

namespace App\CommercePresentation;

use App\Core\Commerce\Http\CommercePublicItemPage;
use App\Core\Commerce\Http\CommercePublicHttpRuntime;
use App\Core\Commerce\CommercePublicAttributeOption;
use App\Core\Commerce\CommercePublicCatalogQuery as CoreCatalogQuery;
use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\CommercePublicTaxonomyTerm;
use App\Core\Commerce\LocalizedProduct;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

interface CommercePresentationAdapterInterface
{
    public function catalog(
        string $locale,
        ?CommerceCatalogQuery $query = null
    ): CommerceCatalogViewModel;

    public function item(
        string $locale,
        string $path
    ): CommerceItemViewModel;

    /** @param list<string> $requestedIds */
    public function inquiry(
        string $locale,
        array $requestedIds
    ): CommerceInquiryViewModel;
}

final class CommerceCatalogQuery
{
    private function __construct(
        private readonly string $search,
        private readonly ?string $category,
        private readonly ?string $tag,
        private readonly int $page
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $search = is_string($input['q'] ?? null)
            ? trim($input['q'])
            : '';
        if (
            strlen($search) > 100
            || preg_match('//u', $search) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $search) === 1
        ) {
            $search = '';
        }
        $term = static function (mixed $value): ?string {
            if (!is_string($value)) {
                return null;
            }
            $value = strtolower(trim($value));

            return preg_match(
                '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',
                $value
            ) === 1 && strlen($value) <= 80
                ? $value
                : null;
        };

        $page = is_string($input['page'] ?? null)
            && preg_match('/\A[1-9][0-9]*\z/D', $input['page']) === 1
                ? (int) $input['page']
                : 1;
        if ($page > 166_667) {
            $page = 1;
        }

        return new self(
            $search,
            $term($input['category'] ?? null),
            $term($input['tag'] ?? null),
            $page
        );
    }

    public function search(): string { return $this->search; }
    public function category(): ?string { return $this->category; }
    public function tag(): ?string { return $this->tag; }
    public function page(): int { return $this->page; }
}

final class CommerceProductViewModel
{
    /**
     * @param list<array{label:string,value:string}> $features
     * @param list<array{width:int,height:int,path:string}> $imageVariants
     */
    public function __construct(
        private readonly string $id,
        private readonly string $path,
        private readonly string $title,
        private readonly string $summary,
        private readonly string $description,
        private readonly string $reference,
        private readonly string $availability,
        private readonly string $commercialLabel,
        private readonly string $imageSrc,
        private readonly string $imageAlt,
        private readonly string $imageTitle,
        private readonly array $features,
        private readonly array $imageVariants = [],
        private readonly bool $acceptsInquiries = true
    ) {
        if (
            preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $id) !== 1
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || $title === ''
            || $reference === ''
        ) {
            throw new InvalidArgumentException(
                'Invalid Commerce product presentation.'
            );
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function availability(): string
    {
        return $this->availability;
    }

    public function commercialLabel(): string
    {
        return $this->commercialLabel;
    }

    public function imageSrc(): string
    {
        return $this->imageSrc;
    }

    public function imageAlt(): string
    {
        return $this->imageAlt;
    }

    public function imageTitle(): string
    {
        return $this->imageTitle;
    }

    /** @return list<array{label:string,value:string}> */
    public function features(): array
    {
        return $this->features;
    }

    /** @return list<array{width:int,height:int,path:string}> */
    public function imageVariants(): array { return $this->imageVariants; }

    public function acceptsInquiries(): bool
    {
        return $this->acceptsInquiries;
    }
}

final class CommerceCatalogViewModel
{
    /**
     * @param list<CommerceProductViewModel> $items
     * @param array<string, string> $labels
     * @param list<array{value:string,label:string}> $categoryOptions
     * @param list<array{value:string,label:string}> $tagOptions
     * @param list<string> $basketProductIds
     */
    public function __construct(
        private readonly array $items,
        private readonly string $heading,
        private readonly string $intro,
        private readonly string $inquiryPath,
        private readonly array $labels,
        private readonly CommerceCatalogQuery $query,
        private readonly array $categoryOptions = [],
        private readonly array $tagOptions = [],
        private readonly array $basketProductIds = [],
        private readonly bool $hasNext = false
    ) {
    }

    /** @return list<CommerceProductViewModel> */
    public function items(): array
    {
        return $this->items;
    }

    public function heading(): string
    {
        return $this->heading;
    }

    public function intro(): string
    {
        return $this->intro;
    }

    public function inquiryPath(): string
    {
        return $this->inquiryPath;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    public function query(): CommerceCatalogQuery { return $this->query; }

    /** @return list<array{value:string,label:string}> */
    public function categoryOptions(): array { return $this->categoryOptions; }

    /** @return list<array{value:string,label:string}> */
    public function tagOptions(): array { return $this->tagOptions; }

    /** @return list<string> */
    public function basketProductIds(): array { return $this->basketProductIds; }

    public function hasPrevious(): bool { return $this->query->page() > 1; }
    public function hasNext(): bool { return $this->hasNext; }
}

final class CommerceItemViewModel
{
    /** @param array<string, string> $labels */
    public function __construct(
        private readonly CommerceProductViewModel $product,
        private readonly string $catalogPath,
        private readonly string $inquiryPath,
        private readonly array $labels,
        private readonly ?int $inquiryCount = null,
        private readonly array $basketProductIds = []
    ) {
    }

    public function product(): CommerceProductViewModel
    {
        return $this->product;
    }

    public function inquiryPath(): string
    {
        return $this->inquiryPath;
    }

    public function catalogPath(): string
    {
        return $this->catalogPath;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    public function inquiryCount(): ?int { return $this->inquiryCount; }

    /** @return list<string> */
    public function basketProductIds(): array { return $this->basketProductIds; }
}

final class CommerceInquiryViewModel
{
    /**
     * @param list<CommerceProductViewModel> $items
     * @param array<string, string> $labels
     */
    public function __construct(
        private readonly array $items,
        private readonly string $path,
        private readonly string $heading,
        private readonly string $intro,
        private readonly array $labels
    ) {
    }

    /** @return list<CommerceProductViewModel> */
    public function items(): array
    {
        return $this->items;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function heading(): string
    {
        return $this->heading;
    }

    public function intro(): string
    {
        return $this->intro;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }
}

final class CommercePublicProductAdapter
{
    public static function adapt(
        CommercePublicProduct $detail,
        callable $text
    ): ?CommerceProductViewModel {
        $product = $detail->product();
        $path = $product->publicPath();
        if ($path === null) {
            return null;
        }
        $read = Closure::fromCallable($text);
        $cover = $detail->cover();
        $variants = $cover?->variants() ?? [];
        $largest = $variants === [] ? null : $variants[array_key_last($variants)];
        $features = [];
        foreach ($detail->attributes() as $attribute) {
            $value = $attribute->value();
            if (is_bool($value)) {
                $value = $read(
                    $value ? 'commerce_value_yes' : 'commerce_value_no'
                );
            } elseif (is_array($value)) {
                $value = implode(', ', array_map(
                    static fn (CommercePublicAttributeOption $option): string =>
                        $option->label(),
                    $value
                ));
            }
            if ($attribute->unit() !== null) {
                $value .= ' ' . $attribute->unit();
            }
            $features[] = [
                'label' => $attribute->name(),
                'value' => (string) $value,
            ];
        }
        $price = $product->price();

        return new CommerceProductViewModel(
            $product->publicId(),
            $path,
            $product->title(),
            $product->summary() ?? '',
            $product->description() ?? '',
            $product->sku() ?? $product->publicId(),
            $read(
                'commerce_availability_'
                . $product->availabilityStatus()->value
            ),
            $price === null
                ? $read('commerce_commercial_inquiry')
                : number_format($price->minorUnits() / 100, 2, ',', '.')
                    . ' ' . $price->currency(),
            is_array($largest) ? $largest['path'] : '',
            $cover?->altText() ?? '',
            $cover?->caption() ?? '',
            $features,
            $variants,
            $product->availabilityStatus()->acceptsInquiries()
        );
    }
}

/**
 * Bridges the canonical CORE public item DTO to the project-owned resource
 * family. No placeholder product content is invented.
 */
final class CommercePublicItemPageAdapter
{
    public static function adapt(
        CommercePublicItemPage $page,
        callable $text
    ): CommerceItemViewModel {
        $catalogText = Closure::fromCallable($text);
        $read = static function (string $key) use ($catalogText): string {
            $value = $catalogText($key);
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException(
                    "Commerce presentation catalog key {$key} is unavailable."
                );
            }

            return trim($value);
        };
        $viewProduct = CommercePublicProductAdapter::adapt(
            $page->detail(),
            $read
        );
        if (!$viewProduct instanceof CommerceProductViewModel) {
            throw new RuntimeException('The Commerce item is unavailable.');
        }

        return new CommerceItemViewModel(
            $viewProduct,
            $page->catalogPath(),
            $page->inquiryPath(),
            [
                'add' => $read('commerce_action_add'),
                'added' => $read('commerce_action_added'),
                'interest' => $read('commerce_action_interest'),
                'interest_summary' => $read('commerce_interest_summary'),
                'added_status' => $read('commerce_interest_added_status'),
                'removed_status' => $read('commerce_interest_removed_status'),
                'reference' => $read('commerce_label_reference'),
                'availability' => $read('commerce_label_availability'),
                'commercial' => $read('commerce_label_commercial'),
                'features_heading' => $read(
                    'commerce_item_features_heading'
                ),
                'back' => $read('commerce_item_back'),
                'social_proof' => $read('commerce_social_proof'),
            ],
            $page->inquiryCount(),
            $page->basketProductIds()
        );
    }
}

/** Uses CORE repositories for catalog and server-side basket projections. */
final class CommerceCorePresentationAdapter implements CommercePresentationAdapterInterface
{
    private Closure $text;

    public function __construct(
        private readonly CommercePublicHttpRuntime $publicRuntime,
        callable $text,
        private readonly ?string $basketToken = null
    ) {
        $this->text = Closure::fromCallable($text);
    }

    public function catalog(
        string $locale,
        ?CommerceCatalogQuery $query = null
    ): CommerceCatalogViewModel
    {
        $query ??= CommerceCatalogQuery::fromInput([]);
        $pageSize = 6;
        $page = $this->publicRuntime->catalogPage(
            $locale,
            new CoreCatalogQuery(
                $query->search() !== '' ? $query->search() : null,
                $query->category(),
                $query->tag(),
                $pageSize,
                ($query->page() - 1) * $pageSize
            )
        );
        $products = $page->items();

        return new CommerceCatalogViewModel(
            array_values(array_filter(array_map(
                fn (CommercePublicProduct $product): ?CommerceProductViewModel =>
                    $this->product($product),
                $products
            ))),
            $this->t('commerce_catalog_heading'),
            $this->t('commerce_catalog_intro'),
            $this->inquiryPath($locale),
            $this->sharedLabels(),
            $query,
            $this->taxonomyOptions(
                $this->publicRuntime->taxonomyTerms(
                    $locale,
                    CommercePublicTaxonomyTerm::CATEGORY
                )
            ),
            $this->taxonomyOptions(
                $this->publicRuntime->taxonomyTerms(
                    $locale,
                    CommercePublicTaxonomyTerm::TAG
                )
            ),
            $this->basketProductIds(),
            $page->hasNext()
        );
    }

    public function item(string $locale, string $path): CommerceItemViewModel
    {
        $config = $this->publicRuntime->config();
        $resolution = $this->publicRuntime->catalog()->resolvePublicPath(
            $path,
            $locale,
            $config->defaultLocale()
        );
        $localized = $resolution?->product();
        $product = $localized instanceof LocalizedProduct
            ? $this->publicRuntime->product(
                $localized->publicId(),
                $locale
            )
            : null;
        if (!$product instanceof CommercePublicProduct) {
            throw new RuntimeException('The Commerce item is unavailable.');
        }
        $viewModel = $this->product($product);
        $catalogPath = $config->publicPath($locale);
        if ($viewModel === null || $catalogPath === null) {
            throw new RuntimeException('The Commerce item is unavailable.');
        }

        return new CommerceItemViewModel(
            $viewModel,
            $catalogPath,
            $this->inquiryPath($locale),
            array_replace($this->sharedLabels(), [
                'features_heading' => $this->t(
                    'commerce_item_features_heading'
                ),
                'back' => $this->t('commerce_item_back'),
            ]),
            null,
            $this->basketProductIds()
        );
    }

    public function inquiry(
        string $locale,
        array $requestedIds
    ): CommerceInquiryViewModel {
        unset($requestedIds);
        $items = [];
        $basket = $this->basket();
        if ($basket !== null) {
            foreach ($basket?->lines() ?? [] as $line) {
                $detail = $this->publicRuntime->product(
                    $line->product()->publicId(),
                    $locale
                );
                $product = $detail instanceof CommercePublicProduct
                    ? $this->product($detail)
                    : null;
                if ($product !== null) {
                    $items[] = $product;
                }
            }
        }

        return new CommerceInquiryViewModel(
            $items,
            $this->inquiryPath($locale),
            $this->t('commerce_inquiry_heading'),
            $this->t('commerce_inquiry_intro'),
            [
                'list_heading' => $this->t('commerce_inquiry_list_heading'),
                'form_heading' => $this->t('commerce_inquiry_form_heading'),
                'empty' => $this->t('commerce_inquiry_empty'),
                'remove' => $this->t('commerce_inquiry_remove'),
                'name' => $this->t('commerce_inquiry_name'),
                'email' => $this->t('commerce_inquiry_email'),
                'phone' => $this->t('commerce_inquiry_phone'),
                'message' => $this->t('commerce_inquiry_message'),
                'privacy' => $this->t('commerce_inquiry_privacy'),
                'privacy_help' => $this->t('commerce_inquiry_privacy_help'),
                'submit' => $this->t('commerce_inquiry_submit'),
                'reset' => $this->t('commerce_inquiry_reset'),
                'notice' => $this->t('commerce_inquiry_notice'),
                'success' => $this->t('commerce_inquiry_success'),
                'invalid' => $this->t('commerce_inquiry_invalid'),
                'empty_error' => $this->t('commerce_inquiry_empty_error'),
                'development_fixture' => '0',
            ]
        );
    }

    private function basket(): ?\App\Core\Commerce\BasketSnapshot
    {
        try {
            $basket = $this->publicRuntime->basket(
                $this->basketToken,
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );

            return $basket?->status() === 'open' ? $basket : null;
        } catch (\App\Core\Commerce\CommerceValidationException) {
            return null;
        }
    }

    /** @return list<string> */
    private function basketProductIds(): array
    {
        $ids = [];
        foreach ($this->basket()?->lines() ?? [] as $line) {
            $product = $line->product();
            if (
                $product->editorialStatus()
                    === \App\Core\Commerce\ProductEditorialStatus::ACTIVE
                && $product->availabilityStatus()->acceptsInquiries()
            ) {
                $ids[] = $product->publicId();
            }
        }

        return $ids;
    }

    private function product(
        CommercePublicProduct $product
    ): ?CommerceProductViewModel {
        return CommercePublicProductAdapter::adapt(
            $product,
            fn (string $key): string => $this->t($key)
        );
    }

    /**
     * @param list<CommercePublicTaxonomyTerm> $terms
     * @return list<array{value:string,label:string}>
     */
    private function taxonomyOptions(array $terms): array
    {
        $byId = [];
        foreach ($terms as $term) {
            $byId[$term->publicId()] = $term;
        }
        $label = static function (
            CommercePublicTaxonomyTerm $term
        ) use ($byId): string {
            $segments = [$term->name()];
            $seen = [$term->publicId() => true];
            $parentId = $term->parentPublicId();
            while ($parentId !== null && isset($byId[$parentId])) {
                if (isset($seen[$parentId]) || count($segments) >= 32) {
                    throw new RuntimeException(
                        'Invalid Commerce taxonomy hierarchy.'
                    );
                }
                $seen[$parentId] = true;
                $parent = $byId[$parentId];
                array_unshift($segments, $parent->name());
                $parentId = $parent->parentPublicId();
            }

            return implode(' › ', $segments);
        };
        $options = [];
        foreach ($terms as $term) {
            $options[] = [
                'value' => $term->slug(),
                'label' => $label($term),
            ];
        }
        usort(
            $options,
            static fn (array $left, array $right): int => strnatcasecmp(
                $left['label'],
                $right['label']
            )
        );

        return $options;
    }

    /** @return array<string, string> */
    private function sharedLabels(): array
    {
        return [
            'detail' => $this->t('commerce_action_detail'),
            'add' => $this->t('commerce_action_add'),
            'added' => $this->t('commerce_action_added'),
            'interest' => $this->t('commerce_action_interest'),
            'interest_summary' => $this->t('commerce_interest_summary'),
            'added_status' => $this->t('commerce_interest_added_status'),
            'removed_status' => $this->t('commerce_interest_removed_status'),
            'empty' => $this->t('commerce_catalog_empty'),
            'reference' => $this->t('commerce_label_reference'),
            'availability' => $this->t('commerce_label_availability'),
            'commercial' => $this->t('commerce_label_commercial'),
            'search_label' => $this->t('commerce_search_label'),
            'search_placeholder' => $this->t('commerce_search_placeholder'),
            'category_label' => $this->t('commerce_filter_category_label'),
            'tag_label' => $this->t('commerce_filter_tag_label'),
            'all' => $this->t('commerce_filter_all'),
            'filter_submit' => $this->t('commerce_filter_submit'),
            'filter_clear' => $this->t('commerce_filter_clear'),
            'pagination_previous' => $this->t(
                'commerce_pagination_previous'
            ),
            'pagination_next' => $this->t('commerce_pagination_next'),
            'pagination_label' => $this->t('commerce_pagination_label'),
            'inquiry_unavailable' => $this->t(
                'commerce_action_inquiry_unavailable'
            ),
            'development_fixture' => '0',
        ];
    }

    private function inquiryPath(string $locale): string
    {
        $path = $this->publicRuntime->config()->inquiryPath($locale);
        if ($path === null) {
            throw new RuntimeException('The Commerce inquiry path is unavailable.');
        }

        return $path;
    }

    private function t(string $key): string
    {
        $value = ($this->text)($key);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Commerce presentation catalog key {$key} is unavailable."
            );
        }

        return trim($value);
    }
}

final class CommerceDevelopmentFixtureAdapter implements CommercePresentationAdapterInterface
{
    private Closure $text;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        callable $text
    ) {
        $this->text = Closure::fromCallable($text);
    }

    public function catalog(
        string $locale,
        ?CommerceCatalogQuery $query = null
    ): CommerceCatalogViewModel
    {
        $query ??= CommerceCatalogQuery::fromInput([]);
        $products = $this->products($locale);
        if ($query->search() !== '') {
            $fold = static fn (string $value): string =>
                function_exists('mb_strtolower')
                    ? mb_strtolower($value, 'UTF-8')
                    : strtolower($value);
            $needle = $fold($query->search());
            $products = array_values(array_filter(
                $products,
                static fn (CommerceProductViewModel $product): bool =>
                    str_contains(
                        $fold($product->title()),
                        $needle
                    )
                    || str_contains(
                        $fold($product->reference()),
                        $needle
                    )
            ));
        }

        $pageSize = 6;
        $offset = ($query->page() - 1) * $pageSize;
        $hasNext = count($products) > $offset + $pageSize;
        $products = array_slice($products, $offset, $pageSize);

        return new CommerceCatalogViewModel(
            $products,
            $this->t('commerce_catalog_heading'),
            $this->t('commerce_catalog_intro'),
            $this->inquiryPath($locale),
            $this->sharedLabels(),
            $query,
            [],
            [],
            [],
            $hasNext
        );
    }

    public function item(
        string $locale,
        string $path
    ): CommerceItemViewModel {
        foreach ($this->products($locale) as $product) {
            if ($product->path() === $path) {
                return new CommerceItemViewModel(
                    $product,
                    (string) $this->config['public_paths'][$locale],
                    $this->inquiryPath($locale),
                    array_replace($this->sharedLabels(), [
                        'features_heading' => $this->t(
                            'commerce_item_features_heading'
                        ),
                        'back' => $this->t('commerce_item_back'),
                    ])
                );
            }
        }

        throw new RuntimeException(
            'The requested Commerce presentation item fixture is unavailable.'
        );
    }

    public function inquiry(
        string $locale,
        array $requestedIds
    ): CommerceInquiryViewModel {
        $products = $this->products($locale);
        $byId = [];
        foreach ($products as $product) {
            $byId[$product->id()] = $product;
        }

        $selected = [];
        foreach (array_slice(array_values($requestedIds), 0, 20) as $id) {
            if (!is_string($id) || !isset($byId[$id])) {
                continue;
            }
            $selected[$id] = $byId[$id];
        }
        if ($selected === [] && $products !== []) {
            $selected[$products[0]->id()] = $products[0];
        }

        return new CommerceInquiryViewModel(
            array_values($selected),
            $this->inquiryPath($locale),
            $this->t('commerce_inquiry_heading'),
            $this->t('commerce_inquiry_intro'),
            [
                'list_heading' => $this->t('commerce_inquiry_list_heading'),
                'form_heading' => $this->t('commerce_inquiry_form_heading'),
                'empty' => $this->t('commerce_inquiry_empty'),
                'remove' => $this->t('commerce_inquiry_remove'),
                'name' => $this->t('commerce_inquiry_name'),
                'email' => $this->t('commerce_inquiry_email'),
                'phone' => $this->t('commerce_inquiry_phone'),
                'message' => $this->t('commerce_inquiry_message'),
                'privacy' => $this->t('commerce_inquiry_privacy'),
                'privacy_help' => $this->t(
                    'commerce_inquiry_privacy_help'
                ),
                'submit' => $this->t('commerce_inquiry_submit'),
                'reset' => $this->t('commerce_inquiry_reset'),
                'notice' => $this->t('commerce_inquiry_development_notice'),
                'success' => $this->t('commerce_inquiry_success'),
                'invalid' => $this->t('commerce_inquiry_invalid'),
                'empty_error' => $this->t(
                    'commerce_inquiry_empty_error'
                ),
                'development_fixture' => '1',
            ]
        );
    }

    /** @return list<CommerceProductViewModel> */
    private function products(string $locale): array
    {
        $paths = match ($locale) {
            'es' => [
                'mesa-modular' =>
                    '/es/comercio/mobiliario/mesas/mesa-modular',
                'lampara-lineal' =>
                    '/es/comercio/iluminacion/lamparas/lampara-lineal',
                'organizador-flexible' =>
                    '/es/comercio/accesorios/organizacion/organizador-flexible',
            ],
            'eu' => [
                'mesa-modular' =>
                    '/eu/merkataritza/altzariak/mahaiak/mahai-modularra',
                'lampara-lineal' =>
                    '/eu/merkataritza/argiztapena/lanparak/lanpara-lineala',
                'organizador-flexible' =>
                    '/eu/merkataritza/osagarriak/antolaketa/antolatzaile-malgua',
            ],
            default => throw new InvalidArgumentException(
                'Unsupported Commerce presentation locale.'
            ),
        };

        $images = [
            'mesa-modular' => 'dummy01.avif',
            'lampara-lineal' => 'dummy02.avif',
            'organizador-flexible' => 'dummy03.avif',
        ];
        $products = [];
        foreach ($paths as $id => $path) {
            $prefix = 'commerce_product_' . str_replace('-', '_', $id);
            $features = [];
            for ($feature = 1; $feature <= 3; ++$feature) {
                $features[] = [
                    'label' => $this->t(
                        $prefix . '_feature_' . $feature . '_label'
                    ),
                    'value' => $this->t(
                        $prefix . '_feature_' . $feature . '_value'
                    ),
                ];
            }
            $products[] = new CommerceProductViewModel(
                $id,
                $path,
                $this->t($prefix . '_title'),
                $this->t($prefix . '_summary'),
                $this->t($prefix . '_description'),
                $this->t($prefix . '_reference'),
                $this->t($prefix . '_availability'),
                $this->t($prefix . '_commercial'),
                '/assets/img/dummy/' . $images[$id],
                $this->t($prefix . '_image_alt'),
                $this->t($prefix . '_image_title'),
                $features
            );
        }

        return $products;
    }

    /** @return array<string, string> */
    private function sharedLabels(): array
    {
        return [
            'detail' => $this->t('commerce_action_detail'),
            'add' => $this->t('commerce_action_add'),
            'added' => $this->t('commerce_action_added'),
            'interest' => $this->t('commerce_action_interest'),
            'interest_summary' => $this->t(
                'commerce_interest_summary'
            ),
            'added_status' => $this->t('commerce_interest_added_status'),
            'removed_status' => $this->t(
                'commerce_interest_removed_status'
            ),
            'empty' => $this->t('commerce_catalog_empty'),
            'reference' => $this->t('commerce_label_reference'),
            'availability' => $this->t('commerce_label_availability'),
            'commercial' => $this->t('commerce_label_commercial'),
            'search_label' => $this->t('commerce_search_label'),
            'search_placeholder' => $this->t('commerce_search_placeholder'),
            'category_label' => $this->t('commerce_filter_category_label'),
            'tag_label' => $this->t('commerce_filter_tag_label'),
            'all' => $this->t('commerce_filter_all'),
            'filter_submit' => $this->t('commerce_filter_submit'),
            'filter_clear' => $this->t('commerce_filter_clear'),
            'pagination_previous' => $this->t(
                'commerce_pagination_previous'
            ),
            'pagination_next' => $this->t('commerce_pagination_next'),
            'pagination_label' => $this->t('commerce_pagination_label'),
            'inquiry_unavailable' => $this->t(
                'commerce_action_inquiry_unavailable'
            ),
            'development_fixture' => '1',
        ];
    }

    private function inquiryPath(string $locale): string
    {
        $path = $this->config['inquiry_paths'][$locale] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw new RuntimeException(
                'Commerce inquiry path is unavailable.'
            );
        }

        return $path;
    }

    private function t(string $key): string
    {
        $value = ($this->text)($key);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Commerce presentation catalog key {$key} is unavailable."
            );
        }

        return trim($value);
    }
}
