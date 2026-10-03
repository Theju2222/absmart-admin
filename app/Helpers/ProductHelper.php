<?php

namespace App\Helpers;

use App\Services\TaxService;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Jobs\SendBackInStockNotification;
use App\Models\ProductStockAlert;
use App\Models\ProductVariantStoreStock;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductHelper
{
    /**
     * Normalize a variant's pricing_slabs (array or JSON string) to a clean list:
     * [ ['min_qty'=>int, 'max_qty'=>int|null, 'price'=>float], ... ] sorted by min_qty.
     */
    public static function normalizeSlabs($slabs): array
    {
        if (is_string($slabs)) {
            $slabs = json_decode($slabs, true);
        }
        if (!is_array($slabs)) {
            return [];
        }
        $out = [];
        foreach ($slabs as $s) {
            if (!is_array($s)) {
                continue;
            }
            $min = isset($s['min_qty']) && $s['min_qty'] !== '' ? (int) $s['min_qty'] : 0;
            $max = isset($s['max_qty']) && $s['max_qty'] !== '' && $s['max_qty'] !== null ? (int) $s['max_qty'] : null;
            $price = isset($s['price']) && $s['price'] !== '' ? (float) $s['price'] : 0;
            if ($min < 1 || $price <= 0) {
                continue; // invalid slab — ignore
            }
            $out[] = ['min_qty' => $min, 'max_qty' => $max, 'price' => $price];
        }
        usort($out, fn ($a, $b) => $a['min_qty'] <=> $b['min_qty']);
        return $out;
    }

    /**
     * Effective PRE-TAX unit price for a variant at a given quantity.
     * Slab match wins; otherwise discounted_price (if set) else base price.
     * $variant may be an object/array with price, discounted_price, pricing_slabs.
     */
    public static function slabUnitPrice($variant, int $qty): float
    {
        $price = (float) (is_array($variant) ? ($variant['price'] ?? 0) : ($variant->price ?? 0));
        $disc  = (float) (is_array($variant) ? ($variant['discounted_price'] ?? 0) : ($variant->discounted_price ?? 0));
        $base  = $disc > 0 ? $disc : $price;

        $slabs = is_array($variant) ? ($variant['pricing_slabs'] ?? null) : ($variant->pricing_slabs ?? null);
        foreach (self::normalizeSlabs($slabs) as $s) {
            if ($qty >= $s['min_qty'] && ($s['max_qty'] === null || $qty <= $s['max_qty'])) {
                return $s['price'];
            }
        }
        return $base;
    }

    /**
     * Upsell hint: the nearest higher-quantity slab that beats the current unit
     * price. Returns ['add_qty','min_qty','price'] or null.
     */
    public static function nextSlabHint($variant, int $qty, float $taxPct = 0, bool $taxInclusive = false): ?array
    {
        $currentUnit = self::slabUnitPrice($variant, $qty);
        $slabs = is_array($variant) ? ($variant['pricing_slabs'] ?? null) : ($variant->pricing_slabs ?? null);
        $best = null;
        foreach (self::normalizeSlabs($slabs) as $s) {
            if ($s['min_qty'] > $qty && $s['price'] < $currentUnit) {
                if ($best === null || $s['min_qty'] < $best['min_qty']) {
                    $best = $s;
                }
            }
        }
        if (!$best) {
            return null;
        }
        return [
            'add_qty' => $best['min_qty'] - $qty,
            'min_qty' => $best['min_qty'],
            'price'   => (float) CommonHelper::doubleNumber(TaxService::applyTax($best['price'], $taxPct, $taxInclusive)),
        ];
    }

    /**
     * The PVSS pricing row for a variant at a store. Customer & cost pricing
     * (price / discounted_price / pricing_slabs / purchase_price) live here.
     * With no store context, returns the cheapest listed store row (used for
     * "from" prices / representative displays).
     */
    public static function storePriceRow($product_variant_id, $store_id = null)
    {
        $q = ProductVariantStoreStock::where('product_variant_id', $product_variant_id);
        if ($store_id) {
            return $q->where('store_id', $store_id)->first();
        }
        return $q->where('is_listed', 1)
            ->orderByRaw('COALESCE(NULLIF(discounted_price, 0), price) ASC')
            ->first();
    }

    /**
     * Copy a store's PVSS pricing onto an in-memory variant (object or array)
     * so downstream calculators (slabUnitPrice, shapers) read the store price
     * transparently. Prefers an eager-loaded storeStocks relation to avoid a
     * query. With no store, uses the cheapest listed store row.
     */
    public static function applyStorePrice($variant, $store_id = null)
    {
        if (!$variant) {
            return $variant;
        }
        $vid = is_array($variant) ? ($variant['id'] ?? null) : ($variant->id ?? null);

        $row = null;
        if (!is_array($variant) && method_exists($variant, 'relationLoaded') && $variant->relationLoaded('storeStocks')) {
            $stocks = $variant->storeStocks;
            if ($store_id) {
                $row = $stocks->firstWhere('store_id', (int) $store_id);
            } else {
                $row = $stocks->where('is_listed', 1)
                    ->sortBy(fn ($s) => ((float) $s->discounted_price > 0 ? (float) $s->discounted_price : (float) $s->price) ?: INF)
                    ->first();
            }
        }
        if (!$row && $vid) {
            $row = self::storePriceRow($vid, $store_id);
        }

        $price    = $row ? (float) $row->price : 0.0;
        $disc     = $row ? (float) $row->discounted_price : 0.0;
        $slabs    = $row ? $row->pricing_slabs : null;
        $purchase = $row ? (float) $row->purchase_price : 0.0;
        $inclusive = $row ? (bool) ($row->is_tax_inclusive ?? false) : false;

        if (is_array($variant)) {
            $variant['price'] = $price;
            $variant['discounted_price'] = $disc;
            $variant['pricing_slabs'] = $slabs;
            $variant['purchase_price'] = $purchase;
            $variant['is_tax_inclusive'] = $inclusive;
        } else {
            $variant->price = $price;
            $variant->discounted_price = $disc;
            $variant->pricing_slabs = $slabs;
            $variant->purchase_price = $purchase;
            $variant->is_tax_inclusive = $inclusive;
        }
        return $variant;
    }

    /**
     * Which store's price row applyStorePrice() would use for this variant — i.e.
     * who is actually selling it. Tax must be resolved against that same store,
     */
    public static function priceStoreId($variant, $store_id = null): ?int
    {
        if ($store_id) {
            return (int) $store_id;
        }
        if (!$variant) {
            return null;
        }
        if (!is_array($variant) && method_exists($variant, 'relationLoaded') && $variant->relationLoaded('storeStocks')) {
            $row = $variant->storeStocks->where('is_listed', 1)
                ->sortBy(fn ($s) => ((float) $s->discounted_price > 0 ? (float) $s->discounted_price : (float) $s->price) ?: INF)
                ->first();
            if ($row) {
                return (int) $row->store_id;
            }
        }
        $vid = is_array($variant) ? ($variant['id'] ?? null) : ($variant->id ?? null);
        $row = $vid ? self::storePriceRow($vid, null) : null;
        return $row ? (int) $row->store_id : null;
    }

    /**
     * Resolve which store a cart row should be pinned to. With products.store_id
     * gone, the store is derived from the variant's PVSS rows: prefer a store
     * inside the customer's zone (if lat/lng passed), otherwise fall back to
     * the first listed-and-active store stocking the variant.
     */
    public static function resolveCartStore($product_variant_id, $latitude = null, $longitude = null, $channel = null): ?int
    {
        $storeIds = [];
        if ($latitude !== null && $longitude !== null) {
            $zone = CommonHelper::getDeliverableCity($latitude, $longitude, $channel);
            if ($zone) {
                // The matched zone's own channel decides which store slot to look in.
                $storeIds = Store::where('status', 1)
                    ->where('zone_id', $zone->id)
                    ->pluck('id')->all();
            }
        }

        $q = ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('is_listed', 1);
        if (!empty($storeIds)) {
            $q->whereIn('store_id', $storeIds);
        }

        $row = $q->orderByDesc('is_unlimited_stock')
            ->orderByDesc('stock_status')
            ->orderByDesc('available')
            ->first();
        return $row ? (int) $row->store_id : null;
    }

    /* ----------------------------------------------------------------------
     * Per-store inventory reservation (PVSS = source of truth).
     *
     *   reserve (cart add):       available -= q, reserved += q
     *   release (cart remove):    available += q, reserved -= q
     *   commit  (order placed):   reserved  -= q   (available already reduced)
     *
     * Each reserved unit ends in exactly ONE of commit or release, so stock
     * never double-counts. Unlimited-stock products skip reservation entirely.
     * All updates are atomic (single UPDATE with column expressions) to avoid
     * lost updates under concurrency.
     * -------------------------------------------------------------------- */

    /** Units a variant has available to reserve in a given store (0 if no row). */
    public static function storeAvailableStock($product_variant_id, $store_id): int
    {
        if (!$store_id) {
            return 0;
        }
        $row = ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('store_id', $store_id)
            ->first(['available']);
        return $row ? (int) $row->available : 0;
    }

    /** Total available units of a variant across all its stores (PVSS sum). */
    public static function totalStock($product_variant_id): int
    {
        return (int) ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->sum('available');
    }

    public static function isVariantUnlimited($product_variant_id, $store_id = null): bool
    {
        if (!$store_id) {
            return false;
        }
        return (int) (ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('store_id', $store_id)
            ->value('is_unlimited_stock') ?? 0) === 1;
    }

    /** Does the variant have at least one store where stock is unlimited? */
    public static function isVariantUnlimitedAnywhere($product_variant_id): bool
    {
        return ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('is_unlimited_stock', 1)
            ->exists();
    }

    /** Reserve $qty: available -= qty, reserved += qty. No-op for unlimited / missing row. */
    public static function reserveStock($product_variant_id, $store_id, $qty): void
    {
        if (!$store_id || $qty <= 0 || self::isVariantUnlimited($product_variant_id, $store_id)) {
            return;
        }
        ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('store_id', $store_id)
            ->update([
                'available'    => DB::raw('GREATEST(available - ' . (int) $qty . ', 0)'),
                'reserved'     => DB::raw('reserved + ' . (int) $qty),
                'stock_status' => DB::raw('IF(available - ' . (int) $qty . ' > 0, 1, 0)'),
            ]);
    }

    /** Release $qty back (cart remove): available += qty, reserved -= qty. */
    public static function releaseStock($product_variant_id, $store_id, $qty): void
    {
        if (!$store_id || $qty <= 0 || self::isVariantUnlimited($product_variant_id, $store_id)) {
            return;
        }
        ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('store_id', $store_id)
            ->update([
                'available'    => DB::raw('available + ' . (int) $qty),
                'reserved'     => DB::raw('GREATEST(reserved - ' . (int) $qty . ', 0)'),
                'stock_status' => DB::raw('1'),
            ]);
        self::notifyBackInStock($product_variant_id, $store_id);
    }

    /** Restock $qty back to available (order cancel/return after commit): available += qty. */
    public static function restockStock($product_variant_id, $store_id, $qty): void
    {
        if (!$store_id || $qty <= 0 || self::isVariantUnlimited($product_variant_id, $store_id)) {
            return;
        }
        ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('store_id', $store_id)
            ->update([
                'available'    => DB::raw('available + ' . (int) $qty),
                'stock_status' => DB::raw('1'),
            ]);
        self::notifyBackInStock($product_variant_id, $store_id);
    }

    public static function notifyBackInStock($product_variant_id, $store_id): void
    {
        if (!$product_variant_id || !$store_id) {
            return;
        }
        try {
            if (!ProductStockAlert::waiting()->where('product_variant_id', (int) $product_variant_id)->exists()) {
                return;
            }
            $row = ProductVariantStoreStock::where('product_variant_id', (int) $product_variant_id)
                ->where('store_id', (int) $store_id)
                ->first();
            if (!$row || (int) $row->is_listed !== 1) {
                return;
            }
            $inStock = (int) $row->is_unlimited_stock === 1
                || ((int) $row->stock_status === 1 && (int) $row->available > 0);
            if (!$inStock) {
                return;
            }

            $job = new SendBackInStockNotification((int) $product_variant_id, (int) $store_id);
            config('queue.default') === 'sync' ? dispatch($job)->afterResponse() : dispatch($job);
        } catch (\Throwable $e) {
            // Never let a notification stop a stock write.
            Log::error('notifyBackInStock: ' . $e->getMessage());
        }
    }

    /** Commit $qty at checkout: reserved -= qty (available already reduced at reserve). */
    public static function commitReservedStock($product_variant_id, $store_id, $qty): void
    {
        if (!$store_id || $qty <= 0 || self::isVariantUnlimited($product_variant_id, $store_id)) {
            return;
        }
        $qty = (int) $qty;
        ProductVariantStoreStock::where('product_variant_id', $product_variant_id)
            ->where('store_id', $store_id)
            ->update([
                'available'    => DB::raw('GREATEST(available - GREATEST(' . $qty . ' - reserved, 0), 0)'),
                'reserved'     => DB::raw('GREATEST(reserved - ' . $qty . ', 0)'),
                'stock_status' => DB::raw('IF(available > 0, 1, 0)'),
            ]);
    }

    public static function isItemAvailable($product_id, $product_variant_id)
    {

        $variant = ProductVariant::where('product_id', $product_id)->where('id', $product_variant_id)->first();
        if ($variant) {
            $product = Product::where('id', $product_id)->where('status', 1)->first();
            return !empty($product);
        } else {
            return false;
        }
    }

    public static function isItemAvailableWithStock($product_id = null, $product_variant_id = null, $qty = 0, $store_id = null, $alreadyReserved = 0)
    {
        // Fetch the variant first
        $variant = ProductVariant::where('id', $product_variant_id)
            ->first();

        if (!$variant) {
            return false;
        }

        // Use product_id from the variant if not provided
        $product_id = $product_id ?? $variant->product_id;

        // Fetch the product
        $product = Product::where('id', $product_id)->where('status', 1)->first();

        if (!$product) {
            return false;
        }

        $unlimited = $store_id
            ? self::isVariantUnlimited($product_variant_id, $store_id)
            : self::isVariantUnlimitedAnywhere($product_variant_id);
        if ($unlimited) {
            return true;
        }

        // Stock is per-store (PVSS). When a store is given, check that store's
        // availability; otherwise fall back to the sum across all stores.
        $stock = $store_id
            ? self::storeAvailableStock($product_variant_id, $store_id)
            : self::totalStock($product_variant_id);

        return ($stock + max(0, (int) $alreadyReserved)) >= $qty;
    }

    public static function isItemAvailableInUserCart($user_id, $product_variant_id = "", $channel = null)
    {
        $cart = Cart::where('user_id', $user_id);
        if ($product_variant_id != '') {
            $cart->where('product_variant_id', $product_variant_id);
        }
        // Quick and ecommerce carts are separate lines; a variant in the ecommerce
        // cart must not count as "already in cart" for a quick add. Legacy null rows
        // match either channel.
        if (in_array($channel, ['quick', 'ecommerce'], true)) {
            $cart->where(function ($q) use ($channel) {
                $q->where('channel', $channel)->orWhereNull('channel');
            });
        }
        return $cart->exists();
    }

    /**
     * Tax-inclusive pricing for a variant. Price is per-store (PVSS): pass the
     * fulfilling $store_id; with none, the cheapest listed store row is used.
     *
     * The rate is resolved through TaxService, so the SAME variant is taxed
     * differently by an India store and a Dubai store. When no store is passed the
     * store behind the chosen price row is used — the price and the tax must come
     * from the same seller or they describe two different offers.
     *
     * $buyer_region_id only affects how the total splits into components (India
     * CGST+SGST vs IGST); the total itself is identical either way, which is what
     * lets the cart quote tax before an address is chosen.
     *
     * Returns the same stdClass shape callers rely on (price, discounted_price,
     * percentage, taxable_amount, taxable_price, taxable_discounted_price) plus the
     * resolved components for anything that needs the breakdown.
     */
    public static function getTaxableAmount($product_variant_id, $store_id = null, $buyer_region_id = null)
    {
        $variant = DB::table('product_variants')->where('id', $product_variant_id)->first(['id', 'product_id']);
        if (!$variant) {
            return null;
        }

        $row = self::storePriceRow($product_variant_id, $store_id);
        $price = (float) ($row->price ?? 0);
        $disc  = (float) ($row->discounted_price ?? 0);

        $sellerStoreId = $store_id ?: ($row->store_id ?? null);
        $resolved = $sellerStoreId
            ? TaxService::forProduct((int) $variant->product_id, (int) $sellerStoreId, $buyer_region_id)
            : null;
        $percentage = (float) ($resolved['total_rate'] ?? 0);
        // Inclusivity belongs to this store's price row, not to the rule.
        $inclusive = (bool) ($row->is_tax_inclusive ?? false);

        $result = new \stdClass();
        $result->id = (int) $variant->id;
        $result->price = $price;
        $result->discounted_price = $disc;
        $result->percentage = $percentage;
        $result->taxable_price = TaxService::applyTax($price, $percentage, $inclusive);
        $result->taxable_discounted_price = TaxService::applyTax($disc, $percentage, $inclusive);

        // Effective taxable amount: discounted (if set) else base, tax applied.
        $base = $disc != 0 ? $disc : $price;
        $result->taxable_amount = TaxService::applyTax($base, $percentage, $inclusive);

        // Breakdown for callers that snapshot or display it. Empty when untaxed.
        $result->tax_rule_id = $resolved['rule_id'] ?? null;
        $result->tax_category_id = $resolved['tax_category_id'] ?? null;
        $result->tax_components = $resolved['components'] ?? [];
        $result->tax_inclusive = $inclusive;
        $result->supply_type = $resolved['supply_type'] ?? null;
        $result->seller = $resolved['seller'] ?? null;

        return $result;
    }
}
