<?php

use App\Http\Controllers\API\AdminAuthController;
use App\Http\Controllers\API\BlogsApiController;
use App\Http\Controllers\API\AttributeApiController;
use App\Http\Controllers\API\BrandsApiController;
use App\Http\Controllers\API\CashCollectionApiController;
use App\Http\Controllers\API\CategoryApiController;
use App\Http\Controllers\API\ZoneApiController;
use App\Http\Controllers\API\DeliveryCityApiController;
use App\Http\Controllers\API\DeliveryAreaApiController;
use App\Http\Controllers\API\CountryApiController;
use App\Http\Controllers\API\ActivityLogApiController;
use App\Http\Controllers\API\CronApiController;
use App\Http\Controllers\API\Customer\ProductsApiController;
use App\Http\Controllers\API\CustomersApiController;
use App\Http\Controllers\API\DeliveryBoysApiController;
use App\Http\Controllers\API\DeliveryBoySalaryApiController;
use App\Http\Controllers\API\EmailTemplatesApiController;
use App\Http\Controllers\API\EmailsApiController;
use App\Http\Controllers\API\FaqsApiController;
use App\Http\Controllers\API\FirebaseApiController;
use App\Http\Controllers\API\HomeLayoutApiController;
use App\Http\Controllers\API\PageLayoutApiController;
use App\Http\Controllers\API\HomeLayoutTemplateApiController;
use App\Http\Controllers\API\LanguageApiController;
use App\Http\Controllers\API\MediaApiController;
use App\Http\Controllers\API\NotificationPanelApiController;
use App\Http\Controllers\API\NotificationTemplatesApiController;
use App\Http\Controllers\API\NotificationsApiController;
use App\Http\Controllers\API\OrderStatusApiController;
use App\Http\Controllers\API\OrdersApiController;
use App\Http\Controllers\API\Customer\OrderApiController as CustomerOrderApiController;
use App\Http\Controllers\API\ChatApiController;
use App\Http\Controllers\API\DashboardApiController;
use App\Http\Controllers\API\DeliveryBoySettlementsApiController;
use App\Http\Controllers\API\PopupApiController;
use App\Http\Controllers\API\ProductApisController;
use App\Http\Controllers\API\ProductBulkApiController;
use App\Http\Controllers\API\StockApiController;
use App\Http\Controllers\API\PromoCodeApiController;
use App\Http\Controllers\API\SpinWheelApiController;
use App\Http\Controllers\API\ReturnRequestsApiController;
use App\Http\Controllers\API\RoleApiController;
use App\Http\Controllers\API\ReportsApiController;
use App\Http\Controllers\API\SeoSettingsApiController;
use App\Http\Controllers\API\SmsSettingsApiController;
use App\Http\Controllers\API\SmsTemplatesApiController;
use App\Http\Controllers\API\SocialMediaApiController;
use App\Http\Controllers\API\StoreApiController;
use App\Http\Controllers\API\GeneralSettingsApiController;
use App\Http\Controllers\API\NotificationPreferencesApiController;
use App\Http\Controllers\API\NotificationSettingsApiController;
use App\Http\Controllers\API\SystemUsersApiController;
use App\Http\Controllers\API\StorePanelApiController;
use App\Http\Controllers\API\TaxRuleApiController;
use App\Http\Controllers\API\TaxCategoryApiController;
use App\Http\Controllers\API\RegionApiController;
use App\Http\Controllers\API\TransactionsApiController;
use App\Http\Controllers\API\TranslateApiController;
use App\Http\Controllers\API\WalletTransactionsApiController;
use App\Http\Controllers\API\WithdrawalRequestsApiController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InstallController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

const EDIT_ID = 'edit/{id}';

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['prefix' => 'install'], function () {
    Route::get('check-composer-updates', [InstallController::class, 'checkUpdates'])->name('checkComposerUpdates');
    Route::get('requirements', [InstallController::class, 'getRequirements']);
    Route::post('database', [InstallController::class, 'setDatabase']);
    Route::post('purchase_code', [InstallController::class, 'checkPurchaseCode']);
});

Route::post('login', [AdminAuthController::class, 'login']);
Route::post('forgot_password', [AdminAuthController::class, 'forgetPassword'])->name('forget-password');
Route::post('reset-password', [AdminAuthController::class, 'resetPassword'])->name('reset-password');
Route::get('system_languages', [LanguageApiController::class, 'getSystemLanguages']);
Route::get('active_languages', [LanguageApiController::class, 'getActiveLanguages']);
Route::get('password_policy', [GeneralSettingsApiController::class, 'getPasswordPolicy']);

Route::get('delivery-boy-privacy-policy', [CountryApiController::class, 'printPrivacyPolicyDeliveryBoy']);
Route::get('delivery-boy-terms-conditions', [CountryApiController::class, 'printTermsConditionsDeliveryBoy']);

Route::get('role', [RoleApiController::class, 'index']);

Route::get('categories', [CategoryApiController::class, 'getCategories']);

Route::get('zones', [ZoneApiController::class, 'getZones']);
Route::get('delivery_cities', [DeliveryCityApiController::class, 'getDeliveryCities']);
Route::get('delivery_areas', [DeliveryAreaApiController::class, 'getDeliveryAreas']);

