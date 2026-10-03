<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessProductBulkJob;
use App\Models\Category;
use App\Models\ProductBulkImport;
use App\Models\Store;
use App\Services\ProductBulkSchema;
use App\Services\ProductBulkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Product bulk import/export.
 *
 * Every endpoint is scoped to a (category, stores) selection, because that pair is
 * what determines the sheet's columns: the category supplies the attribute and
 * custom-field columns, the stores supply the per-store pricing groups. See
 * ProductBulkSchema for the column contract shared by sample/export/import.
 */
class ProductBulkApiController extends Controller
{
    private ProductBulkService $service;

    public function __construct(ProductBulkService $service)
    {
        $this->service = $service;
    }

    /** Everything the UI needs to build the selector and preview the columns. */
    public function meta(Request $request)
    {
        $categoryId = (int) $request->input('category_id', 0);
        $storeIds = $this->storeIds($request);

        $data = [
            'max_rows' => ProductBulkService::MAX_ROWS,
            'stores' => $this->channelStores($request),
        ];

        // Columns can only be resolved once a category is chosen.
        if ($categoryId > 0 && Category::where('id', $categoryId)->exists()) {
            $schema = ProductBulkSchema::make($categoryId, $storeIds, (bool) $request->input('for_update', false));
            $attributes = $schema->attributes();

            $data['attributes'] = $attributes->map(fn ($a) => [
                'id' => (int) $a->id,
                'name' => $a->name,
                'values' => $a->values->map(fn ($v) => ['id' => (int) $v->id, 'value' => $v->value])->values(),
            ])->values();
            $data['custom_fields'] = $schema->customFields()->map(fn ($f) => [
                'id' => (int) $f->id,
                'label' => $f->field_label,
                'type' => $f->field_type,
            ])->values();
            $data['languages'] = $schema->languages->map(fn ($l) => [
                'id' => (int) $l->id, 'name' => $l->name, 'is_default' => (int) $l->is_default,
            ])->values();
            $data['columns'] = array_map(fn ($c) => ['label' => $c['label'], 'group' => $c['group'], 'required' => $c['required']], $schema->columns());
            // The product form refuses a category with no attributes, because variants
            // are built from them. Surface the same rule here rather than shipping a
            // sample that can't describe a variant.
            $data['has_attributes'] = $attributes->isNotEmpty();
        }

        return CommonHelper::responseWithData($data);
    }

