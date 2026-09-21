<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\AttributeDefinitionDraft;
use App\Core\Commerce\AttributeValueDraft;
use App\Core\Commerce\CategoryLocalizationDraft;
use App\Core\Commerce\CommerceAttributeType;
use App\Core\Commerce\CommerceCapabilities;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceException;
use App\Core\Commerce\CommerceValidationException;
use App\Core\Commerce\Money;
use App\Core\Commerce\ProductAvailabilityStatus;
use App\Core\Commerce\ProductEditorialStatus;
use App\Core\Commerce\ProductLocalizationDraft;
use App\Core\Commerce\TagLocalizationDraft;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Media\MediaService;
use Throwable;

final class CommerceAdminHttpController
{
    private readonly CommerceAdminRequestPolicy $policy;
    private readonly CommerceAdminHtmlRenderer $renderer;
    private readonly WebAdminShellContextFactory $shells;

    public function __construct(
        private readonly CommerceAdminHttpRuntimeInterface $runtime,
        ?CommerceAdminRequestPolicy $policy = null,
        ?CommerceAdminHtmlRenderer $renderer = null
    ) {
        $this->policy = $policy ?? new CommerceAdminRequestPolicy();
        $this->renderer = $renderer ?? new CommerceAdminHtmlRenderer();
        $this->shells = new WebAdminShellContextFactory(
            $runtime->webAdminConfig()->basePath(),
            $runtime->authorization(),
            $runtime->navigation()
        );
    }