// Map / Places routes (public). Provider-agnostic — each picks google vs OSM
// via the `map_provider` setting, so callers never branch on provider.
Route::group(['prefix' => 'maps'], function () {
    Route::get('places_autocomplete', [GeneralSettingsApiController::class, 'placesAutocomplete']);
    Route::get('places_details', [GeneralSettingsApiController::class, 'placesDetails']);
    Route::get('geocoding', [GeneralSettingsApiController::class, 'mapsGeocoding']);
});

Route::middleware(['auth:api', 'store.scope'])->group(function () {
    Route::post('broadcasting/auth', fn(Request $request) => Broadcast::auth($request));
    Route::get('clear', [GeneralSettingsApiController::class, 'clearCache']);
    Route::get('admin_settings', [Controller::class, 'getAdminSettings']);
    Route::get('dashboard', [DashboardApiController::class, 'index']);
    Route::get('get_top_notifications', [Controller::class, 'getTopNotifications']);
    Route::get('notification_read', [Controller::class, 'markAsReadNotifications']);
    Route::get('create_slug/{text}', [Controller::class, 'createSlug']);
    Route::post('update_fcm_token', [AdminAuthController::class, 'updateFcmToken'])->name('admin.update_fcm_token');
    Route::post('logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::group(['prefix' => 'categories'], function () {
        Route::get('main', [CategoryApiController::class, 'getMainCategories']);
        Route::get('chain', [CategoryApiController::class, 'getCategoryChain']);
        Route::get('active', [CategoryApiController::class, 'getActiveCategories']);
        Route::post('save', [CategoryApiController::class, 'save'])->name('categories.save');
        Route::post('update', [CategoryApiController::class, 'update'])->name('categories.update');
        Route::post('delete', [CategoryApiController::class, 'delete'])->name('categories.delete');
        Route::get('options_data', [CategoryApiController::class, 'getOptionsData']);
        Route::get('parent_check', [CategoryApiController::class, 'parentCheck']);
        Route::get('row_order', [CategoryApiController::class, 'getCategoriesByRowOrder']);
        Route::post('updateOrder', [CategoryApiController::class, 'updateCategoriesOrder'])->name('categories.updateOrder');
        Route::get('/check-slug/{slug}', [CategoryApiController::class, 'checkSlug']);
        Route::get('schema', [CategoryApiController::class, 'getSchema']);
        Route::post('attach_attribute', [CategoryApiController::class, 'attachAttribute'])->name('categories.attach_attribute');
    });

    Route::group(['prefix' => 'attributes'], function () {
        Route::get('/', [AttributeApiController::class, 'list']);
        Route::get('dropdown', [AttributeApiController::class, 'dropdown']);
        Route::get('usage', [AttributeApiController::class, 'usage']);
        Route::post('save', [AttributeApiController::class, 'save'])->name('attributes.save');
        Route::post('update', [AttributeApiController::class, 'update'])->name('attributes.update');
        Route::post('delete', [AttributeApiController::class, 'delete'])->name('attributes.delete');
    });

    Route::group(['prefix' => 'blog_categories'], function () {
        Route::get('/', [BlogsApiController::class, 'getBlogCategories']);
        Route::post('save', [BlogsApiController::class, 'createBlogCategory'])->name('blog_categories.save');
        Route::post('update', [BlogsApiController::class, 'updateBlogCategory'])->name('blog_categories.update');
        Route::post('delete/{id}', [BlogsApiController::class, 'deleteBlogCategory'])->name('blog_categories.delete');
        Route::get('dropdown', [BlogsApiController::class, 'getBlogCategoriesForDropdown']);
    });

    Route::get('blog_tags', [BlogsApiController::class, 'getBlogTags']);

    Route::group(['prefix' => 'blogs'], function () {
        Route::get('/', [BlogsApiController::class, 'getBlogs']);
        Route::post('/', [BlogsApiController::class, 'getBlogs']);
        Route::post('save', [BlogsApiController::class, 'createBlog'])->name('blogs.save');
        Route::post('update/{id}', [BlogsApiController::class, 'updateBlog'])->name('blogs.update');
        Route::post('delete/{id}', [BlogsApiController::class, 'deleteBlog'])->name('blogs.delete');
    });
    Route::post('/google_gemini', [BlogsApiController::class, 'googleGeminiAI'])->name('blogs.google_gemini');

    Route::group(['prefix' => 'seo_settings'], function () {
        Route::get('/', [SeoSettingsApiController::class, 'getSeoSettings']);
        Route::post('save', [SeoSettingsApiController::class, 'save'])->name('seo_settings.save');
        Route::post('update', [SeoSettingsApiController::class, 'update'])->name('seo_settings.update');
        Route::post('delete', [SeoSettingsApiController::class, 'delete'])->name('seo_settings.delete');
    });

    Route::group(['prefix' => 'products'], function () {
        Route::get('/', [ProductApisController::class, 'getProducts']);
        Route::get('active', [ProductApisController::class, 'getActiveProducts']);
        Route::get('variant_usage', [ProductApisController::class, 'variantUsage']);
        Route::get('demand_stats', [ProductApisController::class, 'demandStats']);
        Route::get('check_sku', [ProductApisController::class, 'checkSku']);
        Route::post('save', [ProductApisController::class, 'save'])->name('products.save');
        Route::post('update', [ProductApisController::class, 'update'])->name('products.update');
        Route::post('delete', [ProductApisController::class, 'delete'])->name('products.delete');
        Route::get(EDIT_ID, [ProductApisController::class, 'edit']);
        Route::post('change', [ProductApisController::class, 'changeStatus'])->name('products.change');

        Route::group(['prefix' => 'bulk'], function () {
            Route::get('meta', [ProductBulkApiController::class, 'meta']);
            Route::get('sample', [ProductBulkApiController::class, 'sample']);
            Route::post('upload', [ProductBulkApiController::class, 'upload'])->name('products.bulk_upload');
            Route::get('export', [ProductBulkApiController::class, 'export']);
            Route::post('update', [ProductBulkApiController::class, 'update'])->name('products.bulk_update');
            Route::get('status', [ProductBulkApiController::class, 'status']);
        });
        Route::get('ratings_list', [ProductsApiController::class, 'productRatingsList']);
        Route::group(['prefix' => 'tax_categories'], function () {
            Route::get('/', [TaxCategoryApiController::class, 'index']);
            Route::post('save', [TaxCategoryApiController::class, 'save'])->name('tax_categories.save');
            Route::post('delete', [TaxCategoryApiController::class, 'delete'])->name('tax_categories.delete');
        });
        Route::group(['prefix' => 'tax_rules'], function () {
            Route::get('/', [TaxRuleApiController::class, 'index']);
            Route::get('coverage', [TaxRuleApiController::class, 'coverage']);
            Route::get('store_rates', [TaxRuleApiController::class, 'storeRates']);
            Route::post('save', [TaxRuleApiController::class, 'save'])->name('tax_rules.save');
            Route::post('delete', [TaxRuleApiController::class, 'delete'])->name('tax_rules.delete');
        });
        Route::group(['prefix' => 'brands'], function () {
            Route::get('/', [BrandsApiController::class, 'list']);
            Route::post('save', [BrandsApiController::class, 'save'])->name('brands.save');
            Route::post('update', [BrandsApiController::class, 'update'])->name('brands.update');
            Route::post('delete', [BrandsApiController::class, 'delete'])->name('brands.delete');
            Route::get('/get', [BrandsApiController::class, 'getBrands']);
            Route::get('/products', [BrandsApiController::class, 'brandProducts']);
        });
        Route::get('variants', [ProductApisController::class, 'getProductVariants']);
        Route::get('recommendations', [ProductApisController::class, 'getRecommendations']);
        Route::get('recommendations/search', [ProductApisController::class, 'searchRecommendationProducts']);
        Route::post('recommendations/save', [ProductApisController::class, 'saveRecommendations'])->name('products.recommendations.save');
        // Stock Management (per-store PVSS inventory).
        Route::get('stock/overview', [StockApiController::class, 'overview']);
        Route::get('stock/inventory', [StockApiController::class, 'inventory']);
        Route::post('stock/adjust', [StockApiController::class, 'adjust'])->name('products.stock.adjust');
        Route::get('stock/alerts', [StockApiController::class, 'alerts']);
        Route::post('generate_description', [ProductApisController::class, 'generateProductDescription'])->name('products.generate_description');
        Route::post('generate_seo', [ProductApisController::class, 'generateProductSeo'])->name('products.generate_seo');
    });

    Route::post('/generate_seo', [ProductApisController::class, 'generateSeo'])->name('generate_seo');

    Route::group(['prefix' => 'stores'], function () {
        Route::get('/', [StoreApiController::class, 'getStores']);
        Route::post('save', [StoreApiController::class, 'save'])->name('stores.save');
        Route::post('delete', [StoreApiController::class, 'delete'])->name('stores.delete');
        Route::get('permission_catalog', [StoreApiController::class, 'permissionCatalog']);
        Route::post('save_permissions', [StoreApiController::class, 'savePermissions'])->name('stores.save_permissions');
        Route::get(EDIT_ID, [StoreApiController::class, 'edit']);
        Route::get('zones_for_type', [StoreApiController::class, 'getZonesForType']);
        Route::get('for_channel', [StoreApiController::class, 'getStoresForChannel']);
    });

    Route::group(['prefix' => 'spin_wheel'], function () {
        Route::get('/', [SpinWheelApiController::class, 'index']);
        Route::get(EDIT_ID, [SpinWheelApiController::class, 'edit']);
        Route::get('history', [SpinWheelApiController::class, 'history']);
        Route::post('save', [SpinWheelApiController::class, 'save'])->name('spin_wheel.save');
        Route::post('delete', [SpinWheelApiController::class, 'delete'])->name('spin_wheel.delete');
        Route::post('upload_image', [SpinWheelApiController::class, 'uploadImage']);
    });

    Route::group(['prefix' => 'promo_code'], function () {
        Route::get('/', [PromoCodeApiController::class, 'index']);
        Route::get(EDIT_ID, [PromoCodeApiController::class, 'edit']);
        Route::post('save', [PromoCodeApiController::class, 'save'])->name('promo_code.save');
        Route::post('update', [PromoCodeApiController::class, 'update'])->name('promo_code.update');
        Route::post('delete', [PromoCodeApiController::class, 'delete'])->name('promo_code.delete');
    });

    Route::group(['prefix' => 'sms_settings'], function () {
        Route::get('/', [SmsSettingsApiController::class, 'index']);
        Route::post('save', [SmsSettingsApiController::class, 'save'])->name('sms_settings.save');
    });

    // Audit trail (read-only; entries are written by model + auth events).
    Route::group(['prefix' => 'activity_logs'], function () {
        Route::get('/', [ActivityLogApiController::class, 'index']);
        Route::get('filters', [ActivityLogApiController::class, 'filters']);
        Route::post('clear', [ActivityLogApiController::class, 'clear'])->name('activity_logs.clear');
    });

    Route::group(['prefix' => 'cron'], function () {
        Route::get('/', [CronApiController::class, 'index']);
        Route::post('run', [CronApiController::class, 'run'])->name('cron.run');
    });

    Route::get('setup_guide', [Controller::class, 'setupGuide'])->name('setup_guide.index');

    Route::group(['prefix' => 'sms_templates'], function () {
        Route::get('/', [SmsTemplatesApiController::class, 'index']);
        Route::get(EDIT_ID, [SmsTemplatesApiController::class, 'edit']);
        Route::post('update', [SmsTemplatesApiController::class, 'update'])->name('sms_templates.update');
    });

    Route::group(['prefix' => 'store_settings'], function () {
        Route::get('/', [GeneralSettingsApiController::class, 'index']);
        Route::post('save_store_basic_setting', [GeneralSettingsApiController::class, 'save_store_basic_setting'])->name('store_settings.save_store_basic_setting');
        Route::post('save_address_setting', [GeneralSettingsApiController::class, 'save_address_setting'])->name('store_settings.save_address_setting');
        Route::post('save_other_setting', [GeneralSettingsApiController::class, 'save_other_setting'])->name('store_settings.save_other_setting');
        Route::post('save_delivery_boy_setting', [GeneralSettingsApiController::class, 'save_delivery_boy_setting'])->name('store_settings.save_delivery_boy_setting');
        Route::post('save_app_setting', [GeneralSettingsApiController::class, 'save_app_setting'])->name('store_settings.save_app_setting');
        Route::get('maintenance_setting', [GeneralSettingsApiController::class, 'maintenance_setting']);
        Route::post('save_maintenance_setting', [GeneralSettingsApiController::class, 'save_maintenance_setting'])->name('store_settings.save_maintenance_setting');
        Route::post('save_deeplink_setting', [GeneralSettingsApiController::class, 'save_deeplink_setting'])->name('store_settings.save_deeplink_setting');
        Route::post('save_smtp_mail_setting', [GeneralSettingsApiController::class, 'save_smtp_mail_setting'])->name('store_settings.save_smtp_mail_setting');
        Route::post('save_third_party_api_setting', [GeneralSettingsApiController::class, 'save_third_party_api_setting'])->name('store_settings.save_third_party_api_setting');
        Route::post('save_login_setting', [GeneralSettingsApiController::class, 'save_login_setting'])->name('store_settings.save_login_setting');
        Route::post('save_cart_setting', [GeneralSettingsApiController::class, 'save_cart_setting'])->name('store_settings.save_cart_setting');

        Route::get('invoice_setting', [GeneralSettingsApiController::class, 'invoiceSettings']);
        Route::post('save_invoice_setting', [GeneralSettingsApiController::class, 'save_invoice_setting'])->name('store_settings.save_invoice_setting');
        Route::post('invoice_setting_preview', [GeneralSettingsApiController::class, 'previewInvoiceSetting'])->name('store_settings.invoice_setting_preview');

        Route::get('broadcast_setting', [GeneralSettingsApiController::class, 'getBroadcastSetting']);
        Route::post('save_broadcast_setting', [GeneralSettingsApiController::class, 'save_broadcast_setting'])->name('store_settings.save_broadcast_setting');

        Route::post('/system_update', [GeneralSettingsApiController::class, 'system_update'])->name('store_settings.system_update');
        Route::post('/test_mail', [GeneralSettingsApiController::class, 'testMail']);
    });

    Route::group(['prefix' => 'firebase'], function () {
        Route::get('/', [FirebaseApiController::class, 'index']);
        Route::post('save', [FirebaseApiController::class, 'save'])->name('firebase.save');
    });

    Route::group(['prefix' => 'popup'], function () {
        Route::get('/', [PopupApiController::class, 'index']);
        Route::post('save', [PopupApiController::class, 'save'])->name('popup.save');
    });

    Route::group(['prefix' => 'notification_templates'], function () {
        Route::get('/', [NotificationTemplatesApiController::class, 'index']);
        Route::post('update', [NotificationTemplatesApiController::class, 'update']);
    });
    Route::group(['prefix' => 'contact_us'], function () {
        Route::get('/', [GeneralSettingsApiController::class, 'getContactUs']);
        Route::post('save', [GeneralSettingsApiController::class, 'saveContactUs'])->name('contact_us.save');
    });
    Route::group(['prefix' => 'about_us'], function () {
        Route::get('/', [GeneralSettingsApiController::class, 'getAboutUs']);
        Route::post('save', [GeneralSettingsApiController::class, 'saveAboutUs'])->name('about_us.save');
    });

    Route::group(['prefix' => 'notifications'], function () {
        Route::get('/', [NotificationsApiController::class, 'index']);
        Route::post('save', [NotificationsApiController::class, 'save'])->name('notifications.save');
        Route::post('delete', [NotificationsApiController::class, 'delete'])->name('notifications.delete');
    });
    Route::group(['prefix' => 'emails'], function () {
        Route::get('/', [EmailsApiController::class, 'index']);
        Route::post('save', [EmailsApiController::class, 'save'])->name('emails.save');
        Route::post('delete', [EmailsApiController::class, 'delete'])->name('emails.delete');
    });
    Route::group(['prefix' => 'email_templates'], function () {
        Route::get('list', [EmailTemplatesApiController::class, 'list']);
        Route::post('update_translation', [EmailTemplatesApiController::class, 'updateTranslation'])->name('email_templates.update_translation');
    });

    Route::group(['prefix' => 'notification_settings'], function () {
        Route::get('events', [NotificationSettingsApiController::class, 'events']);
        Route::get('event', [NotificationSettingsApiController::class, 'event']);
        Route::post('save_event', [NotificationSettingsApiController::class, 'saveEvent'])->name('notification_settings.save_event');
        Route::post('toggle', [NotificationSettingsApiController::class, 'toggle'])->name('notification_settings.toggle');
    });
    Route::group(['prefix' => 'home_layouts'], function () {
        Route::get('/', [HomeLayoutApiController::class, 'getLayouts']);
        Route::get(EDIT_ID, [HomeLayoutApiController::class, 'edit']);
        Route::post('save', [HomeLayoutApiController::class, 'save'])->name('home_layouts.save');
        Route::post('publish', [HomeLayoutApiController::class, 'publish'])->name('home_layouts.publish');
        Route::post('schedule', [HomeLayoutApiController::class, 'schedule'])->name('home_layouts.schedule');
        Route::post('clone', [HomeLayoutApiController::class, 'clone'])->name('home_layouts.clone');
        Route::post('toggle_active', [HomeLayoutApiController::class, 'toggleActive'])->name('home_layouts.toggle_active');
        Route::post('delete', [HomeLayoutApiController::class, 'delete'])->name('home_layouts.delete');
        Route::post('upload_image', [HomeLayoutApiController::class, 'uploadImage']);
    });
    // Page Builder — same section system as home layouts, minus zone/channel.
    Route::group(['prefix' => 'page_layouts'], function () {
        Route::get('/', [PageLayoutApiController::class, 'getPages']);
        Route::get('options', [PageLayoutApiController::class, 'options']);
        Route::get(EDIT_ID, [PageLayoutApiController::class, 'edit']);
        Route::post('save', [PageLayoutApiController::class, 'save'])->name('page_layouts.save');
        Route::post('publish', [PageLayoutApiController::class, 'publish'])->name('page_layouts.publish');
        Route::post('clone', [PageLayoutApiController::class, 'clone'])->name('page_layouts.clone');
        Route::post('toggle_active', [PageLayoutApiController::class, 'toggleActive'])->name('page_layouts.toggle_active');
        Route::post('delete', [PageLayoutApiController::class, 'delete'])->name('page_layouts.delete');
        Route::post('upload_image', [PageLayoutApiController::class, 'uploadImage']);
    });
    Route::group(['prefix' => 'home_layout_templates'], function () {
        Route::get('/',       [HomeLayoutTemplateApiController::class, 'index']);
        Route::post('save',   [HomeLayoutTemplateApiController::class, 'save']);
        Route::post('delete', [HomeLayoutTemplateApiController::class, 'delete']);
    });

    // Admin management of delivery boys. edit/{id} without an id falls back to
    // the authenticated delivery boy's own profile (self-service).
    Route::group(['prefix' => 'delivery_boys'], function () {
        Route::get('/', [DeliveryBoysApiController::class, 'getDeliveryBoy']);
        Route::get('bonus_settings', [DeliveryBoysApiController::class, 'getDeliveryBoyBonusSettings']);
        Route::get('detail/{id}', [DeliveryBoysApiController::class, 'getDeliveryBoyDetail'])->where('id', '[0-9]+');
        Route::get('edit/{id?}', [DeliveryBoysApiController::class, 'edit'])->name('delivery_boys.edit');
        Route::post('save', [DeliveryBoysApiController::class, 'save'])->name('delivery_boys.save');
        Route::post('update', [DeliveryBoysApiController::class, 'update'])->name('delivery_boys.update');
        Route::post('delete', [DeliveryBoysApiController::class, 'delete'])->name('delivery_boys.delete');
        Route::post('update_delivery_boy_status', [DeliveryBoysApiController::class, 'updateStatus'])->name('delivery_boys.update_delivery_boy_status');
        Route::get('settlement_history', [DeliveryBoySettlementsApiController::class, 'index']);
    });

    Route::group(['prefix' => 'cash_collection'], function () {
        Route::get('/', [CashCollectionApiController::class, 'getCashCollection']);
        Route::post('save', [CashCollectionApiController::class, 'save'])->name('cash_collection.save');
    });

    // Delivery-boy salary transactions (standalone payout log, scoped by the boy's country).
    Route::group(['prefix' => 'salary_transactions'], function () {
        Route::get('/', [DeliveryBoySalaryApiController::class, 'getList']);
        Route::post('add', [DeliveryBoySalaryApiController::class, 'store'])->name('salary_transactions.add');
        Route::post('update', [DeliveryBoySalaryApiController::class, 'update'])->name('salary_transactions.update');
        Route::post('delete', [DeliveryBoySalaryApiController::class, 'delete'])->name('salary_transactions.delete');
    });

    Route::group(['prefix' => 'web_settings'], function () {
        Route::get('/', [GeneralSettingsApiController::class, 'webSettingsIndex']);
        Route::post('save', [GeneralSettingsApiController::class, 'saveWebSettings'])->name('web_settings.save');
    });

    Route::group(['prefix' => 'social_media'], function () {
        Route::get('/', [SocialMediaApiController::class, 'index']);
        Route::post('save', [SocialMediaApiController::class, 'save'])->name('social_media.save');
        Route::post('update', [SocialMediaApiController::class, 'update'])->name('social_media.update');
        Route::post('delete', [SocialMediaApiController::class, 'delete'])->name('social_media.delete');
    });

    Route::group(['prefix' => 'customers'], function () {
        Route::get('/', [CustomersApiController::class, 'getCustomers']);
        Route::post('set_status', [CustomersApiController::class, 'setStatus'])->name('customers.set_status');
        Route::get('{id}', [CustomersApiController::class, 'getCustomerDetail'])->where('id', '[0-9]+');
    });

    Route::group(['prefix' => 'wallet_transactions'], function () {
        Route::get('/', [WalletTransactionsApiController::class, 'index']);
        Route::post('save', [WalletTransactionsApiController::class, 'save'])->name('wallet_transactions.save');
    });

    Route::group(['prefix' => 'withdrawal_requests'], function () {
        Route::get('/', [WithdrawalRequestsApiController::class, 'getWithdrawalRequests']);
        Route::post('update', [WithdrawalRequestsApiController::class, 'update'])->name('withdrawal_requests.update');
        Route::post('delete', [WithdrawalRequestsApiController::class, 'delete'])->name('withdrawal_requests.delete');
    });

    Route::group(['prefix' => 'transactions'], function () {
        Route::get('/', [TransactionsApiController::class, 'index']);
    });
    Route::group(['prefix' => 'wishlists'], function () {
        Route::get('/', [CustomersApiController::class, 'getWishlists']);
    });
    // Customers' "notify me when back in stock" requests.
    Route::get('stock_alerts', [CustomersApiController::class, 'getStockAlerts']);
    Route::group(['prefix' => 'carts'], function () {
        Route::get('/', [CustomersApiController::class, 'getCarts']);
        Route::post('/remove', [CustomersApiController::class, 'removeCart'])->name('carts.remove');
    });

    Route::group(['prefix' => 'system_users'], function () {
        Route::get('/', [SystemUsersApiController::class, 'index']);
        Route::post('save', [SystemUsersApiController::class, 'save'])->name('system_users.save');
        Route::post('update', [SystemUsersApiController::class, 'update'])->name('system_users.update');
        Route::post('delete', [SystemUsersApiController::class, 'delete'])->name('system_users.delete');
        Route::post('change_password', [SystemUsersApiController::class, 'changePassword'])->name('system_users.change_password');
    });

    // Store-panel self-management (store owner creates its own sub-users + roles).
    Route::group(['prefix' => 'store_users'], function () {
        Route::get('/', [StorePanelApiController::class, 'users']);
        Route::post('save', [StorePanelApiController::class, 'saveUser'])->name('store_users.save');
        Route::post('update', [StorePanelApiController::class, 'updateUser'])->name('store_users.update');
        Route::post('delete', [StorePanelApiController::class, 'deleteUser'])->name('store_users.delete');
    });
    Route::group(['prefix' => 'store_roles'], function () {
        Route::get('/', [StorePanelApiController::class, 'roles']);
        Route::get('permissions', [StorePanelApiController::class, 'rolePermissions']);
        Route::get('edit/{id}', [StorePanelApiController::class, 'editRole']);
        Route::post('save', [StorePanelApiController::class, 'saveRole'])->name('store_roles.save');
        Route::post('update', [StorePanelApiController::class, 'updateRole'])->name('store_roles.update');
        Route::post('delete', [StorePanelApiController::class, 'deleteRole'])->name('store_roles.delete');
    });
    // Store owner Account Settings — own profile + own notification preferences.
    Route::group(['prefix' => 'store_panel'], function () {
        Route::get('profile', [StoreApiController::class, 'myProfile']);
        Route::post('profile', [StoreApiController::class, 'saveMyProfile'])->name('store_panel.profile.save');
        Route::get('notification_preferences', [NotificationPreferencesApiController::class, 'storeIndex']);
        Route::post('notification_preferences', [NotificationPreferencesApiController::class, 'storeSave'])->name('store_panel.notification_preferences.save');
        // Self change-password (operates on the authenticated store user).
        Route::post('change_password', [SystemUsersApiController::class, 'changePassword'])->name('store_panel.change_password');
    });

    Route::group(['prefix' => 'return_requests'], function () {
        Route::get('/', [ReturnRequestsApiController::class, 'index']);
        Route::post('save', [ReturnRequestsApiController::class, 'save'])->name('return_requests.save');
        Route::post('update', [ReturnRequestsApiController::class, 'update'])->name('return_requests.update');
        Route::post('delete', [ReturnRequestsApiController::class, 'delete'])->name('return_requests.delete');
        Route::post('delivery_boy', [ReturnRequestsApiController::class, 'deliveryBoyReturnRequests'])->name('return_requests.delivery_boy');
    });

    /*
     * Admin reports. Every report takes the header country_id/zone_id + a period
     * (today | last_7_days | this_month | last_quarter | all — default this_month),
     * and can be exported as csv | xlsx | pdf with the same filters.
     */
    Route::group(['prefix' => 'reports'], function () {
        Route::get('sales', [ReportsApiController::class, 'sales']);
        Route::get('orders', [ReportsApiController::class, 'orders']);
        Route::get('products', [ReportsApiController::class, 'products']);
        Route::get('customers', [ReportsApiController::class, 'customers']);
        Route::get('inventory', [ReportsApiController::class, 'inventory']);
        Route::get('returns', [ReportsApiController::class, 'returns']);
        Route::get('delivery', [ReportsApiController::class, 'delivery']);
        Route::get('payment', [ReportsApiController::class, 'payment']);
        Route::get('category', [ReportsApiController::class, 'category']);
        Route::get('promo', [ReportsApiController::class, 'promo']);
        Route::get('tax', [ReportsApiController::class, 'taxSummary']);
        Route::get('tax_orders', [ReportsApiController::class, 'taxOrders']);

        Route::get('{type}/export', [ReportsApiController::class, 'export'])
            ->where('type', 'sales|orders|products|customers|inventory|returns|delivery|payment|category|promo|tax|tax_orders');
    });

    Route::group(['prefix' => 'order_statuses'], function () {
        Route::get('/', [OrderStatusApiController::class, 'getOrderStatus']);
    });

    Route::group(['prefix' => 'orders'], function () {
        Route::get('/', [OrdersApiController::class, 'getOrders']);
        Route::get('/view/{id}', [OrdersApiController::class, 'view']);

        Route::post('invoice_download', [OrdersApiController::class, 'downloadOrderInvoice']);
        Route::post('item_invoice_download', [OrdersApiController::class, 'downloadOrderItemInvoice']);
        Route::post('delivery_receipt_download', [OrdersApiController::class, 'downloadDeliveryReceipt']);

        Route::post('/delete', [OrdersApiController::class, 'delete'])->name('orders.delete');
        Route::post('/delete_item', [OrdersApiController::class, 'deleteItem'])->name('orders.deleteItem');

        Route::post('/update_status', [OrdersApiController::class, 'updateStatus'])->name('orders.update_status');
        Route::post('/assign_delivery_boy', [OrdersApiController::class, 'assignDeliveryBoy'])->name('orders.assign_delivery_boy');
        Route::post('/assign_delivery_boy_to_item', [OrdersApiController::class, 'assignDeliveryBoyToItem'])->name('orders.assign_delivery_boy_to_item');
        Route::post('/assign_delivery_boy_to_items', [OrdersApiController::class, 'assignDeliveryBoyToItems'])->name('orders.assign_delivery_boy_to_items');
        Route::post('/save_order_tracking', [OrdersApiController::class, 'saveOrderTracking'])->name('orders.save_order_tracking');
        Route::post('/save_order_tracking_items', [OrdersApiController::class, 'saveOrderTrackingItems'])->name('orders.save_order_tracking_items');

        Route::post('/update_items_status', [OrdersApiController::class, 'updateItemsStatus'])->name('orders.update_items_status');

        Route::post('/update_item_delivery_estimate', [OrdersApiController::class, 'updateItemDeliveryEstimate'])->name('orders.update_item_delivery_estimate');
        Route::post('/cancel_order_item', [OrdersApiController::class, 'cancelOrderItem'])->name('orders.cancel_order_item');
        Route::post('/cancel_order_items', [OrdersApiController::class, 'cancelOrderItems'])->name('orders.cancel_order_items');
        Route::post('/admin_cancel', [OrdersApiController::class, 'adminCancel'])->name('orders.admin_cancel');
        Route::get('/live_tracking', [CustomerOrderApiController::class, 'getLiveTrackingDetails'])->name('orders.live_tracking');
    });

    Route::group(['prefix' => 'chat'], function () {
        Route::get('conversations', [ChatApiController::class, 'conversations']);
        Route::post('start_customer', [ChatApiController::class, 'startCustomer'])->name('chat.start_customer');
        Route::post('start_delivery_boy', [ChatApiController::class, 'startDeliveryBoy'])->name('chat.start_delivery_boy');
        Route::post('start_order', [ChatApiController::class, 'startOrder'])->name('chat.start_order');
        Route::get('messages', [ChatApiController::class, 'messages']);
        Route::get('unread_count', [ChatApiController::class, 'unreadCount']);
        Route::post('mark_read', [ChatApiController::class, 'markRead']);
        Route::post('send', [ChatApiController::class, 'send'])->name('chat.send');
    });

    Route::group(['prefix' => 'role'], function () {
        Route::get('permissions', [RoleApiController::class, 'getPermissions']);
        Route::post('save', [RoleApiController::class, 'save'])->name('role.save');
        Route::get(EDIT_ID, [RoleApiController::class, 'edit']);
        Route::post('update', [RoleApiController::class, 'update'])->name('role.update');
        Route::post('delete', [RoleApiController::class, 'delete'])->name('role.delete');
    });

    Route::group(['prefix' => 'media'], function () {
        Route::get('/', [MediaApiController::class, 'index']);
        Route::post('save', [MediaApiController::class, 'save'])->name('media.save');
        Route::post('delete', [MediaApiController::class, 'delete'])->name('media.delete');
        Route::post('multiple_delete', [MediaApiController::class, 'multipleDelete'])->name('media.multiple_delete');
        Route::post('editor_upload', [MediaApiController::class, 'editorUpload']);
    });

    Route::group(['prefix' => 'zones'], function () {
        Route::post('save', [ZoneApiController::class, 'save'])->name('zones.save');
        Route::get(EDIT_ID, [ZoneApiController::class, 'edit']);
        Route::get('taken_boundaries', [ZoneApiController::class, 'takenBoundaries']);
        Route::post('delete', [ZoneApiController::class, 'delete'])->name('zones.delete');
    });

    Route::group(['prefix' => 'delivery_cities'], function () {
        Route::post('save', [DeliveryCityApiController::class, 'save'])->name('delivery_cities.save');
        Route::post('delete', [DeliveryCityApiController::class, 'delete'])->name('delivery_cities.delete');
    });

    Route::group(['prefix' => 'delivery_areas'], function () {
        Route::post('save', [DeliveryAreaApiController::class, 'save'])->name('delivery_areas.save');
        Route::post('delete', [DeliveryAreaApiController::class, 'delete'])->name('delivery_areas.delete');
    });

    Route::group(['prefix' => 'faqs'], function () {
        Route::get('/', [FaqsApiController::class, 'index']);
        Route::post('save', [FaqsApiController::class, 'save'])->name('faqs.save');
        Route::post('update', [FaqsApiController::class, 'update'])->name('faqs.update');
        Route::post('update_order', [FaqsApiController::class, 'updateOrder'])->name('faqs.update_order');
        Route::post('delete', [FaqsApiController::class, 'delete'])->name('faqs.delete');
    });

    Route::group(['prefix' => 'languages'], function () {
        Route::get('/', [LanguageApiController::class, 'index']);
        Route::get('supported_languages', [LanguageApiController::class, 'getSupportedLanguages']);
        Route::get('get_json', [LanguageApiController::class, 'getJson']);
        Route::post('save', [LanguageApiController::class, 'save'])->name('languages.save');
        Route::post('update', [LanguageApiController::class, 'update'])->name('languages.update');
        Route::post('update_json', [LanguageApiController::class, 'updateJson'])->name('languages.update_json');
        Route::post('delete', [LanguageApiController::class, 'delete'])->name('languages.delete');

        // One endpoint for the single Translate button (always overwrites the target
        // languages; `target_languages` picks which ones).
        Route::post('translate', [TranslateApiController::class, 'translateFields'])->name('languages.translate');
    });

    Route::group(['prefix' => 'countries'], function () {
        Route::get('/', [CountryApiController::class, 'index']);
        Route::get('/active', [CountryApiController::class, 'active']);
        Route::get('/import_list', [CountryApiController::class, 'importList']);
        Route::post('import', [CountryApiController::class, 'import'])->name('countries.import');
        Route::post('save', [CountryApiController::class, 'save'])->name('countries.save');
        Route::post('update', [CountryApiController::class, 'update'])->name('countries.update');
        Route::post('set_default', [CountryApiController::class, 'setDefault'])->name('countries.set_default');
        Route::post('delete', [CountryApiController::class, 'delete'])->name('countries.delete');
        Route::group(['prefix' => 'regions'], function () {
            Route::get('/', [RegionApiController::class, 'index']);
            Route::get('import_list', [RegionApiController::class, 'importList']);
            Route::post('import', [RegionApiController::class, 'import'])->name('regions.import');
            Route::post('save', [RegionApiController::class, 'save'])->name('regions.save');
            Route::post('delete', [RegionApiController::class, 'delete'])->name('regions.delete');
        });
    });

    Route::group(['prefix' => 'panel_notification'], function () {
        Route::get('/', [NotificationPanelApiController::class, 'getNotifications']);
        Route::post('delete', [NotificationPanelApiController::class, 'delete'])->name('panel_notification.delete');
    });
});

Route::prefix('oauth')->group(function () {
    Route::post('token', '\Laravel\Passport\Http\Controllers\AccessTokenController@issueToken');
    Route::get('tokens', '\Laravel\Passport\Http\Controllers\AuthorizedAccessTokenController@forUser');
    Route::delete('tokens/{token_id}', '\Laravel\Passport\Http\Controllers\AuthorizedAccessTokenController@destroy');
    Route::post('token/refresh', '\Laravel\Passport\Http\Controllers\TransientTokenController@refresh');
});
