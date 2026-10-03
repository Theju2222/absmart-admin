<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariantStoreStock extends Model
{
    use HasFactory;

    protected $table = 'product_variant_store_stocks';

    protected $guarded = [];

    protected $casts = [
        'is_listed'          => 'integer',
        'is_unlimited_stock' => 'integer',
        'stock_status'     => 'integer',
        'available'        => 'integer',
        'reserved'         => 'integer',
        'min_alert'        => 'integer',
        'price'            => 'float',
        'discounted_price' => 'float',
        'purchase_price'   => 'float',
        'pricing_slabs'    => 'array',
    ];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
