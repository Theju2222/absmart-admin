<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImages extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'image',
    ];

    protected $hidden = [];
    protected $appends = ['image_url'];

    public function getImageUrlAttribute(){
        if($this->image){
            $image_url = asset('storage/'.$this->image);
            return $image_url;
        }
        return $this->image;
    }
}
