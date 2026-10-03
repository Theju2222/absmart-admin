<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Language;
use App\Models\Store;
use App\Models\TaxCategory;
use Illuminate\Support\Collection;

/**
 * Column schema for product bulk import/export.
 *
 * The sheet's columns are NOT fixed: they depend on the chosen category (which
 * decides the attribute + custom-field columns) and the chosen stores (which decide
 * the per-store pricing column groups). Pricing lives in product_variant_store_stocks
 * keyed (product_variant_id, store_id) — there is no global price — so a store
 * dimension is unavoidable.
 *
 * This class is the SINGLE source of truth for those columns. The sample writer, the
 * export writer and the import parser all build from it, so a column can never mean
 * one thing on the way out and another on the way in.
 *
 * Sheet shape: one row per VARIANT. Variants of the same product are grouped by the
 * `handle` column; product-level values are read from a group's first row.
 *
 * A column def is:
 *   key      machine key used by the parser
 *   label    header text shown in the sheet
 *   group    product|variant|attribute|custom|store  (drives parsing)
 *   type     text|number|decimal|dropdown|date
 *   options  allowed values for dropdown columns (drives sheet data-validation)
 *   required whether a value must be present (create only)
 *   meta     group-specific payload (attribute_id, field_id, store_id, field key, lang id)
 */
class ProductBulkSchema
{
    /** Product-level pick lists that are the same for every sheet. */
    public const PRODUCT_TYPES = [
        'none' => 0, 'veg' => 1, 'non_veg' => 2, 'chemical' => 3, 'eggetarian' => 4, 'medical' => 5,
    ];

    /** Translatable product fields the product form exposes per language. */
    public const TRANSLATABLE_PRODUCT_FIELDS = [
        'name', 'manufacturer', 'made_in', 'short_description', 'description',
        'meta_title', 'meta_keywords', 'meta_description',
    ];

    /** Translatable product fields the form keeps single-language (default only). */
    public const SINGLE_LANG_PRODUCT_FIELDS = ['tags', 'schema_markup'];

    /** Per-store pricing/stock columns, in sheet order. */
    public const STORE_FIELDS = [
        'is_listed'        => ['label' => 'Listed',           'type' => 'dropdown'],
        'price'            => ['label' => 'Price',            'type' => 'decimal'],
        'discounted_price' => ['label' => 'Discounted Price', 'type' => 'decimal'],
        'purchase_price'   => ['label' => 'Purchase Price',   'type' => 'decimal'],
        'stock_status'     => ['label' => 'Stock Status',     'type' => 'dropdown'],
        'available'        => ['label' => 'Available Qty',    'type' => 'number'],
        'min_alert'        => ['label' => 'Min Alert',        'type' => 'number'],
        'is_unlimited_stock' => ['label' => 'Unlimited Stock', 'type' => 'dropdown'],
    ];

    public Category $category;
    /** @var Collection<int,Store> */
    public Collection $stores;
    /** @var Collection<int,Language> */
    public Collection $languages;
    public ?Language $defaultLanguage;
    public bool $forUpdate;

    public function __construct(Category $category, Collection $stores, bool $forUpdate = false)
    {
        $this->category  = $category;
        $this->stores    = $stores;
        $this->forUpdate = $forUpdate;

        // Admin-panel languages drive the per-language column sets.
        $this->languages = Language::where('system_type', Language::$systemTypeAdminPanel)
            ->where('status', 1)
            ->leftJoin('supported_languages as sl', 'sl.id', '=', 'languages.supported_language_id')
            ->orderByDesc('languages.is_default')
            ->get(['languages.id', 'languages.is_default', 'sl.name as name']);

        $this->defaultLanguage = $this->languages->firstWhere('is_default', 1) ?? $this->languages->first();
    }

    public static function make(int $categoryId, array $storeIds, bool $forUpdate = false): self
    {
        $category = Category::findOrFail($categoryId);
        $stores = Store::whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']);

        return new self($category, $stores, $forUpdate);
    }

    /** Yes/No pick list used for every boolean column. */
    public static function boolOptions(): array
    {
        return ['Yes', 'No'];
    }