    /** Blank template matching the chosen category + stores. */
    public function sample(Request $request)
    {
        $error = $this->validateSelection($request);
        if ($error) {
            return $error;
        }

        $schema = ProductBulkSchema::make((int) $request->input('category_id'), $this->storeIds($request), false);
        if ($schema->attributes()->isEmpty()) {
            return CommonHelper::responseError('this_category_has_no_attributes_add_attributes_first');
        }

        $file = $this->service->sample($schema);

        return response()->download($file, $this->fileName('sample', $schema), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Existing products for the chosen category + stores, keyed for re-upload. */
    public function export(Request $request)
    {
        $error = $this->validateSelection($request);
        if ($error) {
            return $error;
        }

        $schema = ProductBulkSchema::make((int) $request->input('category_id'), $this->storeIds($request), true);
        $file = $this->service->export($schema);

        return response()->download($file, $this->fileName('products', $schema), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Create products from a filled sample. All-or-nothing. */
    public function upload(Request $request)
    {
        return $this->import($request, false);
    }

    /** Merge edits from an exported sheet into existing products. */
    public function update(Request $request)
    {
        return $this->import($request, true);
    }

    private function import(Request $request, bool $forUpdate)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
            'category_id' => 'required|integer|exists:categories,id',
            'store_ids' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        if (Category::where('parent_id', (int) $request->input('category_id'))->exists()) {
            return CommonHelper::responseError(__('category_must_be_leaf'));
        }

        $storeIds = $this->storeIds($request);
        if (empty($storeIds)) {
            return CommonHelper::responseError('please_select_at_least_one_store');
        }

        $schema = ProductBulkSchema::make((int) $request->input('category_id'), $storeIds, $forUpdate);

        $read = $this->service->read($request->file('file')->getRealPath(), $schema->columns());
        if (!empty($read['errors'])) {
            return CommonHelper::responseError($read['errors'][0]);
        }
        if (empty($read['rows'])) {
            return CommonHelper::responseError('no_data_rows_found_in_the_file');
        }

        // Validate the whole file before writing anything, so a bad row never leaves a
        // half-imported catalogue behind.
        $errors = $forUpdate
            ? $this->service->validateForUpdate($read['rows'], $schema)
            : $this->service->validateForCreate($read['rows'], $schema);

        if (!empty($errors)) {
            return response()->json([
                'error' => true,
                'status' => 0,
                'message' => __('validation_failed_for_some_rows_please_fix_and_re_upload'),
                'errors' => array_slice($errors, 0, 200),
                'error_count' => count($errors),
            ]);
        }

        // Validation passed — hand the write phase to the job so the UI can poll a
        // progress bar. With QUEUE_CONNECTION=sync the job runs inline right here and
        // the returned snapshot is already terminal; with a real driver it returns
        // immediately and the frontend polls bulk/status until the worker finishes.
        $path = $request->file('file')->store('bulk-imports', 'local');

        $import = ProductBulkImport::create([
            'admin_id' => (int) (auth()->user()->id ?? 0),
            'type' => $forUpdate ? 'update' : 'upload',
            'category_id' => (int) $request->input('category_id'),
            'store_ids' => $storeIds,
            'file_path' => $path,
            'status' => 'pending',
            'total_rows' => $forUpdate ? count($read['rows']) : count($this->service->groupByHandle($read['rows'])),
        ]);

        ProcessProductBulkJob::dispatch($import->id);

        return CommonHelper::responseSuccessWithData('success', $this->statusPayload($import->fresh()));
    }

    /** Progress snapshot for the panel's progress bar. */
    public function status(Request $request)
    {
        $import = ProductBulkImport::find((int) $request->input('id'));
        if (!$import) {
            return CommonHelper::responseError('not_found');
        }
        return CommonHelper::responseWithData($this->statusPayload($import));
    }

    private function statusPayload(ProductBulkImport $import): array
    {
        return [
            'import_id' => (int) $import->id,
            'type' => $import->type,
            'status' => $import->status,
            'total_rows' => (int) $import->total_rows,
            'processed_rows' => (int) $import->processed_rows,
            'success_count' => (int) $import->success_count,
            'errors' => $import->errors ?? [],
            'message' => $import->message,
        ];
    }

    /** Stores valid for the chosen channel — the same list the product form offers. */
    private function channelStores(Request $request): array
    {
        $channel = $request->input('sales_channel', 'both');
        $query = Store::query()->where('status', 1)->orderBy('name');

        if ($channel === 'quick') {
            $query->whereIn('fulfillment_type', ['quick', 'both']);
        } elseif ($channel === 'ecommerce') {
            $query->whereIn('fulfillment_type', ['ecommerce', 'both']);
        }

        return $query->with('zone:id,city')->get(['id', 'name', 'fulfillment_type', 'zone_id'])->map(fn (Store $s) => [
            'id' => (int) $s->id,
            'name' => $s->name,
            'fulfillment_type' => $s->fulfillment_type,
            'city' => $s->zone?->city,
        ])->values()->all();
    }

    private function storeIds(Request $request): array
    {
        $raw = $request->input('store_ids', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));

        // Never trust the client's ids blindly — a stale/foreign id would silently
        // create pricing rows for a store the admin never picked.
        return empty($ids) ? [] : Store::whereIn('id', $ids)->where('status', 1)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function validateSelection(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|integer|exists:categories,id',
            'store_ids' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        if (Category::where('parent_id', (int) $request->input('category_id'))->exists()) {
            return CommonHelper::responseError(__('category_must_be_leaf'));
        }
        if (empty($this->storeIds($request))) {
            return CommonHelper::responseError('please_select_at_least_one_store');
        }
        return null;
    }

    private function fileName(string $prefix, ProductBulkSchema $schema): string
    {
        $cat = preg_replace('/[^a-z0-9]+/i', '_', (string) $schema->category->name);
        return $prefix . '_' . trim(strtolower($cat), '_') . '.xlsx';
    }
}
