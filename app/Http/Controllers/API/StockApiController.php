<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Helpers\ProductHelper;
use App\Http\Controllers\Controller;
use App\Models\ProductVariantStoreStock;
use App\Models\Store;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockApiController extends Controller
{
    /* ---- Stock state helpers (kept consistent across endpoints) ---- */

    /** Out of stock: explicitly marked out, or nothing available. */
    /** Stock is not tracked for this store row — it can never run out. */
    private function isUnlimited($row): bool
    {
        return (int) ($row->is_unlimited_stock ?? 0) === 1;
    }

    private function isOut($row): bool
    {
        if ($this->isUnlimited($row)) {
            return false;
        }
        return (int) $row->stock_status === 0 || (int) $row->available <= 0;
    }

    /** Low stock: in stock but at/below the min alert (alert configured). */
    private function isLow($row): bool
    {
        if ($this->isUnlimited($row)) {
            return false;
        }
        return !$this->isOut($row) && (int) $row->min_alert > 0 && (int) $row->available <= (int) $row->min_alert;
    }

    /**
     * Store ids allowed by the global header country/zone filter, or null when no
     * filter is set. Stores reach a country via their zone FKs (quick / ecommerce).
     */
    private function filteredStoreIds(Request $request): ?array
    {
        $countryId = (int) $request->input('country_id', 0);
        $zoneId    = (int) $request->input('zone_id', 0);
        if (!$countryId && !$zoneId) {
            return null;
        }

        $zoneIds = $zoneId
            ? [$zoneId]
            : Zone::where('country_id', $countryId)->pluck('id')->all();

        return Store::where(function ($q) use ($zoneIds) {
            $q->whereIn('zone_id', $zoneIds);
        })->pluck('id')->all();
    }

    /**
     * Overview tab: global KPI tiles + per-store snapshot.
     */
    public function overview(Request $request)
    {
        $allowed = $this->filteredStoreIds($request);
        $storeQuery = Store::where('status', 1)->orderBy('name');
        if ($allowed !== null) {
            $storeQuery->whereIn('id', $allowed);
        }

        $stores = $storeQuery->with('zone:id,city')->get(['id', 'name', 'zone_id']);
        $storeIds = $stores->pluck('id')->all();

        $rows = $this->baseRows()->whereIn('pvss.store_id', $storeIds)->get();

        // Per-store snapshot + global accumulators.
        $perStore = [];
        foreach ($stores as $s) {
            $perStore[$s->id] = [
                'id' => (int) $s->id, 'name' => $s->name, 'city' => $s->zone?->city,
                'in_stock' => 0, 'out' => 0, 'low' => 0, 'units' => 0, 'value' => 0.0,
            ];
        }

        $totalUnits = 0;
        $totalValue = 0.0;
        $listedSkuIds = [];
        $variantInStock = [];   // variant_id => bool (in stock anywhere)
        $variantExists  = [];   // variant_id => true
        $variantLow     = [];   // variant_id => bool

        foreach ($rows as $r) {
            $variantExists[$r->product_variant_id] = true;
            $unitPrice = (float) $r->discounted_price > 0 ? (float) $r->discounted_price : (float) $r->price;
            // Unlimited stores don't track units, so their `available` is not a real
            // quantity — counting it would inflate units and inventory value.
            $lineUnits = $this->isUnlimited($r) ? 0 : max(0, (int) $r->available);
            $lineValue = $lineUnits * $unitPrice;
            $totalUnits += $lineUnits;
            $totalValue += $lineValue;

            if ((int) $r->is_listed === 1) {
                $listedSkuIds[$r->product_variant_id] = true;
            }

            $out = $this->isOut($r);
            $low = $this->isLow($r);
            if (!$out) {
                $variantInStock[$r->product_variant_id] = true;
            }
            if ($low) {
                $variantLow[$r->product_variant_id] = true;
            }

            $ps = &$perStore[$r->store_id];
            if ($out) {
                $ps['out']++;
            } elseif ($low) {
                $ps['low']++;
                $ps['in_stock']++;
            } else {
                $ps['in_stock']++;
            }
            $ps['units'] += $lineUnits;
            $ps['value'] += $lineValue;
            unset($ps);
        }

        $totalSkus = count($variantExists);
        $inStock   = count($variantInStock);
        $low       = count(array_filter($variantLow, fn($v, $k) => isset($variantInStock[$k]), ARRAY_FILTER_USE_BOTH));

        $totals = [
            'total_skus'      => $totalSkus,
            'in_stock'        => $inStock,
            'out_of_stock'    => $totalSkus - $inStock,
            'low_stock'       => $low,
            'listed_skus'     => count($listedSkuIds),
            'total_units'     => $totalUnits,
            'inventory_value' => round($totalValue, 2),
            'active_stores'   => $stores->count(),
        ];

        $snapshot = array_map(function ($s) {
            $s['value'] = round($s['value'], 2);
            return $s;
        }, array_values($perStore));

        return CommonHelper::responseWithData([
            'totals' => $totals,
            'stores' => $snapshot,
        ]);
    }

    /**
     * Inventory tab: store view (flat rows) or product view (grouped). Filters:
     * search, store_id, status (in_stock|out_of_stock|low_stock|unlisted).
     */
    public function inventory(Request $request)
    {
        $view    = $request->input('view') === 'product' ? 'product' : 'store';
        $search  = trim((string) $request->input('search', ''));
        $storeId = (int) $request->input('store_id', 0);
        $status  = $request->input('status', 'all');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $allowed = $this->filteredStoreIds($request);

        $apply = function ($q) use ($search, $storeId, $status, $allowed) {
            $q->where('stores.status', 1);
            if ($allowed !== null) {
                $q->whereIn('pvss.store_id', $allowed);
            }
            if ($storeId) {
                $q->where('pvss.store_id', $storeId);
            }
            if ($search !== '') {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('pv.name', 'like', $like)->orWhere('pv.sku', 'like', $like);
                });
            }
            // Unlimited store rows are always in stock and never low / out.
            switch ($status) {
                case 'in_stock':
                    $q->where(function ($w) {
                        $w->where('pvss.is_unlimited_stock', 1)
                            ->orWhere(fn($x) => $x->where('pvss.stock_status', 1)->where('pvss.available', '>', 0));
                    });
                    break;
                case 'out_of_stock':
                    $q->where('pvss.is_unlimited_stock', 0)
                        ->where(function ($w) {
                            $w->where('pvss.stock_status', 0)->orWhere('pvss.available', '<=', 0);
                        });
                    break;
                case 'low_stock':
                    $q->where('pvss.is_unlimited_stock', 0)
                        ->where('pvss.available', '>', 0)
                        ->where('pvss.min_alert', '>', 0)
                        ->whereColumn('pvss.available', '<=', 'pvss.min_alert');
                    break;
                case 'unlimited':
                    $q->where('pvss.is_unlimited_stock', 1);
                    break;
                case 'unlisted':
                    $q->where('pvss.is_listed', 0);
                    break;
            }
        };

        if ($view === 'product') {
            // Distinct products matching the filter, paginated.
            $productIdsQuery = $this->baseRows()->select('p.id')->distinct();
            $apply($productIdsQuery);
            $allProductIds = $productIdsQuery->orderBy('p.id', 'desc')->pluck('id')->all();
            $totalProducts = count($allProductIds);
            $pageProductIds = array_slice($allProductIds, ($page - 1) * $perPage, $perPage);

            $groups = [];
            if (!empty($pageProductIds)) {
                $rowsQuery = $this->baseRows()->whereIn('p.id', $pageProductIds);
                $apply($rowsQuery);
                $rows = $rowsQuery->orderBy('p.id', 'desc')->orderBy('pv.id')->get();

                $variantIds = $rows->pluck('product_variant_id')->unique()->all();
                $attrText = $this->attributesTextMap($variantIds);

                $byProduct = [];
                foreach ($rows as $r) {
                    $byProduct[$r->product_id]['rows'][] = $this->shapeRow($r, $attrText);
                    $byProduct[$r->product_id]['units'] = ($byProduct[$r->product_id]['units'] ?? 0) + max(0, (int) $r->available);
                    $byProduct[$r->product_id]['meta'] = [
                        'product_id' => (int) $r->product_id,
                        'name'       => $r->product_name,
                        'image_url'  => !empty($r->variant_image) ? asset('storage/' . $r->variant_image) : null,
                        'sku'        => $r->sku,
                        'brand'      => $r->brand_name,
                    ];
                }
                // Keep page order.
                foreach ($pageProductIds as $pid) {
                    if (isset($byProduct[$pid])) {
                        $g = $byProduct[$pid];
                        $groups[] = array_merge($g['meta'], [
                            'units_total'    => $g['units'],
                            'store_variants' => count($g['rows']),
                            'variants'       => $g['rows'],
                        ]);
                    }
                }
            }

            return CommonHelper::responseWithData([
                'view'   => 'product',
                'groups' => $groups,
            ], $totalProducts);
        }

        // Store view: flat paginated rows.
        $rowsQuery = $this->baseRows();
        $apply($rowsQuery);
        $total = (clone $rowsQuery)->count('pvss.id');
        $rows = $rowsQuery->orderBy('p.id', 'desc')->orderBy('pv.id')
            ->forPage($page, $perPage)->get();

        $attrText = $this->attributesTextMap($rows->pluck('product_variant_id')->unique()->all());
        $items = $rows->map(fn($r) => $this->shapeRow($r, $attrText))->all();

        return CommonHelper::responseWithData(['view' => 'store', 'rows' => $items], $total);
    }

    /**
     * Adjust a store-variant's available stock: add | remove | set.
     * stock_status is recomputed from the resulting available (>0 => in stock).
     */
    public function adjust(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_variant_id' => 'required|integer',
            'store_id'           => 'required|integer',
            'action'             => 'required|in:add,remove,set',
            'quantity'           => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $row = ProductVariantStoreStock::where('product_variant_id', $request->product_variant_id)
            ->where('store_id', $request->store_id)
            ->first();
        if (!$row) {
            return CommonHelper::responseError('stock_record_not_found');
        }

        $qty = (int) $request->quantity;
        $available = (int) $row->available;
        switch ($request->action) {
            case 'add':
                $available += $qty;
                break;
            case 'remove':
                $available = max(0, $available - $qty);
                break;
            case 'set':
                $available = $qty;
                break;
        }

        $row->available = $available;
        $row->stock_status = $available > 0 ? 1 : 0;
        $row->save();

        ProductHelper::notifyBackInStock($row->product_variant_id, $row->store_id);

        return CommonHelper::responseSuccessWithData('stock_updated_successfully', [
            'available'    => $available,
            'stock_status' => (int) $row->stock_status,
        ]);
    }

    /**
     * Alerts tab: out-of-stock + low-stock store-variants (listed only).
     */
    public function alerts(Request $request)
    {
        $allowed = $this->filteredStoreIds($request);

        $rows = $this->baseRows()
            ->where('stores.status', 1)
            ->when($allowed !== null, fn($q) => $q->whereIn('pvss.store_id', $allowed))
            ->where('pvss.is_listed', 1)
            ->where('pvss.is_unlimited_stock', 0)
            ->where(function ($w) {
                $w->where('pvss.stock_status', 0)
                    ->orWhere('pvss.available', '<=', 0)
                    ->orWhere(function ($l) {
                        $l->where('pvss.available', '>', 0)
                            ->where('pvss.min_alert', '>', 0)
                            ->whereColumn('pvss.available', '<=', 'pvss.min_alert');
                    });
            })
            ->orderBy('pvss.available')
            ->get();

        $attrText = $this->attributesTextMap($rows->pluck('product_variant_id')->unique()->all());

        $out = [];
        $low = [];
        foreach ($rows as $r) {
            $shaped = $this->shapeRow($r, $attrText);
            if ($this->isOut($r)) {
                $out[] = $shaped;
            } elseif ($this->isLow($r)) {
                $low[] = $shaped;
            }
        }

        return CommonHelper::responseWithData([
            'out_of_stock' => $out,
            'low_stock'    => $low,
            'out_count'    => count($out),
            'low_count'    => count($low),
            'total'        => count($out) + count($low),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** Base joined PVSS query with the columns every endpoint needs. */
    private function baseRows()
    {
        return ProductVariantStoreStock::query()
            ->from('product_variant_store_stocks as pvss')
            ->join('product_variants as pv', 'pv.id', '=', 'pvss.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('stores', 'stores.id', '=', 'pvss.store_id')
            // City moved to the zone, so join it for the store_city column below.
            ->leftJoin('zones as store_zones', 'store_zones.id', '=', 'stores.zone_id')
            ->leftJoin('brands', 'brands.id', '=', 'p.brand_id')
            ->select(
                'pvss.id', 'pvss.product_variant_id', 'pvss.store_id',
                'pvss.available', 'pvss.reserved', 'pvss.min_alert',
                'pvss.stock_status', 'pvss.is_listed',
                'pvss.price', 'pvss.discounted_price', 'pvss.purchase_price',
                'pv.name as product_name', 'pv.sku',
                'pv.image as variant_image', 'pv.product_id',
                'pvss.is_unlimited_stock',
                'brands.name as brand_name',
                'stores.name as store_name', 'store_zones.city as store_city'
            );
    }

    /** variant_id => "Red / Large" attribute summary. */
    private function attributesTextMap(array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }
        $rows = DB::table('product_variant_attribute_values as pva')
            ->join('attribute_values as av', 'av.id', '=', 'pva.attribute_value_id')
            ->whereIn('pva.product_variant_id', $variantIds)
            ->orderBy('pva.id')
            ->get(['pva.product_variant_id', 'av.value']);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->product_variant_id][] = $r->value;
        }
        return array_map(fn($vals) => implode(' / ', $vals), $map);
    }

    /** Shape one PVSS row into an inventory item. */
    private function shapeRow($r, array $attrText): array
    {
        $available = (int) $r->available;
        $unlimited = $this->isUnlimited($r);
        $out = $this->isOut($r);
        $low = $this->isLow($r);
        $state = $unlimited ? 'unlimited' : ($out ? 'out_of_stock' : ($low ? 'low_stock' : 'in_stock'));

        return [
            'product_variant_id' => (int) $r->product_variant_id,
            'product_id'         => (int) $r->product_id,
            'product_name'       => $r->product_name,
            'image_url'          => !empty($r->variant_image) ? asset('storage/' . $r->variant_image) : null,
            'sku'                => $r->sku,
            'brand_name'         => $r->brand_name,
            'variant_text'       => $attrText[$r->product_variant_id] ?? '',
            'store_id'           => (int) $r->store_id,
            'store_name'         => $r->store_name,
            'store_city'         => $r->store_city,
            'available'          => $available,
            'reserved'           => (int) $r->reserved,
            'min_alert'          => (int) $r->min_alert,
            'is_listed'          => (int) $r->is_listed,
            'is_unlimited_stock' => $unlimited ? 1 : 0,
            'in_stock'           => !$out,
            'state'              => $state,
        ];
    }
}