    public static function parseBool($value, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $v = strtolower(trim((string) $value));
        if (in_array($v, ['yes', 'y', '1', 'true', 'in stock', 'listed', 'active'], true)) {
            return 1;
        }
        if (in_array($v, ['no', 'n', '0', 'false', 'out of stock', 'not listed', 'inactive'], true)) {
            return 0;
        }
        return $default;
    }

    /**
     * The full ordered column list for this (category, stores, languages) selection.
     *
     * @return array<int,array>
     */
    public function columns(): array
    {
        $cols = [];

        // --- identity -------------------------------------------------------
        if ($this->forUpdate) {
            // Export/update keys. product_id + variant_id are what we match on; they
            // are never edited, so the sheet carries them read-only.
            $cols[] = $this->col('product_id', 'Product ID', 'key', 'number', [], true);
            $cols[] = $this->col('variant_id', 'Variant ID', 'key', 'number', [], true);
        } else {
            // Groups variant rows into one product. Any stable text works.
            $cols[] = $this->col('handle', 'Product Handle*', 'key', 'text', [], true);
        }

        // --- variant identity ----------------------------------------------
        foreach ($this->languages as $lang) {
            $required = !$this->forUpdate && (int) $lang->is_default === 1;
            $cols[] = $this->col(
                'variant_name.' . $lang->id,
                'Variant Name (' . $lang->name . ')' . ($required ? '*' : ''),
                'variant_translation',
                'text',
                [],
                $required,
                ['field' => 'name', 'language_id' => (int) $lang->id]
            );
        }
        $cols[] = $this->col('sku', 'SKU*', 'variant', 'text', [], !$this->forUpdate);
        $cols[] = $this->col('hsn_code', 'HSN Code', 'variant', 'text');

        // --- attributes: one column per category attribute -------------------
        // A variant is exactly one point in the attribute space (unique
        // (product_variant_id, attribute_id)), so one column each is a faithful mapping.
        foreach ($this->attributes() as $attr) {
            $cols[] = $this->col(
                'attr.' . $attr->id,
                $attr->name . '*',
                'attribute',
                'dropdown',
                $attr->values->pluck('value')->filter()->values()->all(),
                !$this->forUpdate,
                ['attribute_id' => (int) $attr->id]
            );
        }

        // --- images ---------------------------------------------------------
        // Variant image is required on create; the gallery is always optional.
        $imageRequired = !$this->forUpdate;
        $cols[] = $this->col('image', 'Variant Image (path or URL)' . ($imageRequired ? '*' : ''), 'variant', 'text', [], $imageRequired);
        $cols[] = $this->col('gallery', 'Gallery Images (comma separated)', 'variant', 'text');

        // --- product-level ---------------------------------------------------
        $cols[] = $this->col('brand', 'Brand', 'product', 'dropdown', $this->brandOptions());
        $cols[] = $this->col('tax', 'Tax Category', 'product', 'dropdown', $this->taxOptions());
        $cols[] = $this->col('product_type', 'Product Type', 'product', 'dropdown', array_keys(self::PRODUCT_TYPES));
        $cols[] = $this->col('status', 'Status', 'product', 'dropdown', ['Active', 'Inactive']);
        $cols[] = $this->col('total_allowed_quantity', 'Max Qty Per Order', 'product', 'number');
        $cols[] = $this->col('cod_allowed', 'COD Allowed', 'product', 'dropdown', self::boolOptions());
        $cols[] = $this->col('return_status', 'Returnable', 'product', 'dropdown', self::boolOptions());
        $cols[] = $this->col('return_days', 'Return Days', 'product', 'number');
        $cols[] = $this->col('cancelable_status', 'Cancellable', 'product', 'dropdown', self::boolOptions());
        $cols[] = $this->col('till_status_quick', 'Cancellable Till (Quick)', 'product', 'text');
        $cols[] = $this->col('till_status_ecommerce', 'Cancellable Till (Ecommerce)', 'product', 'text');
        $cols[] = $this->col('is_prescription_required', 'Prescription Required', 'product', 'dropdown', ['0', '1']);

        // Single-language product fields (the form does not translate these).
        foreach (self::SINGLE_LANG_PRODUCT_FIELDS as $field) {
            $cols[] = $this->col(
                'ptrans.' . $field . '.' . ($this->defaultLanguage->id ?? 0),
                ucwords(str_replace('_', ' ', $field)),
                'product_translation',
                'text',
                [],
                false,
                ['field' => $field, 'language_id' => (int) ($this->defaultLanguage->id ?? 0)]
            );
        }

        // Per-language product fields.
        foreach (self::TRANSLATABLE_PRODUCT_FIELDS as $field) {
            foreach ($this->languages as $lang) {
                $cols[] = $this->col(
                    'ptrans.' . $field . '.' . $lang->id,
                    ucwords(str_replace('_', ' ', $field)) . ' (' . $lang->name . ')',
                    'product_translation',
                    'text',
                    [],
                    false,
                    ['field' => $field, 'language_id' => (int) $lang->id]
                );
            }
        }

        // --- custom fields (per variant, from the category's sections) --------
        foreach ($this->customFields() as $field) {
            $cols[] = $this->col(
                'custom.' . $field->id,
                $field->field_label,
                'custom',
                $this->customFieldType($field),
                $this->customFieldOptions($field),
                false,
                ['field_id' => (int) $field->id, 'field_type' => $field->field_type]
            );
        }

        // --- per-store pricing groups ----------------------------------------
        foreach ($this->stores as $store) {
            foreach (self::STORE_FIELDS as $key => $def) {
                $options = [];
                if ($key === 'is_listed' || $key === 'is_unlimited_stock') {
                    $options = self::boolOptions();
                } elseif ($key === 'stock_status') {
                    $options = ['In Stock', 'Out Of Stock'];
                }
                $cols[] = $this->col(
                    'store.' . $store->id . '.' . $key,
                    '[' . $store->name . '] ' . $def['label'],
                    'store',
                    $def['type'],
                    $options,
                    false,
                    ['store_id' => (int) $store->id, 'field' => $key]
                );
            }
        }

        return $cols;
    }

