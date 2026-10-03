<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\Region;
use App\Models\Role;
use App\Models\Store;
use App\Models\Zone;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Validation\Rule;
use Laravel\Passport\Token;
use Laravel\Passport\RefreshToken;

class StoreApiController extends Controller
{
    protected $languageService;

    public function __construct(LanguageService $languageService)
    {
        $this->languageService = $languageService;
    }

    public function getStores(Request $request)
    {
        $query = Store::with(['translations', 'zone:id,name,city,state,sales_channel,country_id,region_id'])->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('provider', 'like', "%{$term}%")
                    ->orWhereHas('zone', fn ($z) => $z->where('name', 'like', "%{$term}%")
                        ->orWhere('city', 'like', "%{$term}%")
                        ->orWhere('state', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('service_modes')) {
            $query->where('service_modes', $request->input('service_modes'));
        }
        if ($request->filled('fulfillment_type')) {
            $query->where('fulfillment_type', $request->input('fulfillment_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        // Global header country/zone filter. Store reaches country via its zone
        // (quick or ecommerce), so match on either zone FK.
        if ($request->filled('country_id')) {
            $countryId = (int) $request->input('country_id');
            $zoneIds = Zone::where('country_id', $countryId)->pluck('id')->all();
            $query->whereIn('zone_id', $zoneIds);
        }
        if ($request->filled('zone_id')) {
            $zoneId = (int) $request->input('zone_id');
            $query->where('zone_id', $zoneId);
        }

        $total = $query->count();

        if ($request->filled('limit')) {
            $stores = $query->skip((int) $request->input('offset', 0))
                ->take((int) $request->input('limit'))
                ->get();
        } else {
            $stores = $query->get();
        }

        $regionIds = $stores->map(fn ($s) => optional($s->zone)->region_id)->filter()->unique();
        $regions = Region::whereIn('id', $regionIds)->get(['id', 'name', 'code', 'tax_code'])->keyBy('id');

        foreach ($stores as $store) {
            $regionId = optional($store->zone)->region_id;
            $store->region_id = $regionId ? (int) $regionId : null;
            $store->region_name = $regionId ? ($regions[$regionId]->name ?? null) : null;
        }

        return CommonHelper::responseWithData([
            'total'  => $total,
            'stores' => $stores,
        ]);
    }

    public function save(Request $request)
    {
        $defaultLanguage = $this->languageService->getDefaultLanguage();
        $isDefaultLanguage = ($request->language_id == $defaultLanguage->id);

        if ($request->filled('id')) {
            $store = Store::find($request->id);
            if (!$store) {
                return CommonHelper::responseError('Store not found');
            }
        } else {
            if (!$isDefaultLanguage) {
                return CommonHelper::responseError('Please create store in default language first');
            }

            $validator = Validator::make($request->all(), [
                'name'             => 'required',
                'fulfillment_type' => 'required|in:quick,ecommerce,both',
            ]);

            if ($validator->fails()) {
                return CommonHelper::responseError($validator->errors()->first());
            }

            $store = new Store();
        }

        if ($isDefaultLanguage) {
            $fulfillment = $request->input('fulfillment_type', $store->fulfillment_type ?: 'quick');

            // A store has exactly ONE zone; the zone declares which channel(s) it serves.
            $coordValidator = Validator::make($request->all(), [
                'latitude'       => 'required|numeric|between:-90,90',
                'longitude'      => 'required|numeric|between:-180,180',
                'zone_id'        => 'required|integer|exists:zones,id',
                'contact_number' => 'required|string',
            ], [
                'latitude.required'       => __('please_select_store_location_on_map'),
                'longitude.required'      => __('please_select_store_location_on_map'),
                'zone_id.required'        => __('please_select_a_zone'),
                'zone_id.exists'          => __('please_select_a_zone'),
                'contact_number.required' => __('contact_number') . ' ' . __('is_required'),
            ]);

            if ($coordValidator->fails()) {
                return CommonHelper::responseError($coordValidator->errors()->first());
            }

            // Store login credential — email is the login id (must be unique across
            // all admins). Password is required on create, optional on update.
            $credValidator = Validator::make($request->all(), [
                'email'    => ['required', 'email', Rule::unique('admins', 'email')->ignore($store->owner_admin_id)],
                'password' => $store->exists ? 'nullable|string' : 'required|string',
            ], [
                'email.required' => __('please_enter_email') ?: 'Email is required',
                'email.unique'   => __('email_already_taken') ?: 'This email is already in use',
            ]);
            if ($credValidator->fails()) {
                return CommonHelper::responseError($credValidator->errors()->first());
            }
            // Enforce the configured password policy (same as system users).
            if ($request->filled('password')
                && ($pwErr = CommonHelper::validatePasswordPolicy($request->password)) !== null) {
                return CommonHelper::responseError($pwErr);
            }
            if ($request->filled('password') && $request->password !== $request->confirm_password) {
                return CommonHelper::responseError(__('password_and_confirm_password_must_match'));
            }

            $zoneId = (int) $request->input('zone_id');
            $zone   = Zone::find($zoneId);

            if (!$zone || !$this->zoneSupportsFulfillment($zone, $fulfillment)) {
                return CommonHelper::responseError(__('selected_zone_does_not_support_this_fulfillment_type'));
            }

            // One zone -> one store.
            $taken = Store::where('zone_id', $zoneId)
                ->when($store->id, fn ($q) => $q->where('id', '!=', $store->id))
                ->exists();
            if ($taken) {
                return CommonHelper::responseError(__('zone_already_assigned_to_another_store'));
            }

            $store->name              = $request->input('name');
            $store->provider          = $request->input('provider');
            $store->fulfillment_type  = $fulfillment;
            // Self pickup: what the store offers, and where. A single-channel store can
            // only offer pickup on its own channel whatever the form sent.
            $modes = (string) $request->input('service_modes', $store->service_modes ?? 'delivery');
            $store->service_modes = in_array($modes, ['delivery', 'pickup', 'both'], true) ? $modes : 'delivery';
            $pickupChannels = (string) $request->input('pickup_channels', $store->pickup_channels ?? 'both');
            $store->pickup_channels = $fulfillment === 'both'
                ? (in_array($pickupChannels, ['quick', 'ecommerce', 'both'], true) ? $pickupChannels : 'both')
                : $fulfillment;
            $store->zone_id           = $zoneId;
            $store->address           = $request->input('address');
            $store->latitude          = $request->input('latitude');
            $store->longitude         = $request->input('longitude');
            $store->formatted_address = $request->input('formatted_address');
            $store->place_id          = $request->input('place_id');
            $store->contact_number  = $request->input('contact_number');
            $store->email           = $request->input('email');
            $store->operating_hours = $this->parseJson($request->input('operating_hours'));
            $store->preparation_time = max(0, (int) $request->input('preparation_time', 0));
            $store->status = (int) $request->input('status', 1);
            // The store's tax identity (GSTIN / VAT / TRN). Blank clears it.
            if ($request->has('tax_number')) {
                $number = strtoupper(trim((string) $request->input('tax_number')));
                $store->tax_number = $number !== '' ? $number : null;
                $store->tax_registration_type = $number !== ''
                    ? (strtoupper(trim((string) $request->input('tax_registration_type'))) ?: null)
                    : null;
            }
            $store->save();

            if ((int) $store->status === 0) {
                $this->revokeStoreLogins($store->id);
            }

            $ownerRole = $this->syncOwnerRole($store, $request);
            $this->syncStoreOwner($store, $request->input('email'), $request->input('password'), $ownerRole);
        }

        $store->saveTranslation((int) $request->language_id, [
            'name'     => $request->input('name'),
            'provider' => $request->input('provider'),
            'address'  => $request->input('address'),
        ]);

        return CommonHelper::responseWithData([
            'id'      => $store->id,
            'message' => __('store_saved_successfully'),
        ]);
    }

    /** Internal name of a store's OWNER role (distinct from its sub-user roles). */
    private function ownerRoleName(int $storeId): string
    {
        return 'store' . $storeId . ':__owner__';
    }

    /**
     * Create/sync the store's per-store OWNER role and its permission ceiling.
     * The admin picks permissions (default = full catalog on create); the two
     * management perms are always included. Revoking a permission cascade-prunes
     * it from the store's sub-user roles so no sub-user ever exceeds the owner.
     */
    private function syncOwnerRole(Store $store, Request $request): SpatieRole
    {
        $role = SpatieRole::firstOrCreate(
            ['name' => $this->ownerRoleName($store->id), 'guard_name' => 'web'],
            ['store_id' => $store->id]
        );
        if ((int) $role->store_id !== (int) $store->id) {
            $role->store_id = $store->id;
            $role->save();
        }

        // Only (re)apply permissions when the admin sent them, or on first create.
        $sentPerms = $request->has('permissions');
        if ($sentPerms || !$store->owner_admin_id) {
            if ($sentPerms) {
                $ids = array_map('intval', (array) $request->input('permissions', []));
                $selected = Permission::whereIn('id', $ids)->pluck('name')->all();
                $names = array_values(array_intersect($selected, Role::storePermissionCatalog()));
            } else {
                // Create default = full catalog.
                $names = Role::storePermissionCatalog();
            }
            $ownerNames = array_values(array_unique(array_merge($names, Role::storeOwnerMgmtPerms())));
            $role->syncPermissions(Permission::whereIn('name', $ownerNames)->get());
            $this->pruneSubUserRoles($store->id, $ownerNames);
        }

        return $role;
    }

    /** Clamp every store sub-user role down to the owner's permission set. */
    private function pruneSubUserRoles(int $storeId, array $ownerNames): void
    {
        $subRoles = SpatieRole::where('store_id', $storeId)
            ->where('name', '!=', $this->ownerRoleName($storeId))
            ->get();
        foreach ($subRoles as $r) {
            $keep = $r->permissions->pluck('name')->intersect($ownerNames)->values()->all();
            $r->syncPermissions(Permission::whereIn('name', $keep)->get());
        }
    }

    /**
     * Creates or updates the store's PRIMARY login — an `admins` row on the store's
     * OWNER role, linked to the store. The email/password are the store login.
     */
    private function syncStoreOwner(Store $store, ?string $email, ?string $password, SpatieRole $ownerRole): void
    {
        $admin = $store->owner_admin_id ? Admin::find($store->owner_admin_id) : null;
        if (!$admin) {
            $admin = Admin::where('store_id', $store->id)->where('is_store_owner', 1)->first();
        }

        if ($admin) {
            $admin->username       = $store->name;
            $admin->email          = $email;
            $admin->role_id        = $ownerRole->id;
            $admin->store_id       = $store->id;
            $admin->is_store_owner = 1;
            if (!empty($password)) {
                $admin->password = Hash::make($password);
            }
            $admin->save();
        } else {
            $admin = Admin::create([
                'username'       => $store->name,
                'email'          => $email,
                'password'       => Hash::make($password ?: Str::random(14)),
                'role_id'        => $ownerRole->id,
                'created_by'     => auth()->id() ?? 0,
                'store_id'       => $store->id,
                'is_store_owner' => 1,
            ]);
        }
        $this->assignRolePivot($admin, $ownerRole);

        if ((int) $store->owner_admin_id !== (int) $admin->id) {
            $store->owner_admin_id = $admin->id;
            $store->save();
        }
    }

    private function assignRolePivot(Admin $admin, SpatieRole $ownerRole): void
    {
        DB::table('model_has_roles')
            ->where('model_type', $admin->getMorphClass())
            ->where('model_id', $admin->id)
            ->delete();
        DB::table('model_has_roles')->insert([
            'role_id'    => $ownerRole->id,
            'model_type' => $admin->getMorphClass(),
            'model_id'   => $admin->id,
        ]);
        $admin->unsetRelation('roles');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Admin permission-picker catalog (categories with only store-owner perms). */
    public function permissionCatalog()
    {
        $categories = PermissionCategory::with(['permissions' => fn ($q) => $q->whereIn('name', Role::storePermissionCatalog())])
            ->get()
            ->filter(fn ($c) => $c->permissions->isNotEmpty())
            ->values();
        return CommonHelper::responseWithData(['categories' => $categories]);
    }

    /** Update ONLY a store's owner permissions (used by the listing's permission icon). */
    public function savePermissions(Request $request)
    {
        $store = Store::find($request->input('id'));
        if (!$store) {
            return CommonHelper::responseError('Store not found');
        }
        $request->merge(['permissions' => (array) $request->input('permissions', [])]);
        $ownerRole = $this->syncOwnerRole($store, $request);
        // Keep the owner admin on the (possibly re-created) owner role.
        if ($store->owner_admin_id) {
            Admin::where('id', $store->owner_admin_id)->update(['role_id' => $ownerRole->id]);
        }
        return CommonHelper::responseSuccess('permissions_updated_successfully');
    }

    public function edit($id)
    {
        $store = Store::with(['translations', 'zone:id,name'])->find($id);
        if (!$store) {
            return CommonHelper::responseError('Store not found');
        }
        $ownerRole = SpatieRole::where('name', $this->ownerRoleName($store->id))->first();
        $data = $store->toArray();
        // Current owner permission ids (excluding the always-on mgmt perms) for the picker.
        $data['owner_permission_ids'] = $ownerRole
            ? $ownerRole->permissions()->whereIn('name', Role::storePermissionCatalog())->pluck('permissions.id')->all()
            : [];
        return CommonHelper::responseWithData($data);
    }

    /** Store owner viewing its OWN store (Account → Profile). Resolves the store server-side. */
    public function myProfile()
    {
        $user = auth()->user();
        if (!$user || !$user->isStoreUser() || !$user->store_id || !$user->is_store_owner) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        return $this->edit((int) $user->store_id);
    }

    /** Store owner saving its OWN store profile — never lets it change permissions or password here. */
    public function saveMyProfile(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->isStoreUser() || !$user->store_id || !$user->is_store_owner) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $request->merge(['id' => (int) $user->store_id]);
        // Ceiling + credentials are admin-only / Settings-only; strip them from a self edit.
        $request->request->remove('permissions');
        $request->request->remove('password');
        $request->request->remove('confirm_password');
        return $this->save($request);
    }

    public function delete(Request $request)
    {
        if (!$request->filled('id')) {
            return CommonHelper::responseError('Store id required');
        }
        $store = Store::find($request->id);
        if (!$store) {
            return CommonHelper::responseSuccess('store_already_deleted');
        }
        // Block delete when the store has order items (order history must survive).
        if (OrderItem::where('store_id', $store->id)->exists()) {
            return CommonHelper::responseError('store_in_use_deactivate_instead');
        }
        // Release the store's zones so they can be assigned to another store
        // (unique index would otherwise keep them locked to the trashed row).
        $store->zone_id = null;
        $store->save();
        Admin::where('store_id', $store->id)->update(['status' => 0]);
        // Kill any active panel session for the removed store.
        $this->revokeStoreLogins($store->id);
        $store->delete();
        return CommonHelper::responseSuccess('store_deleted_successfully');
    }

    private function revokeStoreLogins(int $storeId): void
    {
        $adminIds = Admin::where('store_id', $storeId)->pluck('id');
        if ($adminIds->isEmpty()) {
            return;
        }
        $tokenIds = Token::whereIn('user_id', $adminIds)
            ->where('revoked', false)
            ->pluck('id');
        if ($tokenIds->isEmpty()) {
            return;
        }
        Token::whereIn('id', $tokenIds)->update(['revoked' => true]);
        RefreshToken::whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
    }

    /**
     * Can this zone back a store with the given fulfillment type?
     * Strict: the zone's channel must equal the store's fulfillment type.
     */
    protected function zoneSupportsFulfillment(Zone $zone, string $fulfillment): bool
    {
        return $zone->sales_channel === $fulfillment;
    }

    public function getZonesForType(Request $request)
    {
        $type = $request->input('fulfillment_type');

        $query = Zone::query()->where('status', 1)->orderBy('name');

        // The zone's channel must equal the store's fulfillment type — quick stores get
        // quick zones, ecommerce gets ecommerce, and a 'both' zone is reserved for a
        // 'both' store. No cross-matching.
        if (in_array($type, Zone::CHANNELS, true)) {
            $query->where('sales_channel', $type);
        }

        if ($request->filled('country_id')) {
            $query->where('country_id', (int) $request->input('country_id'));
        }
        if ($request->filled('zone_id')) {
            $ownZoneId = (int) Store::where('id', (int) $request->input('store_id', 0))->value('zone_id');
            $query->where(function ($q) use ($request, $ownZoneId) {
                $q->where('id', (int) $request->input('zone_id'));
                if ($ownZoneId) {
                    $q->orWhere('id', $ownZoneId);
                }
            });
        }

        // One zone -> one store: hide zones already owned by another store
        // (the editing store's own zones stay selectable via store_id).
        $editingStoreId = (int) $request->input('store_id', 0);
        $takenZoneIds = Store::query()
            ->when($editingStoreId, fn ($q) => $q->where('id', '!=', $editingStoreId))
            ->pluck('zone_id')
            ->filter()
            ->all();
        if (!empty($takenZoneIds)) {
            $query->whereNotIn('id', $takenZoneIds);
        }

        $zones = $query->with('region:id,name')->get(['id', 'name', 'sales_channel', 'country_id', 'region_id'])
            ->map(function (Zone $z) {
                return [
                    'id'            => $z->id,
                    'name'          => $z->getRawOriginal('name') ?: ('Zone #' . $z->id),
                    'sales_channel' => $z->sales_channel,
                    'country_id'    => $z->country_id ? (int) $z->country_id : null,
                    'region_id'     => $z->region_id ? (int) $z->region_id : null,
                    'region_name'   => optional($z->region)->name,
                ];
            })->values();

        return CommonHelper::responseWithData([
            'zones' => $zones,
        ]);
    }

    public function getStoresForChannel(Request $request)
    {
        $channel = $request->input('sales_channel', 'both');

        $query = Store::query()->where('status', 1)->orderBy('name');

        if ($channel === 'quick') {
            $query->whereIn('fulfillment_type', ['quick', 'both']);
        } elseif ($channel === 'ecommerce') {
            $query->whereIn('fulfillment_type', ['ecommerce', 'both']);
        }
        // 'both' (product sells everywhere) -> every active store

        // Global header country/zone filter. Store reaches country via its zone FKs.
        if ($request->filled('country_id') || $request->filled('zone_id')) {
            $zoneIds = $request->filled('zone_id')
                ? [(int) $request->input('zone_id')]
                : Zone::where('country_id', (int) $request->input('country_id'))->pluck('id')->all();
            $query->where(function ($q) use ($zoneIds) {
                $q->whereIn('zone_id', $zoneIds);
            });
        }

        // Optional name search (variant step store picker).
        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where('name', 'like', "%{$term}%");
        }

        $stores = $query->with('zone:id,name,city')->get(['id', 'name', 'fulfillment_type', 'zone_id']);

        // Resolve each store's country (via its channel zone) so the product form can
        // show the store's currency symbol next to price fields and auto-fill the
        // purchase price across stores of the SAME country. Store → zone → country.
        $zoneIds = $stores->pluck('zone_id')
            ->filter()->unique()->values()->all();
        $zoneCountry = empty($zoneIds)
            ? collect()
            : Zone::whereIn('id', $zoneIds)->with('country')->get()->keyBy('id');

        $mapped = $stores->map(function (Store $s) use ($channel, $zoneCountry) {
            $zoneId = $channel === 'ecommerce'
                ? $s->zone_id
                : $s->zone_id;
            $country = $zoneId && isset($zoneCountry[$zoneId]) ? $zoneCountry[$zoneId]->country : null;
            return [
                'id'                => $s->id,
                'name'              => $s->getRawOriginal('name') ?: ('Store #' . $s->id),
                'fulfillment_type'  => $s->fulfillment_type,
                'city'              => $s->zone?->city,
                'zone_id'           => $s->zone_id,
                // zone for the requested channel (quick fallback for 'both' callers)
                'zone_id'           => $zoneId,
                // Country + currency for per-store pricing UI (symbol + same-country autofill).
                'country_id'        => $country?->id,
                'currency'          => $country?->currency,
                'currency_code'     => $country?->currency_code,
            ];
        })->values();

        return CommonHelper::responseWithData(['stores' => $mapped]);
    }

    protected function parseJson($input)
    {
        if (is_null($input) || $input === '') {
            return null;
        }
        if (is_array($input)) {
            return $input;
        }
        $decoded = json_decode($input, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

}
