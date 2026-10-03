<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Role extends Model
{
    use LogsActivity;


    public static $roleSuperAdmin = 1;
    public static $roleAdmin = 2;
    public static $roleDeliveryBoy = 3;
    public static $roleStore = 4;

    public static $roleNameSuperAdmin = "Super Admin";
    public static $roleNameAdmin = "Admin";
    public static $roleNameDeliveryBoy = "Delivery Boy";
    public static $roleNameStore = "Store";

    public static function storeRoleId(): int
    {
        static $id = null;
        if ($id === null) {
            $id = (int) (\Spatie\Permission\Models\Role::where('name', self::$roleNameStore)->value('id')
                ?: self::$roleStore);
        }
        return $id;
    }

    /** Store-panel management perms — always granted to a store OWNER (not admin-pickable). */
    public static function storeOwnerMgmtPerms(): array
    {
        return ['store_user_manage', 'store_role_manage'];
    }

    public static function storePermissionNames(): array
    {
        return array_merge([
            'manage_dashboard',
            'product_list', 'product_create', 'product_update', 'stock_management',
            // View-only reference listings (store can see, not mutate).
            'category_list', 'brand_list', 'tax_list', 'attribute_list',
            'order_list', 'order_update',
            'self_pickup_order_list', 'self_pickup_order_update',
            'return_request_list', 'return_request_update',
            'promo_code_list', 'promo_code_create', 'promo_code_update', 'promo_code_delete',
            'home_builder_list', 'home_builder_create', 'home_builder_update', 'home_builder_delete', 'home_builder_publish',
            'customer_list', 'transaction_list', 'manage_customer_wallet',
            'report_sales', 'report_orders', 'report_products', 'report_customers',
            'report_inventory', 'report_returns', 'report_delivery', 'report_payment',
            'report_category', 'report_promo',
            'delivery_boy_list', 'delivery_boy_create', 'delivery_boy_update', 'delivery_boy_delete',
            'delivery_boy_wallet_transactions_list', 'cash_collection_list', 'cash_collection_create',
            'delivery_boy_salary_list', 'delivery_boy_salary_create', 'delivery_boy_salary_delete',
            'chat',
            'notification_list', 'send_notification', 'notification_delete',
            'manage_emails', 'send_email', 'delete_send_email',
        ], self::storeOwnerMgmtPerms());
    }

    /** Admin-pickable owner permissions (everything except the always-on mgmt perms). */
    public static function storePermissionCatalog(): array
    {
        return array_values(array_diff(self::storePermissionNames(), self::storeOwnerMgmtPerms()));
    }
}