    /** @return Collection */
    public function attributes(): Collection
    {
        return $this->category->effectiveAttributes();
    }

    /** Flattened custom fields across the category's effective sections. */
    public function customFields(): Collection
    {
        $fields = collect();
        foreach ($this->category->effectiveCustomSections() as $section) {
            foreach ($section->fields as $field) {
                $fields->push($field);
            }
        }
        return $fields;
    }

    private function customFieldType($field): string
    {
        return match ($field->field_type) {
            'number'   => 'number',
            'date'     => 'date',
            'dropdown', 'checkbox', 'boolean' => 'dropdown',
            default    => 'text',
        };
    }

    private function customFieldOptions($field): array
    {
        if ($field->field_type === 'boolean') {
            return self::boolOptions();
        }
        if (in_array($field->field_type, ['dropdown', 'checkbox'], true)) {
            $opts = $field->options;
            if (is_string($opts)) {
                $opts = json_decode($opts, true);
            }
            return is_array($opts) ? array_values(array_filter(array_map('strval', $opts))) : [];
        }
        return [];
    }

    public function brandOptions(): array
    {
        return Brand::where('status', 1)->orderBy('name')->pluck('name')->filter()->values()->all();
    }

    public function taxOptions(): array
    {
        return TaxCategory::where('status', 1)->orderBy('name')->pluck('name')->values()->all();
    }

    /** Resolve a Brand name back to its id. */
    public function brandId(?string $name): int
    {
        if (!$name) {
            return 0;
        }
        return (int) (Brand::where('name', trim($name))->value('id') ?? 0);
    }

    public function taxCategoryId(?string $label): ?int
    {
        if (!$label) {
            return null;
        }
        $name = trim(preg_replace('/\s*\([^)]*%\)\s*$/', '', $label));
        $id = TaxCategory::where('name', $name)->value('id')
            ?? TaxCategory::where('code', strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $name)))->value('id');

        return $id ? (int) $id : null;
    }

    private function col(
        string $key,
        string $label,
        string $group,
        string $type = 'text',
        array $options = [],
        bool $required = false,
        array $meta = []
    ): array {
        return compact('key', 'label', 'group', 'type', 'options', 'required', 'meta');
    }
}
