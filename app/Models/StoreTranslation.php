<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreTranslation extends Model
{
    use HasFactory;

    protected $table = 'store_translations';

    protected $fillable = [
        'store_id',
        'language_id',
        'name',
        'provider',
        'address',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }
}
