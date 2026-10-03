<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialMedia extends Model
{
    use HasFactory;
    public $timestamps = false;

    protected $appends = ['icon_url'];

    /** Public URL for the uploaded icon (icon column now stores a file path). */
    public function getIconUrlAttribute()
    {
        return !empty($this->icon) ? asset('storage/' . $this->icon) : null;
    }
}