    public function index(Request $request): Response
    {
        if (!$this->policy->acceptsIndex($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_VIEW);
        if ($context instanceof Response) {
            return $context;
        }
        try {
            $locale = $this->runtime->commerceConfig()->defaultLocale();
            $products = $this->runtime->reads()->products($locale, $locale);
            $canEdit = $this->runtime->authorization()->hasCapability(
                $context['session'],
                CommerceCapabilities::PRODUCTS_EDIT
            );
            $canSettings = $this->runtime->authorization()->hasCapability(
                $context['session'],
                CommerceCapabilities::SETTINGS_MANAGE
            );

            return $this->htmlForRequest($request, $this->renderer->products(
                $this->basePath(),
                $products,
                $canEdit,
                $this->shell($context, '/commerce'),
                $canSettings
            ));
        } catch (Throwable) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function newProduct(Request $request): Response
    {
        if (!$this->policy->acceptsIndex($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        $primary = $this->runtime->commerceConfig()->defaultLocale();

        return $this->htmlForRequest($request, $this->renderer->productForm(
            $this->basePath(),
            [$primary],
            $primary,
            $context['csrf'],
            $this->shell($context, '/commerce')
        ));
    }

    public function create(Request $request): Response
    {
        if (!$this->policy->acceptsCreate($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::PRODUCTS_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $product = $this->runtime->catalog()->createProduct(
                ProductLocalizationDraft::source(
                    (string) $request->form('locale'),
                    (string) $request->form('title'),
                    (string) $request->form('slug'),
                    $this->nullable($request->form('summary')),
                    $this->nullable($request->form('description'))
                ),
                $this->nullable($request->form('sku')),
                $this->money($request),
                $this->runtime->now()
            );

            return $this->redirect($this->basePath() . '/products/edit?'
                . http_build_query([
                    'product' => $product->publicId(),
                    'locale' => $product->requestedLocale(),
                ], '', '&', PHP_QUERY_RFC3986));
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException) {
            return $this->plain(409, 'Conflict');
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function edit(Request $request): Response
    {
        if (!$this->policy->acceptsEdit($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        try {
            $product = $this->runtime->catalog()->product(
                (string) $request->query('product'),
                (string) $request->query('locale')
            );
            if ($product === null) {
                return $this->plain(404, 'Not found');
            }
            $primary = $this->runtime->commerceConfig()->defaultLocale();
            $canUseTaxonomies = $this->runtime->authorization()->hasCapability(
                $context['session'],
                CommerceCapabilities::TAXONOMIES_VIEW
            );
            $canUseMedia = $this->runtime->mediaReady()
                && $this->runtime->authorization()->hasCapability(
                    $context['session'],
                    MediaService::VIEW_CAPABILITY
                );
            $editor = [
                'primary_locale' => $primary,
                'categories' => $canUseTaxonomies
                    ? $this->runtime->reads()->categories($product->requestedLocale(), $primary)
                    : [],
                'selected_categories' => $canUseTaxonomies
                    ? $this->runtime->reads()->productCategoryPublicIds($product->publicId())
                    : [],
                'canonical_category' => $canUseTaxonomies
                    ? $this->runtime->reads()->productCanonicalCategoryPublicId($product->publicId())
                    : null,
                'tags' => $canUseTaxonomies
                    ? $this->runtime->reads()->tags($product->requestedLocale(), $primary)
                    : [],
                'selected_tags' => $canUseTaxonomies
                    ? $this->runtime->reads()->productTagPublicIds($product->publicId())
                    : [],
                'attributes' => $canUseTaxonomies
                    ? $this->runtime->reads()->attributes($product->requestedLocale(), $primary)
                    : [],
                'attribute_values' => $canUseTaxonomies
                    ? $this->runtime->reads()->productAttributeValues($product->publicId())
                    : [],
                'media_assets' => $canUseMedia
                    ? $this->runtime->reads()->mediaAssets()
                    : [],
                'product_media' => $canUseMedia
                    ? $this->runtime->reads()->productMedia(
                        $product->publicId(),
                        $product->requestedLocale(),
                        $primary
                    )
                    : [],
            ];

            return $this->htmlForRequest($request, $this->renderer->productForm(
                $this->basePath(),
                $this->runtime->languages(),
                $primary,
                $context['csrf'],
                $this->shell($context, '/commerce'),
                $product,
                false,
                $editor,
                $canUseTaxonomies,
                $canUseMedia
            ));
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->policy->acceptsSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        $status = ProductEditorialStatus::from((string) $request->form('status'));
        $capability = match ($status) {
            ProductEditorialStatus::ACTIVE => CommerceCapabilities::PRODUCTS_PUBLISH,
            ProductEditorialStatus::ARCHIVED => CommerceCapabilities::PRODUCTS_ARCHIVE,
            default => CommerceCapabilities::PRODUCTS_EDIT,
        };
        if ($capability !== CommerceCapabilities::PRODUCTS_EDIT
            && !$this->runtime->authorization()->hasCapability($context['session'], $capability)
        ) {
            return $this->plain(403, 'Forbidden');
        }
        if (!$this->authorizeWrite($context, $request, $capability)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $id = (string) $request->form('product');
            $locale = (string) $request->form('locale');
            $existing = $this->runtime->catalog()->product($id, $locale);
            if ($existing === null) {
                return $this->plain(404, 'Not found');
            }
            if (($existing->sku() ?? '') !== (string) $request->form('sku')) {
                return $this->plain(422, 'Unprocessable content');
            }
            if ($status === ProductEditorialStatus::ACTIVE
                && !$this->runtime->reads()->productMediaHasAltForLocales(
                    $id,
                    $this->runtime->languages(),
                    $this->runtime->commerceConfig()->defaultLocale()
                )
            ) {
                return $this->plain(422, 'Unprocessable content');
            }
            $draft = $locale === $this->runtime->commerceConfig()->defaultLocale()
                ? ProductLocalizationDraft::source(
                    $locale,
                    (string) $request->form('title'),
                    (string) $request->form('slug'),
                    $this->nullable($request->form('summary')),
                    $this->nullable($request->form('description'))
                )
                : ProductLocalizationDraft::translated(
                    $locale,
                    (string) $request->form('title'),
                    (string) $request->form('slug'),
                    $this->nullable($request->form('summary')),
                    $this->nullable($request->form('description'))
                );
            $availability = ProductAvailabilityStatus::from(
                (string) $request->form('availability')
            );
            $saved = $this->runtime->catalog()->saveProductDetails(
                $id,
                $draft,
                (int) $request->form('lock_version'),
                $status === ProductEditorialStatus::ACTIVE ? null : $status,
                $availability,
                $this->money($request),
                $this->runtime->now()
            );
            if ($status === ProductEditorialStatus::ACTIVE) {
                $this->runtime->catalog()->activateProduct(
                    $id,
                    $saved->lockVersion(),
                    $this->runtime->commerceConfig()->publicPaths(),
                    $this->runtime->now()
                );
            }

            return $this->redirect($this->basePath());
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->plain(
                $exception->kind() === CommerceConflictException::NOT_FOUND ? 404 : 409,
                $exception->kind() === CommerceConflictException::NOT_FOUND ? 'Not found' : 'Conflict'
            );
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function taxonomies(Request $request): Response
    {
        if (!$this->policy->acceptsIndex($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_VIEW);
        if ($context instanceof Response) {
            return $context;
        }
        try {
            $locale = $this->runtime->commerceConfig()->defaultLocale();
            $canEdit = $this->runtime->authorization()->hasCapability(
                $context['session'],
                CommerceCapabilities::TAXONOMIES_EDIT
            );

            $categories = [];
            $tags = [];
            foreach ($this->runtime->languages() as $language) {
                array_push(
                    $categories,
                    ...$this->runtime->reads()->categories($language, $locale)
                );
                array_push(
                    $tags,
                    ...$this->runtime->reads()->tags($language, $locale)
                );
            }

            return $this->htmlForRequest($request, $this->renderer->taxonomies(
                $this->basePath(),
                $categories,
                $tags,
                $context['csrf'],
                $canEdit,
                $this->shell($context, '/commerce/taxonomies'),
                false,
                $this->runtime->languages(),
                $locale,
                $this->runtime->reads()->attributes($locale, $locale)
            ));
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function createCategory(Request $request): Response
    {
        if (!$this->policy->acceptsCategoryCreate($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $this->runtime->catalog()->createCategory(
                CategoryLocalizationDraft::source(
                    $this->runtime->commerceConfig()->defaultLocale(),
                    (string) $request->form('name'),
                    (string) $request->form('slug')
                ),
                $this->nullable($request->form('parent')),
                $this->runtime->now()
            );
            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException) {
            return $this->plain(409, 'Conflict');
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function createTag(Request $request): Response
    {
        if (!$this->policy->acceptsTagCreate($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $this->runtime->catalog()->createTag(
                TagLocalizationDraft::source(
                    $this->runtime->commerceConfig()->defaultLocale(),
                    (string) $request->form('name'),
                    (string) $request->form('slug')
                ),
                $this->runtime->now()
            );
            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException) {
            return $this->plain(409, 'Conflict');
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveCategoryLocalization(Request $request): Response
    {
        if (!$this->policy->acceptsCategoryLocalizationSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $locale = (string) $request->form('locale');
            $draft = $locale === $this->runtime->commerceConfig()->defaultLocale()
                ? CategoryLocalizationDraft::source(
                    $locale,
                    (string) $request->form('name'),
                    (string) $request->form('slug')
                )
                : CategoryLocalizationDraft::translated(
                    $locale,
                    (string) $request->form('name'),
                    (string) $request->form('slug')
                );
            $this->runtime->catalog()->saveCategoryLocalization(
                (string) $request->form('category'),
                $draft,
                (int) $request->form('lock_version'),
                $this->runtime->now()
            );

            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveCategoryParent(Request $request): Response
    {
        if (!$this->policy->acceptsCategoryParentSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $this->runtime->catalog()->setCategoryParent(
                (string) $request->form('category'),
                $this->nullable($request->form('parent')),
                (int) $request->form('lock_version'),
                $this->runtime->now()
            );

            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveCategoryOrder(Request $request): Response
    {
        if (!$this->policy->acceptsCategoryOrderSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        try {
            if (!$this->runtime->setCategorySortOrder(
                (string) $request->form('category'),
                (int) $request->form('sort_order'),
                (int) $request->form('lock_version'),
                $context['session'],
                (string) $request->form('csrf')
            )) {
                return $this->plain(403, 'Forbidden');
            }

            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveTagLocalization(Request $request): Response
    {
        if (!$this->policy->acceptsTagLocalizationSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $locale = (string) $request->form('locale');
            $draft = $locale === $this->runtime->commerceConfig()->defaultLocale()
                ? TagLocalizationDraft::source(
                    $locale,
                    (string) $request->form('name'),
                    (string) $request->form('slug')
                )
                : TagLocalizationDraft::translated(
                    $locale,
                    (string) $request->form('name'),
                    (string) $request->form('slug')
                );
            $this->runtime->catalog()->saveTagLocalization(
                (string) $request->form('tag'),
                $draft,
                $this->runtime->now()
            );

            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveProductTaxonomies(Request $request): Response
    {
        if (!$this->policy->acceptsProductTaxonomiesSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->authorization()->hasCapability(
            $context['session'],
            CommerceCapabilities::TAXONOMIES_VIEW
        ) || !$this->authorizeWrite(
            $context,
            $request,
            CommerceCapabilities::PRODUCTS_EDIT
        )) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $product = (string) $request->form('product');
            /** @var list<string> $categories */
            $categories = $request->form('categories');
            /** @var list<string> $tags */
            $tags = $request->form('tags', []);
            $this->runtime->catalog()->assignCategories(
                $product,
                $categories,
                (string) $request->form('canonical'),
                $this->runtime->now()
            );
            $this->runtime->catalog()->assignTags(
                $product,
                $tags,
                $this->runtime->now()
            );

            return $this->redirectToProduct($product, $this->runtime->commerceConfig()->defaultLocale());
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function createAttribute(Request $request): Response
    {
        if (!$this->policy->acceptsAttributeCreate($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $this->runtime->catalog()->defineAttribute(
                new AttributeDefinitionDraft(
                    (string) $request->form('code'),
                    CommerceAttributeType::from((string) $request->form('type')),
                    $this->runtime->commerceConfig()->defaultLocale(),
                    (string) $request->form('name'),
                    $this->nullable($request->form('category')),
                    $this->nullable($request->form('unit')),
                    $request->form('filterable') === '1',
                    (int) $request->form('sort_order')
                ),
                $this->runtime->now()
            );

            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function createAttributeOption(Request $request): Response
    {
        if (!$this->policy->acceptsAttributeOptionCreate($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::TAXONOMIES_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->authorizeWrite($context, $request, CommerceCapabilities::TAXONOMIES_EDIT)) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $labels = [];
            foreach ((array) $request->form('labels') as $locale => $label) {
                if (is_string($locale) && is_string($label) && trim($label) !== '') {
                    $labels[$locale] = $label;
                }
            }
            $this->runtime->catalog()->addAttributeOption(
                (string) $request->form('attribute'),
                (string) $request->form('code'),
                (int) $request->form('sort_order'),
                $labels,
                $this->runtime->now()
            );

            return $this->redirect($this->basePath() . '/taxonomies');
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveAttributeValue(Request $request): Response
    {
        if (!$this->policy->acceptsAttributeValueSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->authorization()->hasCapability(
            $context['session'],
            CommerceCapabilities::TAXONOMIES_VIEW
        ) || !$this->authorizeWrite(
            $context,
            $request,
            CommerceCapabilities::PRODUCTS_EDIT
        )) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $primary = $this->runtime->commerceConfig()->defaultLocale();
            $attributeId = (string) $request->form('attribute');
            $attributes = $this->runtime->reads()->attributes(
                (string) $request->form('locale'),
                $primary
            );
            $definition = null;
            foreach ($attributes as $attribute) {
                if (($attribute['public_id'] ?? null) === $attributeId) {
                    $definition = $attribute;
                    break;
                }
            }
            if ($definition === null) {
                return $this->plain(404, 'Not found');
            }
            $type = CommerceAttributeType::from((string) ($definition['type'] ?? ''));
            $value = $request->form('value');
            $draft = match ($type) {
                CommerceAttributeType::TEXT => AttributeValueDraft::text(
                    (string) $request->form('locale'),
                    (string) $value
                ),
                CommerceAttributeType::NUMBER => AttributeValueDraft::number((string) $value),
                CommerceAttributeType::BOOLEAN => AttributeValueDraft::boolean($value === '1'),
                CommerceAttributeType::DATE => AttributeValueDraft::date((string) $value),
                CommerceAttributeType::SELECT => AttributeValueDraft::select((string) $value),
                CommerceAttributeType::MULTISELECT => AttributeValueDraft::multiselect(
                    is_array($value) ? array_values($value) : []
                ),
            };
            $product = (string) $request->form('product');
            $this->runtime->catalog()->setAttributeValue(
                $product,
                $attributeId,
                $draft,
                $this->runtime->now()
            );

            return $this->redirectToProduct($product, (string) $request->form('locale'));
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveProductMedia(Request $request): Response
    {
        if (!$this->policy->acceptsProductMediaSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->mediaReady()) {
            return $this->plain(503, 'Service unavailable');
        }
        if (!$this->runtime->authorization()->hasCapability(
            $context['session'],
            MediaService::VIEW_CAPABILITY
        )) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $cover = null;
            $gallery = [];
            foreach ((array) $request->form('roles') as $media => $role) {
                if ($role === 'cover') {
                    $cover = (string) $media;
                } elseif ($role === 'gallery') {
                    $gallery[] = (string) $media;
                }
            }
            $product = (string) $request->form('product');
            $locale = (string) $request->form('locale');
            if ($locale !== $this->runtime->commerceConfig()->defaultLocale()) {
                return $this->plain(422, 'Unprocessable content');
            }
            /** @var array<string, string> $alts */
            $alts = $request->form('alts');
            /** @var array<string, string> $captions */
            $captions = $request->form('captions');
            if (!$this->runtime->replaceProductMedia(
                $product,
                $cover,
                $gallery,
                (int) $request->form('lock_version'),
                $locale,
                $alts,
                $captions,
                $context['session'],
                (string) $request->form('csrf')
            )) {
                return $this->plain(403, 'Forbidden');
            }

            return $this->redirectToProduct(
                $product,
                $locale
            );
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function saveProductMediaLocalization(Request $request): Response
    {
        if (!$this->policy->acceptsProductMediaLocalizationSave($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::PRODUCTS_EDIT);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->runtime->mediaReady()
            || !$this->runtime->authorization()->hasCapability(
                $context['session'],
                MediaService::VIEW_CAPABILITY
            )
        ) {
            return $this->plain(403, 'Forbidden');
        }
        try {
            $product = (string) $request->form('product');
            $locale = (string) $request->form('locale');
            if (!$this->runtime->saveProductMediaLocalization(
                $product,
                (string) $request->form('media'),
                $locale,
                (string) $request->form('alt_text'),
                $this->nullable($request->form('caption')),
                $context['session'],
                (string) $request->form('csrf')
            )) {
                return $this->plain(403, 'Forbidden');
            }

            return $this->redirectToProduct($product, $locale);
        } catch (CommerceValidationException) {
            return $this->plain(422, 'Unprocessable content');
        } catch (CommerceConflictException $exception) {
            return $this->conflict($exception);
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function inquiries(Request $request): Response
    {
        if (!$this->policy->acceptsIndex($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::INQUIRIES_VIEW);
        if ($context instanceof Response) {
            return $context;
        }
        try {
            return $this->htmlForRequest($request, $this->renderer->inquiries(
                $this->runtime->reads()->inquiries(),
                $this->shell($context, '/commerce/inquiries'),
                $this->basePath()
            ));
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function inquiryDetail(Request $request): Response
    {
        if (!$this->policy->acceptsInquiryDetail($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::INQUIRIES_VIEW);
        if ($context instanceof Response) {
            return $context;
        }
        try {
            $inquiry = $this->runtime->reads()->inquiry(
                (string) $request->query('inquiry')
            );
            if ($inquiry === null) {
                return $this->plain(404, 'Not found');
            }
            $canViewMedia = $this->runtime->mediaReady()
                && $this->runtime->authorization()->hasCapability(
                    $context['session'],
                    MediaService::VIEW_CAPABILITY
                );

            return $this->htmlForRequest($request, $this->renderer->inquiryDetail(
                $this->basePath(),
                $inquiry,
                $canViewMedia,
                $this->shell($context, '/commerce/inquiries')
            ));
        } catch (CommerceException) {
            return $this->plain(503, 'Service unavailable');
        }
    }

    public function settings(Request $request): Response
    {
        if (!$this->policy->acceptsIndex($request)) {
            return $this->plain(400, 'Bad request');
        }
        $context = $this->authorized($request, CommerceCapabilities::SETTINGS_MANAGE);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->htmlForRequest($request, $this->renderer->settings(
            $this->runtime->commerceConfig()->toSafeArray(),
            $this->shell($context, '/commerce/settings')
        ));
    }

    /** @return array{session: string, csrf: string}|Response */
    private function authorized(Request $request, string $capability): array|Response
    {
        $config = $this->runtime->webAdminConfig();
        $sessionToken = $request->cookie($config->cookieName());
        if ($sessionToken === null) {
            return $this->redirect($config->basePath() . '/login');
        }
        $session = $this->runtime->authentication()->resolveAuthenticatedSession($sessionToken);
        if ($session === null) {
            return $this->withExpiredCookie($this->redirect($config->basePath() . '/login'));
        }
        if (!$this->runtime->authorization()->mayAccessWebAdmin($sessionToken)) {
            return $this->plain(403, 'Forbidden');
        }
        if (!$this->runtime->authorization()->hasCapability($sessionToken, $capability)) {
            return $this->plain(403, 'Forbidden');
        }
        $csrf = $this->runtime->authentication()->authenticatedCsrfToken($sessionToken);
        if ($csrf === null) {
            return $this->withExpiredCookie($this->redirect($config->basePath() . '/login'));
        }
        return ['session' => $sessionToken, 'csrf' => $csrf->csrfToken()];
    }

    /** @param array{session: string, csrf: string} $context */
    private function authorizeWrite(array $context, Request $request, string $capability): bool
    {
        return $this->runtime->authorizeMutation(
            $context['session'],
            (string) $request->form('csrf'),
            $capability
        );
    }

    /** @param array{session: string, csrf: string} $context */
    private function shell(array $context, string $activePath): WebAdminShellContext
    {
        return $this->shells->create(
            $context['session'],
            $context['csrf'],
            $activePath
        );
    }

    private function money(Request $request): ?Money
    {
        $value = (string) $request->form('price');
        if ($value === '') {
            return null;
        }
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $decimal = str_pad($decimal, 2, '0');
        return new Money(
            ((int) $whole * 100) + (int) substr($decimal, 0, 2),
            (string) $request->form('currency')
        );
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function conflict(CommerceConflictException $exception): Response
    {
        return $this->plain(
            $exception->kind() === CommerceConflictException::NOT_FOUND ? 404 : 409,
            $exception->kind() === CommerceConflictException::NOT_FOUND
                ? 'Not found' : 'Conflict'
        );
    }

    private function redirectToProduct(string $product, string $locale): Response
    {
        return $this->redirect($this->basePath() . '/products/edit?'
            . http_build_query([
                'product' => $product,
                'locale' => $locale,
            ], '', '&', PHP_QUERY_RFC3986));
    }

    private function basePath(): string
    {
        return rtrim($this->runtime->webAdminConfig()->basePath(), '/') . '/commerce';
    }

    private function htmlForRequest(Request $request, string $body): Response
    {
        return new Response(200, $request->method() === 'HEAD' ? '' : $body, $this->headers(
            "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/html; charset=utf-8', 'Content-Language' => 'es']);
    }

    private function plain(int $status, string $body): Response
    {
        return new Response($status, $body, $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ) + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function redirect(string $path): Response
    {
        return new Response(303, '', ['Location' => $path] + $this->headers(
            "default-src 'none'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'"
        ));
    }

    private function withExpiredCookie(Response $response): Response
    {
        $config = $this->runtime->webAdminConfig();
        return $response->withAddedHeader('Set-Cookie', $config->cookieName()
            . '=; Path=' . $config->cookiePath()
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Secure; HttpOnly; SameSite='
            . WebAdminConfig::COOKIE_SAME_SITE);
    }

    /** @return array<string, string> */
    private function headers(string $csp): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
