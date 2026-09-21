<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Http\Request;
use App\Core\WebAdmin\Http\WebAdminHttpRequestPolicy;

final class CommerceAdminRequestPolicy
{
    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
    private const LOCALE = '/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/';
    private const SLUG = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    public function __construct(
        private readonly WebAdminHttpRequestPolicy $webAdmin =
            new WebAdminHttpRequestPolicy()
    ) {
    }

    public function acceptsIndex(Request $request): bool
    {
        return $this->webAdmin->acceptsSafeNavigation($request);
    }

    public function acceptsEdit(Request $request): bool
    {
        if (!$request->isValid()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->formParams() !== []
            || $request->bodySize() !== 0
        ) {
            return false;
        }
        $query = $request->queryParams();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);

        return $keys === ['locale', 'product']
            && is_string($query['product'] ?? null)
            && preg_match(self::UUID, $query['product']) === 1
            && is_string($query['locale'] ?? null)
            && preg_match(self::LOCALE, $query['locale']) === 1;
    }

    public function acceptsInquiryDetail(Request $request): bool
    {
        if (!$request->isValid()
            || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->formParams() !== []
            || $request->bodySize() !== 0
        ) {
            return false;
        }
        $query = $request->queryParams();

        return array_keys($query) === ['inquiry']
            && is_string($query['inquiry'])
            && preg_match(self::UUID, $query['inquiry']) === 1;
    }

    public function acceptsCreate(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'sku', 'price', 'currency', 'locale', 'title', 'slug',
            'summary', 'description',
        ]) && $this->validProductFields($request);
    }

    public function acceptsSave(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'product', 'lock_version', 'sku', 'price', 'currency',
            'locale', 'title', 'slug', 'summary', 'description', 'status',
            'availability',
        ])
            && preg_match(self::UUID, (string) $request->form('product')) === 1
            && $this->positiveInteger((string) $request->form('lock_version'))
            && in_array(
                $request->form('status'),
                ['draft', 'active', 'inactive', 'archived'],
                true
            )
            && in_array(
                $request->form('availability'),
                ['available', 'reserved', 'sold', 'unavailable'],
                true
            )
            && $this->validProductFields($request);
    }

    public function acceptsCategoryCreate(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'name', 'slug', 'parent',
        ])
            && $this->text($request->form('name'), 180, false)
            && $this->slug($request->form('slug'))
            && ($request->form('parent') === ''
                || preg_match(self::UUID, (string) $request->form('parent')) === 1);
    }

    public function acceptsTagCreate(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'name', 'slug',
        ])
            && $this->text($request->form('name'), 180, false)
            && $this->slug($request->form('slug'));
    }

    public function acceptsCategoryLocalizationSave(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'category', 'locale', 'name', 'slug', 'lock_version',
        ])
            && preg_match(self::UUID, (string) $request->form('category')) === 1
            && preg_match(self::LOCALE, (string) $request->form('locale')) === 1
            && $this->text($request->form('name'), 180, false)
            && $this->slug($request->form('slug'))
            && $this->positiveInteger((string) $request->form('lock_version'));
    }

    public function acceptsCategoryParentSave(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'category', 'parent', 'lock_version',
        ])
            && preg_match(self::UUID, (string) $request->form('category')) === 1
            && ($request->form('parent') === ''
                || preg_match(self::UUID, (string) $request->form('parent')) === 1)
            && $this->positiveInteger((string) $request->form('lock_version'));
    }

    public function acceptsCategoryOrderSave(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'category', 'sort_order', 'lock_version',
        ])
            && preg_match(self::UUID, (string) $request->form('category')) === 1
            && $this->nonNegativeInteger((string) $request->form('sort_order'), 10_000)
            && $this->positiveInteger((string) $request->form('lock_version'));
    }

    public function acceptsTagLocalizationSave(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'tag', 'locale', 'name', 'slug',
        ])
            && preg_match(self::UUID, (string) $request->form('tag')) === 1
            && preg_match(self::LOCALE, (string) $request->form('locale')) === 1
            && $this->text($request->form('name'), 180, false)
            && $this->slug($request->form('slug'));
    }

    public function acceptsProductTaxonomiesSave(Request $request): bool
    {
        if (!$this->acceptsComplexForm($request, ['csrf', 'product', 'canonical'], [
            'categories', 'tags',
        ])) {
            return false;
        }
        $categories = $request->form('categories', []);
        $tags = $request->form('tags', []);
        $canonical = $request->form('canonical');

        return preg_match(self::UUID, (string) $request->form('product')) === 1
            && is_string($canonical)
            && preg_match(self::UUID, $canonical) === 1
            && $this->publicIdList($categories, 100, false)
            && in_array($canonical, $categories, true)
            && $this->publicIdList($tags, 50, true);
    }

    public function acceptsAttributeCreate(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'code', 'type', 'category', 'unit', 'filterable',
            'sort_order', 'name',
        ])
            && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', (string) $request->form('code')) === 1
            && in_array($request->form('type'), [
                'text', 'number', 'boolean', 'select', 'multiselect', 'date',
            ], true)
            && ($request->form('category') === ''
                || preg_match(self::UUID, (string) $request->form('category')) === 1)
            && $this->text($request->form('unit'), 32, true)
            && in_array($request->form('filterable'), ['0', '1'], true)
            && $this->nonNegativeInteger((string) $request->form('sort_order'), 10_000)
            && $this->text($request->form('name'), 180, false);
    }

    public function acceptsAttributeOptionCreate(Request $request): bool
    {
        if (!$this->acceptsComplexForm($request, [
            'csrf', 'attribute', 'code', 'sort_order', 'labels',
        ], [])) {
            return false;
        }
        $labels = $request->form('labels');
        if (!is_array($labels) || $labels === [] || count($labels) > 32) {
            return false;
        }
        foreach ($labels as $locale => $label) {
            if (!is_string($locale)
                || preg_match(self::LOCALE, $locale) !== 1
                || !$this->text($label, 180, true)
            ) {
                return false;
            }
        }

        return preg_match(self::UUID, (string) $request->form('attribute')) === 1
            && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', (string) $request->form('code')) === 1
            && $this->nonNegativeInteger((string) $request->form('sort_order'), 10_000);
    }

    public function acceptsAttributeValueSave(Request $request): bool
    {
        if (!$this->acceptsComplexForm($request, [
            'csrf', 'product', 'attribute', 'locale', 'value',
        ], [])) {
            return false;
        }
        $value = $request->form('value');
        if (is_array($value)) {
            if (!$this->publicIdList($value, 50, false)) {
                return false;
            }
        } elseif (!is_string($value) || strlen($value) > 4_000 || preg_match('//u', $value) !== 1) {
            return false;
        }

        return preg_match(self::UUID, (string) $request->form('product')) === 1
            && preg_match(self::UUID, (string) $request->form('attribute')) === 1
            && preg_match(self::LOCALE, (string) $request->form('locale')) === 1;
    }

    public function acceptsProductMediaSave(Request $request): bool
    {
        if (!$this->acceptsComplexForm($request, [
            'csrf', 'product', 'lock_version', 'locale', 'roles', 'alts', 'captions',
        ], [])) {
            return false;
        }
        $roles = $request->form('roles');
        $alts = $request->form('alts');
        $captions = $request->form('captions');
        if (!is_array($roles) || $roles === [] || count($roles) > 200) {
            return false;
        }
        $selected = [];
        $covers = 0;
        foreach ($roles as $publicId => $role) {
            if (!is_string($publicId)
                || preg_match(self::UUID, $publicId) !== 1
                || !is_string($role)
                || !in_array($role, ['none', 'cover', 'gallery'], true)
            ) {
                return false;
            }
            if ($role !== 'none') {
                $selected[] = $publicId;
            }
            if ($role === 'cover') {
                ++$covers;
            }
        }

        return preg_match(self::UUID, (string) $request->form('product')) === 1
            && $this->positiveInteger((string) $request->form('lock_version'))
            && preg_match(self::LOCALE, (string) $request->form('locale')) === 1
            && $covers <= 1
            && count($selected) <= 51
            && $this->mediaTextMap($alts, 500, true)
            && $this->mediaTextMap($captions, 2_000, true)
            && array_diff($selected, array_keys($alts)) === []
            && array_diff($selected, array_keys($captions)) === []
            && $this->selectedMediaAltsPresent($selected, $alts);
    }

    public function acceptsProductMediaLocalizationSave(Request $request): bool
    {
        return $this->webAdmin->acceptsFormPost($request, [
            'csrf', 'product', 'media', 'locale', 'alt_text', 'caption',
        ])
            && preg_match(self::UUID, (string) $request->form('product')) === 1
            && preg_match(self::UUID, (string) $request->form('media')) === 1
            && preg_match(self::LOCALE, (string) $request->form('locale')) === 1
            && $this->text($request->form('alt_text'), 500, false)
            && $this->text($request->form('caption'), 2_000, true);
    }

    private function validProductFields(Request $request): bool
    {
        return preg_match(self::LOCALE, (string) $request->form('locale')) === 1
            && $this->text($request->form('sku'), 100, true)
            && $this->price($request->form('price'))
            && preg_match('/\A[A-Z]{3}\z/', (string) $request->form('currency')) === 1
            && $this->text($request->form('title'), 240, false)
            && $this->slug($request->form('slug'))
            && $this->text($request->form('summary'), 2_000, true)
            && $this->text($request->form('description'), 200_000, true);
    }

    private function price(mixed $value): bool
    {
        return is_string($value)
            && ($value === '' || preg_match('/\A(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,2})?\z/', $value) === 1);
    }

    private function slug(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 180
            && preg_match(self::SLUG, $value) === 1;
    }

    private function text(mixed $value, int $limit, bool $empty): bool
    {
        return is_string($value)
            && strlen($value) <= $limit
            && preg_match('//u', $value) === 1
            && ($empty || trim($value) !== '');
    }

    private function positiveInteger(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1
            && (string) (int) $value === $value;
    }

    private function nonNegativeInteger(string $value, int $max): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/', $value) === 1
            && (int) $value <= $max;
    }

    /** @param list<string> $required @param list<string> $optional */
    private function acceptsComplexForm(
        Request $request,
        array $required,
        array $optional
    ): bool {
        if (!$request->isValid()
            || $request->method() !== 'POST'
            || $request->queryParams() !== []
            || strtolower(trim((string) strtok(
                $request->header('content-type', ''),
                ';'
            ))) !== 'application/x-www-form-urlencoded'
        ) {
            return false;
        }
        $form = $request->formParams();
        foreach ($required as $key) {
            if (!array_key_exists($key, $form)) {
                return false;
            }
        }
        $allowed = array_fill_keys([...$required, ...$optional], true);
        foreach (array_keys($form) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                return false;
            }
        }
        foreach ($required as $key) {
            if (in_array($key, ['labels', 'value', 'roles', 'alts', 'captions'], true)) {
                continue;
            }
            if (!is_string($form[$key])) {
                return false;
            }
        }

        return true;
    }

    private function publicIdList(mixed $value, int $max, bool $allowEmpty): bool
    {
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) > $max
            || (!$allowEmpty && $value === [])
        ) {
            return false;
        }
        $seen = [];
        foreach ($value as $publicId) {
            if (!is_string($publicId)
                || preg_match(self::UUID, $publicId) !== 1
                || isset($seen[$publicId])
            ) {
                return false;
            }
            $seen[$publicId] = true;
        }

        return true;
    }

    private function mediaTextMap(mixed $value, int $maxBytes, bool $empty): bool
    {
        if (!is_array($value) || $value === [] || count($value) > 200) {
            return false;
        }
        foreach ($value as $publicId => $text) {
            if (!is_string($publicId)
                || preg_match(self::UUID, $publicId) !== 1
                || !$this->text($text, $maxBytes, $empty)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $selected @param array<string, mixed> $alts */
    private function selectedMediaAltsPresent(array $selected, array $alts): bool
    {
        foreach ($selected as $publicId) {
            if (!is_string($alts[$publicId] ?? null)
                || trim($alts[$publicId]) === ''
            ) {
                return false;
            }
        }

        return true;
    }
}
