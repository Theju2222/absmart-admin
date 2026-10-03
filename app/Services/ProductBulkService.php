<?php

namespace App\Services;

use App\Helpers\ProductHelper;
use App\Http\Controllers\API\ProductApisController;
use App\Models\Product;
use App\Models\ProductImages;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\ProductVariantCustomValue;
use App\Models\ProductVariantStoreStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Product bulk import/export.
 *
 * Two flows, deliberately asymmetric:
 *
 *  CREATE (upload) — builds the exact payload the product form posts and hands it to
 *  ProductApisController@save. The importer therefore inherits the real writer's
 *  behaviour (slug derivation, translation mirroring, PVSS sync, SKU checks) instead
 *  of duplicating it and drifting away from it — which is how the old importer ended
 *  up writing columns that no longer existed.
 *
 *  UPDATE — a targeted MERGE. It never calls the form's sync helpers, because those
 *  treat the payload as the complete truth and hard-delete any variant or store price
 *  absent from it. A partial sheet ("re-price store 3") must not destroy store 5's
 *  pricing or the variants that weren't exported, so update only touches the cells
 *  actually present in the file.
 */
class ProductBulkService
{
    /** Hard cap so one file can't exhaust memory/execution time (imports are synchronous). */
    public const MAX_ROWS = 2000;

    /* ===================================================================== */
    /* Sample + export                                                        */
    /* ===================================================================== */

    /** Blank template whose columns match the chosen category + stores. */
    public function sample(ProductBulkSchema $schema): string
    {
        $columns = $schema->columns();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $this->writeHeader($sheet, $columns);
        $this->applyValidation($sheet, $columns, 2, 200);

        // One worked example row so the grouping rule is self-evident: two variants
        // sharing a handle = one product with two variants.
        $this->writeExampleRows($sheet, $columns, $schema);

        $this->addNotesSheet($spreadsheet, $schema);

        return $this->save($spreadsheet);
    }

