<?php

namespace App\Models;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Product extends Model
{

    use HasFactory, HasTranslations, LogsActivity;
    protected $fillable = [
        'name',
        'category_id',
        'product_type',
        'is_prescription_required',
        'manufacturer',
        'made_in',
        'return_status',
        'cancelable_status',
        'till_status_quick',
        'till_status_ecommerce',
        'description',
        'short_description',
        'image',
        'sales_channel',
        'brand_id',
        'return_days',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'schema_markup',
        'is_draft',
    ];

    protected $appends = ['image_url', 'translations'];

    protected $hidden=['created_at','updated_at','deleted_at'];


    public function madeInCountry(){
        return $this->belongsTo(Country::class,'made_in','id');
    }

    public function category(){
        return $this->belongsTo(Category::class,'category_id','id');
    }

    public function variants(){

        return $this->hasMany(ProductVariant::class,'product_id','id');
    }

    public function images(){

        return $this->hasMany(ProductImages::class,'product_id','id')
            ->where('product_variant_id',0);
    }

    public function brand(){
        return $this->belongsTo(Brand::class,'brand_id','id');
    }

    public function getImageUrlAttribute(){
        if($this->image){
            return asset('storage/'.$this->image);
        }
        return '';
    }

    // `name` is a real translatable column now. HasTranslations::getAttribute()
    // intercepts translatable keys before accessors, so a getNameAttribute() here
    // would never run — resolution is translation.name -> products.name.

    public function ratings()
    {
        return $this->hasMany(ProductRating::class, 'product_id');
    }
    // Multi-language support
    protected $translatable = [
        'name',
        'tags',
        'manufacturer',
        'made_in',
        'description',
        'short_description',
        'meta_title',
        'meta_keywords',
        'schema_markup',
        'meta_description',
    ];

    protected $translationModel = 'ProductTranslation';
    protected $translationForeignKey = 'product_id';

    public function taxCategory()
    {
        return $this->belongsTo(TaxCategory::class, 'tax_category_id');
    }

}
