<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use App\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable,HasRoles, LogsActivity;

    protected $appends = ['allPermissions','delivery_boy_status'];
    protected $hidden = ['password'];

    protected $fillable = ['username','email','password','role_id','created_by','store_id','is_store_owner'];

    public function role(){
        return $this->hasOne(Role::class,'id','role_id');
    }

    public function store(){
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function isStoreUser(): bool
    {
        return $this->store_id !== null;
    }

    public function getAllPermissionsAttribute()
    {
        $permissions = [];
       
        if ($this->role){
            $rolePermissions = DB::table('role_has_permissions')
                ->where('role_id', $this->role->id)
                ->get()->pluck('permission_id')->toArray();
        $permissions = Permission::whereIn('id', $rolePermissions)->get()->pluck('name')->toArray();
        }
        return $permissions;
    }

    public function notifications()
    {
        return $this->morphMany(PanelNotification::class, 'notifiable')->orderBy('created_at', 'desc');
    }

    public function deliveryBoy(){
        return $this->belongsTo(DeliveryBoy::class,'id','admin_id');
    }

    public function getDeliveryBoyStatusAttribute()
    {
        $status = 0;
        if($this->deliveryBoy){
            $status = $this->deliveryBoy->status;
        }
        return $status;
    }
}