    /** Existing products for the chosen category + stores, ready to edit and re-upload. */
    public function export(ProductBulkSchema $schema): string
    {
        $columns = $schema->columns();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $this->writeHeader($sheet, $columns);

        $storeIds = $schema->stores->pluck('id')->all();
        $products = Product::where('category_id', $schema->category->id)
            ->with([
                'variants.storeStocks' => fn ($q) => $q->whereIn('store_id', $storeIds),
                'variants.attributeValues',
                'variants.customValues',
                'variants.translations',
                'variants.images',
                'translations',
                'brand',
                'taxCategory',
            ])
            ->orderBy('id')
            ->get();

        $row = 2;
        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $this->writeExportRow($sheet, $columns, $row, $product, $variant, $schema);
                $row++;
            }
        }

        $this->applyValidation($sheet, $columns, 2, max($row - 1, 2));
        $this->addNotesSheet($spreadsheet, $schema);

        return $this->save($spreadsheet);
    }

    private function writeHeader($sheet, array $columns): void
    {
        foreach ($columns as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($letter . '1', $col['label']);
            $sheet->getColumnDimension($letter)->setWidth(max(14, min(38, strlen($col['label']) + 4)));
        }
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $style = $sheet->getStyle('A1:' . $last . '1');
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF7');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');
    }

    /**
     * Range-based data validation. The old importer looped every cell of every
     * dropdown column for 999 rows, which was a real memory sink; one validation
     * object per column range does the same job.
     */
    private function applyValidation($sheet, array $columns, int $firstRow, int $lastRow): void
    {
        foreach ($columns as $i => $col) {
            if ($col['type'] !== 'dropdown' || empty($col['options'])) {
                continue;
            }
            // Excel caps an inline list at 255 chars; longer lists are left free-text
            // rather than silently truncated.
            $list = '"' . implode(',', array_map(fn ($o) => str_replace(',', ' ', (string) $o), $col['options'])) . '"';
            if (strlen($list) > 255) {
                continue;
            }
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $validation = $sheet->getCell($letter . $firstRow)->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setShowDropDown(true)
                ->setErrorTitle(__('invalid_value'))
                ->setError(__('please_pick_a_value_from_the_list'))
                ->setFormula1($list);
            $sheet->setDataValidation($letter . $firstRow . ':' . $letter . $lastRow, clone $validation);
        }
    }

    private function writeExampleRows($sheet, array $columns, ProductBulkSchema $schema): void
    {
        $attrs = $schema->attributes();
        if ($attrs->isEmpty()) {
            return;
        }
        // Two rows sharing one handle => one product, two variants.
        for ($n = 0; $n < 2; $n++) {
            $rowNo = $n + 2;
            foreach ($columns as $i => $col) {
                $letter = Coordinate::stringFromColumnIndex($i + 1);
                $value = match (true) {
                    $col['key'] === 'handle' => 'sample-product-1',
                    $col['key'] === 'sku' => 'SAMPLE-SKU-' . ($n + 1),
                    str_starts_with($col['key'], 'variant_name.') => 'Sample Variant ' . ($n + 1),
                    $col['group'] === 'attribute' => (string) ($col['options'][$n] ?? ($col['options'][0] ?? '')),
                    str_ends_with($col['key'], '.is_listed') => 'Yes',
                    str_ends_with($col['key'], '.price') => '100',
                    str_ends_with($col['key'], '.discounted_price') => '90',
                    str_ends_with($col['key'], '.purchase_price') => '70',
                    str_ends_with($col['key'], '.stock_status') => 'In Stock',
                    str_ends_with($col['key'], '.available') => '10',
                    default => '',
                };
                if ($value !== '') {
                    $sheet->setCellValueExplicit($letter . $rowNo, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle('A' . $rowNo . ':' . Coordinate::stringFromColumnIndex(count($columns)) . $rowNo)
                ->getFont()->getColor()->setRGB('9AA4B2');
        }
    }

    /** Human-readable rules, shipped inside the workbook instead of a separate .txt. */
    private function addNotesSheet(Spreadsheet $spreadsheet, ProductBulkSchema $schema): void
    {
        $notes = $spreadsheet->createSheet();
        $notes->setTitle('Instructions');
        $lines = [
            [__('product_bulk_instructions')],
            [''],
            ['1. ' . __('bulk_note_one_row_per_variant')],
            ['2. ' . __('bulk_note_handle_groups_variants')],
            ['3. ' . __('bulk_note_columns_match_selection')],
            ['4. ' . __('bulk_note_sku_unique')],
            ['5. ' . __('bulk_note_listed_needs_price')],
            ['6. ' . __('bulk_note_discount_lte_price')],
            ['7. ' . __('bulk_note_attributes_unique_combo')],
            ['8. ' . __('bulk_note_images_path_or_url')],
            ['9. ' . __('bulk_note_update_is_merge')],
            ['10. ' . __('bulk_note_grey_rows_are_examples')],
            [''],
            [__('category') . ': ' . $schema->category->name],
            [__('stores') . ': ' . $schema->stores->pluck('name')->implode(', ')],
            [__('max_rows') . ': ' . self::MAX_ROWS],
        ];
        $notes->fromArray($lines, null, 'A1');
        $notes->getColumnDimension('A')->setWidth(110);
        $notes->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $notes->getStyle('A1:A20')->getAlignment()->setWrapText(true);
    }

    private function writeExportRow($sheet, array $columns, int $row, Product $product, ProductVariant $variant, ProductBulkSchema $schema): void
    {
        $attrByAttrId = $variant->attributeValues->keyBy('attribute_id');
        $customByField = $variant->customValues->keyBy('category_custom_field_id');
        $stockByStore = $variant->storeStocks->keyBy('store_id');

        foreach ($columns as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $value = $this->exportValue($col, $product, $variant, $schema, $attrByAttrId, $customByField, $stockByStore);
            if ($value === null || $value === '') {
                continue;
            }
            // Force text so SKUs/HSNs like "0012" keep their leading zeros.
            $sheet->setCellValueExplicit($letter . $row, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    private function exportValue(array $col, Product $product, ProductVariant $variant, ProductBulkSchema $schema, $attrByAttrId, $customByField, $stockByStore)
    {
        $meta = $col['meta'];

        switch ($col['group']) {
            case 'key':
                return $col['key'] === 'product_id' ? $product->id : $variant->id;

            case 'variant':
                return match ($col['key']) {
                    'sku' => $variant->sku,
                    'hsn_code' => $variant->hsn_code,
                    'image' => $variant->getRawOriginal('image'),
                    // Gallery = the variant's product_images rows, comma-separated.
                    'gallery' => $variant->images->pluck('image')->filter()->implode(', '),
                    default => null,
                };

            case 'variant_translation':
                return $this->translatedField($variant, 'name', (int) $meta['language_id']);

            case 'attribute':
                $row = $attrByAttrId->get($meta['attribute_id']);
                return $row?->attributeValue?->value;

            case 'custom':
                $cv = $customByField->get($meta['field_id']);
                if (!$cv) {
                    return null;
                }
                return match ($meta['field_type']) {
                    'number' => $cv->value_number,
                    'date' => $cv->value_date ?: null,
                    'boolean' => $cv->value_text === null || $cv->value_text === '' ? null : ((int) $cv->value_text === 1 ? 'Yes' : 'No'),
                    'dropdown', 'checkbox' => is_array($cv->value_json) ? implode(', ', $cv->value_json) : $cv->value_text,
                    default => $cv->value_text,
                };

            case 'product':
                return $this->exportProductValue($col['key'], $product, $schema);

            case 'product_translation':
                return $this->translatedField($product, $meta['field'], (int) $meta['language_id']);

            case 'store':
                $stock = $stockByStore->get($meta['store_id']);
                if (!$stock) {
                    return null;
                }
                return match ($meta['field']) {
                    'is_listed' => ((int) $stock->is_listed === 1 ? 'Yes' : 'No'),
                    'is_unlimited_stock' => ((int) $stock->is_unlimited_stock === 1 ? 'Yes' : 'No'),
                    'stock_status' => ((int) $stock->stock_status === 1 ? 'In Stock' : 'Out Of Stock'),
                    'price' => $stock->price,
                    'discounted_price' => $stock->discounted_price,
                    'purchase_price' => $stock->purchase_price,
                    'available' => $stock->available,
                    'min_alert' => $stock->min_alert,
                    default => null,
                };
        }

        return null;
    }

    /**
     * The exact stored translation for one language — no fallback.
     *
     * HasTranslations::getTranslationsAttribute() turns `$model->translations` into a
     * plain array, and getTranslatedAttribute() falls back to the default language.
     * Either would be wrong here: a fallback would print the default language's text
     * in another language's column, and re-uploading that sheet would persist it as a
     * real translation. An empty cell must stay empty.
     */
    private function translatedField($model, string $field, int $languageId)
    {
        if ($model->relationLoaded('translations')) {
            return $model->getRelation('translations')->firstWhere('language_id', $languageId)?->{$field};
        }
        return $model->translation($languageId)?->{$field};
    }

    private function exportProductValue(string $key, Product $product, ProductBulkSchema $schema)
    {
        switch ($key) {
            case 'brand':
                return $product->brand?->name;
            case 'tax':
                return $product->taxCategory?->name;
            case 'product_type':
                return array_search((int) $product->product_type, ProductBulkSchema::PRODUCT_TYPES, true) ?: null;
            case 'status':
                return (int) $product->status === 1 ? 'Active' : 'Inactive';
            case 'cod_allowed':
            case 'return_status':
            case 'cancelable_status':
                return (int) $product->{$key} === 1 ? 'Yes' : 'No';
            default:
                return $product->{$key};
        }
    }

    private function save(Spreadsheet $spreadsheet): string
    {
        $spreadsheet->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'products_bulk_');
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        return $tmp;
    }

    /* ===================================================================== */
    /* Reading                                                                */
    /* ===================================================================== */

    /**
     * Read the uploaded sheet into assoc rows keyed by column key.
     * Matching is positional-by-header-label so a reordered sheet still imports.
     *
     * @return array{rows: array, errors: array}
     */
    public function read(string $path, array $columns): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $raw = $sheet->toArray(null, true, false, false);

        if (empty($raw)) {
            return ['rows' => [], 'errors' => [__('the_file_is_empty')]];
        }

        $header = array_map(fn ($h) => $this->normalizeHeader($h), array_shift($raw));
        $byLabel = [];
        foreach ($columns as $col) {
            $byLabel[$this->normalizeHeader($col['label'])] = $col['key'];
        }

        // Map each sheet column index -> our column key.
        $indexToKey = [];
        foreach ($header as $idx => $label) {
            if ($label !== '' && isset($byLabel[$label])) {
                $indexToKey[$idx] = $byLabel[$label];
            }
        }

        $errors = [];
        $missing = array_diff(array_keys($byLabel), array_values(array_map(fn ($l) => $l, $header)));
        // Only required columns must be present; optional ones may be dropped.
        foreach ($columns as $col) {
            if ($col['required'] && !in_array($col['key'], $indexToKey, true)) {
                $errors[] = __('missing_required_column') . ': ' . $col['label'];
            }
        }
        if ($errors) {
            return ['rows' => [], 'errors' => $errors];
        }

        $rows = [];
        foreach ($raw as $n => $line) {
            $assoc = [];
            foreach ($indexToKey as $idx => $key) {
                $v = $line[$idx] ?? null;
                $assoc[$key] = is_string($v) ? trim($v) : $v;
            }
            // Skip fully blank lines and the greyed example rows we ship in the sample.
            if (!array_filter($assoc, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            if (($assoc['handle'] ?? null) === 'sample-product-1' && str_starts_with((string) ($assoc['sku'] ?? ''), 'SAMPLE-SKU-')) {
                continue;
            }
            $assoc['__row'] = $n + 2; // human-facing sheet row number
            $rows[] = $assoc;
        }

        if (count($rows) > self::MAX_ROWS) {
            return ['rows' => [], 'errors' => [__('too_many_rows_max') . ': ' . self::MAX_ROWS]];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    private function normalizeHeader($h): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $h)));
    }

    /* ===================================================================== */
    /* Create                                                                 */
    /* ===================================================================== */

    /**
     * Validate every row up front, then write. Nothing is written unless the whole
     * file is clean, so a user never ends up with half a catalogue imported.
     *
     * @return array{errors: array, created: int}
     */
    public function validateForCreate(array $rows, ProductBulkSchema $schema): array
    {
        $errors = [];
        $seenSku = [];
        $seenCombo = [];
        $attributes = $schema->attributes();

        foreach ($rows as $row) {
            $r = $row['__row'];
            $handle = $row['handle'] ?? '';
            $sku = $row['sku'] ?? '';

            if ($handle === '') {
                $errors[] = ['row' => $r, 'error' => __('product_handle_is_required')];
            }
            if ($sku === '') {
                $errors[] = ['row' => $r, 'error' => __('sku_is_required')];
            } else {
                $k = mb_strtolower($sku);
                if (isset($seenSku[$k])) {
                    $errors[] = ['row' => $r, 'error' => __('duplicate_sku_in_file') . ': ' . $sku . ' (' . __('row') . ' ' . $seenSku[$k] . ')'];
                } else {
                    $seenSku[$k] = $r;
                    if (ProductVariant::where('sku', $sku)->exists()) {
                        $errors[] = ['row' => $r, 'error' => __('sku_already_used') . ': ' . $sku];
                    }
                }
            }

            // Default-language variant name is what the product's slug is derived from.
            $nameKey = 'variant_name.' . ($schema->defaultLanguage->id ?? 0);
            if (empty($row[$nameKey])) {
                $errors[] = ['row' => $r, 'error' => __('variant_name_required_in_default_language')];
            }

            // On create every variant needs its own image; the gallery stays optional.
            if (trim((string) ($row['image'] ?? '')) === '') {
                $errors[] = ['row' => $r, 'error' => __('variant_image_is_required')];
            }

            // Every attribute must be answered, and the combination must be unique
            // inside the product — that is what makes a variant addressable.
            $combo = [];
            foreach ($attributes as $attr) {
                $val = $row['attr.' . $attr->id] ?? '';
                if ($val === '' || $val === null) {
                    $errors[] = ['row' => $r, 'error' => __('attribute_value_required') . ': ' . $attr->name];
                    continue;
                }
                $match = $attr->values->first(fn ($v) => mb_strtolower((string) $v->value) === mb_strtolower((string) $val));
                if (!$match) {
                    $errors[] = ['row' => $r, 'error' => __('invalid_value_for') . ' ' . $attr->name . ': ' . $val];
                    continue;
                }
                $combo[] = $attr->id . ':' . $match->id;
            }
            if ($combo && $handle !== '') {
                sort($combo);
                $ck = $handle . '|' . implode(',', $combo);
                if (isset($seenCombo[$ck])) {
                    $errors[] = ['row' => $r, 'error' => __('duplicate_variant_combination_for_handle') . ' (' . __('row') . ' ' . $seenCombo[$ck] . ')'];
                } else {
                    $seenCombo[$ck] = $r;
                }
            }

            $errors = array_merge($errors, $this->validateStoreCells($row, $schema, $r));
            $errors = array_merge($errors, $this->validateImages($row, $r));
        }

        // A product must be listed somewhere, or it is invisible to every customer.
        foreach ($this->groupByHandle($rows) as $handle => $group) {
            $anyListed = false;
            foreach ($group as $row) {
                foreach ($schema->stores as $store) {
                    if (ProductBulkSchema::parseBool($row['store.' . $store->id . '.is_listed'] ?? null, 0) === 1) {
                        $anyListed = true;
                        break 2;
                    }
                }
            }
            if (!$anyListed) {
                $errors[] = ['row' => $group[0]['__row'], 'error' => __('at_least_one_store_must_be_listed_for') . ': ' . $handle];
            }
        }

        return $errors;
    }

    /** Price rules per store cell group. */
    private function validateStoreCells(array $row, ProductBulkSchema $schema, int $r): array
    {
        $errors = [];
        foreach ($schema->stores as $store) {
            $p = 'store.' . $store->id . '.';
            // Unlike the form's API, an omitted "Listed" here means NOT listed. The old
            // default (listed) turned a blank cell into a hard failure on missing price.
            $listed = ProductBulkSchema::parseBool($row[$p . 'is_listed'] ?? null, 0);
            if ($listed !== 1) {
                continue;
            }
            $price = $row[$p . 'price'] ?? null;
            $disc  = $row[$p . 'discounted_price'] ?? null;

            if ($price === null || $price === '' || !is_numeric($price) || (float) $price <= 0) {
                $errors[] = ['row' => $r, 'error' => '[' . $store->name . '] ' . __('price_is_required_for_a_listed_store')];
                continue;
            }
            if ($disc !== null && $disc !== '') {
                if (!is_numeric($disc)) {
                    $errors[] = ['row' => $r, 'error' => '[' . $store->name . '] ' . __('discounted_price_must_be_a_number')];
                } elseif ((float) $disc > (float) $price) {
                    $errors[] = ['row' => $r, 'error' => '[' . $store->name . '] ' . __('discounted_price_cannot_be_greater_than_price')];
                }
            }
            foreach (['purchase_price', 'available', 'min_alert'] as $f) {
                $v = $row[$p . $f] ?? null;
                if ($v !== null && $v !== '' && (!is_numeric($v) || (float) $v < 0)) {
                    $errors[] = ['row' => $r, 'error' => '[' . $store->name . '] ' . __('invalid_value_for') . ' ' . $f];
                }
            }
        }
        return $errors;
    }

    private function validateImages(array $row, int $r, array $knownPaths = []): array
    {
        $errors = [];
        foreach (['image' => false, 'gallery' => true] as $key => $multi) {
            $raw = $row[$key] ?? '';
            if ($raw === '' || $raw === null) {
                continue;
            }
            $items = $multi ? array_filter(array_map('trim', explode(',', (string) $raw))) : [(string) $raw];
            foreach ($items as $item) {
                if (filter_var($item, FILTER_VALIDATE_URL)) {
                    continue;
                }
                if (in_array($item, $knownPaths, true)) {
                    continue;
                }
                if (!Storage::disk('public')->exists($item)) {

                    $looksLikeFragment = $multi && pathinfo($item, PATHINFO_EXTENSION) === '';
                    $errors[] = [
                        'row' => $r,
                        'error' => __('image_not_found') . ': ' . $item
                            . ($looksLikeFragment ? ' — ' . __('image_path_contains_comma_hint') : ''),
                    ];
                }
            }
        }
        return $errors;
    }

    /** @return array<string,array> */
    public function groupByHandle(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) ($row['handle'] ?? '')][] = $row;
        }
        return $groups;
    }

    /**
     * Build the product-form payload for one handle group and hand it to the real
     * writer. Returns null on success, or the writer's error message.
     */
    public function createProduct(string $handle, array $group, ProductBulkSchema $schema): ?string
    {
        $first = $group[0];

        $payload = [
            'category_id' => $schema->category->id,
            'is_draft'    => 0,
            'sales_channel' => $this->channelForStores($schema),
            'brand_id'    => $schema->brandId($first['brand'] ?? null),
            'tax_category_id' => $schema->taxCategoryId($first['tax'] ?? null),
            'product_type' => ProductBulkSchema::PRODUCT_TYPES[strtolower((string) ($first['product_type'] ?? 'none'))] ?? 0,
            'status'      => strtolower((string) ($first['status'] ?? 'active')) === 'inactive' ? 0 : 1,
            'total_allowed_quantity' => (int) ($first['total_allowed_quantity'] ?? 0),
            'cod_allowed' => ProductBulkSchema::parseBool($first['cod_allowed'] ?? null, 0),
            'return_status' => ProductBulkSchema::parseBool($first['return_status'] ?? null, 0),
            'return_days' => (int) ($first['return_days'] ?? 0),
            'cancelable_status' => ProductBulkSchema::parseBool($first['cancelable_status'] ?? null, 0),
            'till_status_quick' => $first['till_status_quick'] ?? '',
            'till_status_ecommerce' => $first['till_status_ecommerce'] ?? '',
            'is_prescription_required' => (int) ($first['is_prescription_required'] ?? 0),
            'translations' => json_encode($this->productTranslations($first, $schema)),
            'variants' => json_encode($this->variantPayloads($group, $schema)),
        ];

        $request = new Request();
        $request->merge($payload);

        $response = app(ProductApisController::class)->save($request);
        $body = json_decode($response->getContent(), true);

        if (!($body['status'] ?? 0)) {
            return $body['message'] ?? __('something_went_wrong');
        }

        // Gallery images are referenced by path, which the form's file-upload path
        // does not cover — attach them once the variants exist.
        $this->attachGalleries($group, $schema);

        return null;
    }

    /**
     * A product's sales_channel must cover the stores it is priced for, else the
     * customer-side channel filter hides it from the very stores it targets.
     */
    private function channelForStores(ProductBulkSchema $schema): string
    {
        $types = \App\Models\Store::whereIn('id', $schema->stores->pluck('id'))
            ->pluck('fulfillment_type')->unique()->values()->all();

        if ($types === ['quick']) {
            return 'quick';
        }
        if ($types === ['ecommerce']) {
            return 'ecommerce';
        }
        return 'both';
    }

    private function productTranslations(array $first, ProductBulkSchema $schema): array
    {
        $out = [];
        foreach ($schema->languages as $lang) {
            $data = [];
            foreach (ProductBulkSchema::TRANSLATABLE_PRODUCT_FIELDS as $field) {
                $v = $first['ptrans.' . $field . '.' . $lang->id] ?? null;
                if ($v !== null && $v !== '') {
                    $data[$field] = $v;
                }
            }
            if ((int) $lang->is_default === 1) {
                foreach (ProductBulkSchema::SINGLE_LANG_PRODUCT_FIELDS as $field) {
                    $v = $first['ptrans.' . $field . '.' . $lang->id] ?? null;
                    if ($v !== null && $v !== '') {
                        $data[$field] = $v;
                    }
                }
            }
            if ($data) {
                $out[(string) $lang->id] = $data;
            }
        }
        return $out;
    }

    private function variantPayloads(array $group, ProductBulkSchema $schema): array
    {
        $attributes = $schema->attributes();
        $variants = [];

        foreach ($group as $row) {
            $attrValues = [];
            foreach ($attributes as $attr) {
                $val = $row['attr.' . $attr->id] ?? '';
                $match = $attr->values->first(fn ($v) => mb_strtolower((string) $v->value) === mb_strtolower((string) $val));
                if ($match) {
                    $attrValues[] = ['attribute_id' => (int) $attr->id, 'value_id' => (int) $match->id];
                }
            }

            $translations = [];
            foreach ($schema->languages as $lang) {
                $n = $row['variant_name.' . $lang->id] ?? null;
                if ($n !== null && $n !== '') {
                    $translations[(string) $lang->id] = ['name' => $n];
                }
            }

            $variants[] = [
                'name' => $row['variant_name.' . ($schema->defaultLanguage->id ?? 0)] ?? '',
                'sku' => $row['sku'] ?? '',
                'hsn_code' => $row['hsn_code'] ?? '',
                'image_path' => $this->resolveImage($row['image'] ?? ''),
                'attribute_values' => $attrValues,
                'translations' => $translations,
                'custom_values' => $this->customValuePayloads($row, $schema),
                'store_stocks' => $this->storeStockPayloads($row, $schema),
            ];
        }

        return $variants;
    }

    private function customValuePayloads(array $row, ProductBulkSchema $schema): array
    {
        $out = [];
        foreach ($schema->customFields() as $field) {
            $v = $row['custom.' . $field->id] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $entry = ['field_id' => (int) $field->id, 'value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null, 'translations' => []];

            switch ($field->field_type) {
                case 'number':
                    if (!is_numeric($v)) {
                        continue 2;
                    }
                    $entry['value_number'] = (float) $v;
                    break;
                case 'date':
                    $ts = strtotime((string) $v);
                    if ($ts === false) {
                        continue 2;
                    }
                    $entry['value_date'] = date('Y-m-d', $ts);
                    break;
                case 'boolean':
                    $entry['value_text'] = (string) (ProductBulkSchema::parseBool($v, 0));
                    break;
                case 'multiselect':
                case 'checkbox':
                    $entry['value_json'] = array_values(array_filter(array_map('trim', explode(',', (string) $v))));
                    break;
                default:
                    $entry['value_text'] = (string) $v;
            }
            $out[] = $entry;
        }
        return $out;
    }

    private function storeStockPayloads(array $row, ProductBulkSchema $schema): array
    {
        $out = [];
        foreach ($schema->stores as $store) {
            $p = 'store.' . $store->id . '.';
            $listed = ProductBulkSchema::parseBool($row[$p . 'is_listed'] ?? null, 0);
            // Unlimited stock is a per-store column now — it arrives in the store group.
            $unlimited = ProductBulkSchema::parseBool($row[$p . 'is_unlimited_stock'] ?? null, 0);
            $out[] = [
                'store_id' => (int) $store->id,
                'is_listed' => $listed,
                'is_unlimited_stock' => $unlimited,
                'stock_status' => ProductBulkSchema::parseBool($row[$p . 'stock_status'] ?? null, 1),
                // Counts are kept as submitted even when unlimited, so flipping a store
                // back to Limited preserves them (matches the product form).
                'available' => (int) ($row[$p . 'available'] ?? 0),
                'min_alert' => (int) ($row[$p . 'min_alert'] ?? 0),
                'price' => $this->num($row[$p . 'price'] ?? null),
                'discounted_price' => $this->num($row[$p . 'discounted_price'] ?? null),
                'purchase_price' => $this->num($row[$p . 'purchase_price'] ?? null),
                'pricing_slabs' => [],
            ];
        }
        return $out;
    }

    private function num($v): ?float
    {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
    }

    /** Only local storage paths are usable as-is; URLs are fetched and stored. */
    private function resolveImage(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        try {
            $contents = @file_get_contents($value);
            if ($contents === false) {
                return '';
            }
            $ext = pathinfo(parse_url($value, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $path = 'products/variants/' . uniqid('bulk_', true) . '.' . $ext;
            Storage::disk('public')->put($path, $contents);
            return $path;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function attachGalleries(array $group, ProductBulkSchema $schema): void
    {
        foreach ($group as $row) {
            $raw = trim((string) ($row['gallery'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $variant = ProductVariant::where('sku', $row['sku'] ?? '')->first();
            if ($variant) {
                $this->mergeGalleryPaths($variant, $raw);
            }
        }
    }

    private function mergeGalleryPaths(ProductVariant $variant, string $raw): void
    {
        $existing = ProductImages::where('product_variant_id', $variant->id)
            ->pluck('image')->all();

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $item) {
            $path = $this->resolveImage($item);
            if ($path === '' || in_array($path, $existing, true)) {
                continue;
            }
            ProductImages::create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'image' => $path,
            ]);
            $existing[] = $path;
        }
    }


    /**
     * Validate an update sheet. Rows are matched on variant_id; anything the sheet
     * does not mention is left alone.
     */
    public function validateForUpdate(array $rows, ProductBulkSchema $schema): array
    {
        $errors = [];
        foreach ($rows as $row) {
            $r = $row['__row'];
            $variantId = (int) ($row['variant_id'] ?? 0);
            $productId = (int) ($row['product_id'] ?? 0);

            if ($variantId <= 0 || $productId <= 0) {
                $errors[] = ['row' => $r, 'error' => __('product_id_and_variant_id_are_required')];
                continue;
            }
            $variant = ProductVariant::where('id', $variantId)->where('product_id', $productId)->first();
            if (!$variant) {
                $errors[] = ['row' => $r, 'error' => __('variant_not_found_for_this_product') . ': ' . $variantId];
                continue;
            }
            $sku = $row['sku'] ?? '';
            if ($sku !== '' && ProductVariant::where('sku', $sku)->where('id', '!=', $variantId)->exists()) {
                $errors[] = ['row' => $r, 'error' => __('sku_already_used') . ': ' . $sku];
            }
            $errors = array_merge($errors, $this->validateStoreCells($row, $schema, $r));

            // Paths the variant already carries are accepted as-is: an unchanged
            // export must re-upload cleanly even if a referenced file went missing.
            $knownPaths = ProductImages::where('product_variant_id', $variant->id)
                ->pluck('image')->push((string) $variant->getRawOriginal('image'))
                ->filter()->all();
            $errors = array_merge($errors, $this->validateImages($row, $r, $knownPaths));
        }
        return $errors;
    }

    /**
     * Merge one row into an existing variant. Only columns present in the sheet are
     * written; variants and stores that were not exported keep their data.
     */
    public function updateRow(array $row, ProductBulkSchema $schema): void
    {
        $variant = ProductVariant::find((int) $row['variant_id']);
        if (!$variant) {
            return;
        }
        $product = Product::find((int) $row['product_id']);

        /* ---- variant fields ---- */
        if (array_key_exists('sku', $row) && $row['sku'] !== '' && $row['sku'] !== null) {
            $variant->sku = $row['sku'];
        }
        if (array_key_exists('hsn_code', $row) && $row['hsn_code'] !== null && $row['hsn_code'] !== '') {
            $variant->hsn_code = $row['hsn_code'];
        }
        if (!empty($row['image'])) {
            $path = $this->resolveImage((string) $row['image']);
            if ($path !== '') {
                $variant->image = $path;
            }
        }
        $variant->save();

        // Gallery column: add any listed paths the variant doesn't have yet.
        if (!empty($row['gallery'])) {
            $this->mergeGalleryPaths($variant, (string) $row['gallery']);
        }

        foreach ($schema->languages as $lang) {
            $n = $row['variant_name.' . $lang->id] ?? null;
            if ($n !== null && $n !== '') {
                $variant->saveTranslation((int) $lang->id, ['name' => $n]);
            }
        }

        /* ---- product fields (last row of a product wins; sheets repeat them) ---- */
        if ($product) {
            $this->mergeProductFields($product, $row, $schema);
        }

        /* ---- custom values ---- */
        foreach ($this->customValuePayloads($row, $schema) as $cv) {
            $model = ProductVariantCustomValue::firstOrNew([
                'product_variant_id' => $variant->id,
                'category_custom_field_id' => $cv['field_id'],
            ]);
            $model->value_text = $cv['value_text'];
            $model->value_number = $cv['value_number'];
            $model->value_date = $cv['value_date'];
            $model->value_json = $cv['value_json'];
            $model->save();
        }

        /* ---- attributes ---- */
        foreach ($schema->attributes() as $attr) {
            $val = $row['attr.' . $attr->id] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            $match = $attr->values->first(fn ($v) => mb_strtolower((string) $v->value) === mb_strtolower((string) $val));
            if (!$match) {
                continue;
            }
            ProductVariantAttributeValue::updateOrCreate(
                ['product_variant_id' => $variant->id, 'attribute_id' => (int) $attr->id],
                ['attribute_value_id' => (int) $match->id]
            );
        }

        /* ---- pricing: targeted upsert on the PVSS natural key ---- */
        foreach ($schema->stores as $store) {
            $p = 'store.' . $store->id . '.';
            // A store group left entirely blank means "don't touch this store".
            $touched = false;
            foreach (array_keys(ProductBulkSchema::STORE_FIELDS) as $f) {
                if (array_key_exists($p . $f, $row) && $row[$p . $f] !== null && $row[$p . $f] !== '') {
                    $touched = true;
                    break;
                }
            }
            if (!$touched) {
                continue;
            }

            $stock = ProductVariantStoreStock::firstOrNew([
                'product_variant_id' => $variant->id,
                'store_id' => (int) $store->id,
            ]);

            $listed = ProductBulkSchema::parseBool($row[$p . 'is_listed'] ?? null, null);
            if ($listed !== null) {
                $stock->is_listed = $listed;
            }
            $ss = ProductBulkSchema::parseBool($row[$p . 'stock_status'] ?? null, null);
            if ($ss !== null) {
                $stock->stock_status = $ss;
            }
            foreach (['price', 'discounted_price', 'purchase_price'] as $f) {
                $v = $row[$p . $f] ?? null;
                if ($v !== null && $v !== '' && is_numeric($v)) {
                    $stock->{$f} = (float) $v;
                }
            }
            // Unlimited stock is per store now. Counts are still stored as submitted so
            // switching a store back to Limited preserves them (mirrors the product form).
            $unlimited = ProductBulkSchema::parseBool($row[$p . 'is_unlimited_stock'] ?? null, null);
            if ($unlimited !== null) {
                $stock->is_unlimited_stock = $unlimited;
            }
            foreach (['available', 'min_alert'] as $f) {
                $v = $row[$p . $f] ?? null;
                if ($v !== null && $v !== '' && is_numeric($v)) {
                    $stock->{$f} = (int) $v;
                }
            }
            $stock->save();
            ProductHelper::notifyBackInStock($variant->id, (int) $store->id);
        }
    }

    private function mergeProductFields(Product $product, array $row, ProductBulkSchema $schema): void
    {
        $map = [
            'brand' => fn ($v) => ['brand_id' => $schema->brandId($v)],
            'tax' => fn ($v) => ['tax_category_id' => $schema->taxCategoryId($v)],
            'product_type' => fn ($v) => ['product_type' => ProductBulkSchema::PRODUCT_TYPES[strtolower((string) $v)] ?? 0],
            'status' => fn ($v) => ['status' => strtolower((string) $v) === 'inactive' ? 0 : 1],
            'cod_allowed' => fn ($v) => ['cod_allowed' => ProductBulkSchema::parseBool($v, 0)],
            'return_status' => fn ($v) => ['return_status' => ProductBulkSchema::parseBool($v, 0)],
            'cancelable_status' => fn ($v) => ['cancelable_status' => ProductBulkSchema::parseBool($v, 0)],
            'return_days' => fn ($v) => ['return_days' => (int) $v],
            'total_allowed_quantity' => fn ($v) => ['total_allowed_quantity' => (int) $v],
            'till_status_quick' => fn ($v) => ['till_status_quick' => $v],
            'till_status_ecommerce' => fn ($v) => ['till_status_ecommerce' => $v],
            'is_prescription_required' => fn ($v) => ['is_prescription_required' => (int) $v],
        ];

        foreach ($map as $key => $fn) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                foreach ($fn($row[$key]) as $col => $val) {
                    $product->{$col} = $val;
                }
            }
        }
        $product->save();

        foreach ($schema->languages as $lang) {
            $data = [];
            $fields = ProductBulkSchema::TRANSLATABLE_PRODUCT_FIELDS;
            if ((int) $lang->is_default === 1) {
                $fields = array_merge($fields, ProductBulkSchema::SINGLE_LANG_PRODUCT_FIELDS);
            }
            foreach ($fields as $field) {
                $v = $row['ptrans.' . $field . '.' . $lang->id] ?? null;
                if ($v !== null && $v !== '') {
                    $data[$field] = $v;
                }
            }
            if ($data) {
                $product->saveTranslation((int) $lang->id, $data);
            }
        }
    }
}
