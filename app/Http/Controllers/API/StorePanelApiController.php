<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\PermissionCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

/**
 * Store-panel self-management: a store owner creates sub-users and store-scoped
 * roles. Everything is scoped to the authenticated user's store_id; roles are
 * store-owned (roles.store_id) and can only grant the store permission set.
 */
class StorePanelApiController extends Controller
{
    /** The current store id, or null when the user isn't a store user. */
    private function storeId(): ?int
    {
        $user = auth()->user();
        if (!$user || !$user->isStoreUser()) {
            return null;
        }
        return $user->store_id ? (int) $user->store_id : null;
    }

    private function can(string $permission): bool
    {
        return in_array($permission, auth()->user()->allPermissions ?? [], true);
    }

    /** Internal name of the store's OWNER role (never assignable to a sub-user). */
    private function ownerRoleName(int $storeId): string
    {
        return 'store' . $storeId . ':__owner__';
    }

    /** The store OWNER's permission set — the ceiling for delegating to sub-users. */
    private function ownerPermissionNames(int $storeId): array
    {
        $ownerRole = Role::where('name', $this->ownerRoleName($storeId))->first();
        return $ownerRole ? $ownerRole->permissions->pluck('name')->all() : [];
    }

    /** Roles a store user may assign to a sub-user: its own sub-user roles (not the owner role). */
    private function storeRoleIds(int $storeId): array
    {
        return Role::where('store_id', $storeId)
            ->where('name', '!=', $this->ownerRoleName($storeId))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /* =============================== USERS =============================== */

    public function users()
    {
        $storeId = $this->storeId();
        if (!$storeId) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }

        $records = Admin::with('role')->where('store_id', $storeId)->orderBy('id', 'DESC')->get();
        $roles = Role::where('store_id', $storeId)
            ->where('name', '!=', $this->ownerRoleName($storeId))
            ->get()->map(fn ($r) => ['id' => $r->id, 'name' => $this->displayName($r->name)]);

        return CommonHelper::responseWithData(['records' => $records, 'roles' => $roles]);
    }

    public function saveUser(Request $request)
    {
        $storeId = $this->storeId();
        if (!$storeId || !$this->can('store_user_manage')) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }

        $validator = Validator::make($request->all(), [
            'username'         => 'required',
            'email'            => 'required|email|unique:admins,email',
            'role_id'          => 'required|integer',
            'password'         => 'required|same:confirm_password',
            'confirm_password' => 'required',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        if (!in_array((int) $request->role_id, $this->storeRoleIds($storeId), true)) {
            return CommonHelper::responseError('invalid_role_selected');
        }
        if (($pwErr = CommonHelper::validatePasswordPolicy($request->password)) !== null) {
            return CommonHelper::responseError($pwErr);
        }
        if ($request->password !== $request->confirm_password) {
            return CommonHelper::responseError(__('password_and_confirm_password_must_match'));
        }

        $admin = new Admin();
        $admin->username       = $request->username;
        $admin->email          = $request->email;
        $admin->password       = bcrypt($request->password);
        $admin->created_by     = auth()->id();
        $admin->role_id        = (int) $request->role_id;
        $admin->store_id       = $storeId;
        $admin->is_store_owner = 0;
        $admin->save();

        return CommonHelper::responseSuccess(__('system_user_saved_successfully'));
    }

    public function updateUser(Request $request)
    {
        $storeId = $this->storeId();
        if (!$storeId || !$this->can('store_user_manage')) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }

        $admin = Admin::where('id', $request->id)->where('store_id', $storeId)->first();
        if (!$admin) {
            return CommonHelper::responseError('system_user_not_found');
        }
        if ($admin->is_store_owner) {
            return CommonHelper::responseError('the_store_owner_login_is_managed_from_the_store_form');
        }

        $rules = [
            'username' => 'required',
            'email'    => 'required|email|unique:admins,email,' . $admin->id,
            'role_id'  => 'required|integer',
        ];
        if ($request->filled('password')) {
            $rules['password'] = 'same:confirm_password';
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }
        if (!in_array((int) $request->role_id, $this->storeRoleIds($storeId), true)) {
            return CommonHelper::responseError('invalid_role_selected');
        }
        if ($request->filled('password') && ($pwErr = CommonHelper::validatePasswordPolicy($request->password)) !== null) {
            return CommonHelper::responseError($pwErr);
        }
        if ($request->filled('password') && $request->password !== $request->confirm_password) {
            return CommonHelper::responseError(__('password_and_confirm_password_must_match'));
        }

        $admin->username = $request->username;
        $admin->email    = $request->email;
        $admin->role_id  = (int) $request->role_id;
        if ($request->filled('password')) {
            $admin->password = bcrypt($request->password);
        }
        $admin->save();

        return CommonHelper::responseSuccess(__('system_user_updated_successfully'));
    }

    public function deleteUser(Request $request)
    {
        $storeId = $this->storeId();
        if (!$storeId || !$this->can('store_user_manage')) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $admin = Admin::where('id', $request->id)->where('store_id', $storeId)->first();
        if (!$admin) {
            return CommonHelper::responseSuccess(__('system_user_deleted_successfully'));
        }
        if ($admin->is_store_owner) {
            return CommonHelper::responseError('the_store_owner_login_cannot_be_deleted');
        }
        $admin->delete();
        return CommonHelper::responseSuccess(__('system_user_deleted_successfully'));
    }

    /* =============================== ROLES =============================== */

    public function roles()
    {
        $storeId = $this->storeId();
        if (!$storeId) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $roles = Role::where('store_id', $storeId)
            ->where('name', '!=', $this->ownerRoleName($storeId))
            ->get()->map(fn ($r) => ['id' => $r->id, 'name' => $this->displayName($r->name)]);
        return CommonHelper::responseWithData(['records' => $roles]);
    }

    /**
     * Delegation catalog — a store owner may only grant permissions it holds
     * (its owner-role set), never the full store catalog.
     */
    public function rolePermissions()
    {
        $storeId = $this->storeId();
        if (!$storeId) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $allowed = $this->ownerPermissionNames($storeId);
        $categories = PermissionCategory::with(['permissions' => fn ($q) => $q->whereIn('name', $allowed)])
            ->get()
            ->filter(fn ($c) => $c->permissions->isNotEmpty())
            ->values();
        return CommonHelper::responseWithData(['categories' => $categories]);
    }

    public function saveRole(Request $request)
    {
        $storeId = $this->storeId();
        if (!$storeId || !$this->can('store_role_manage')) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $validator = Validator::make($request->all(), ['name' => 'required|string|max:100']);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $role = new Role();
        $role->name       = $this->storedName($storeId, $request->name);
        $role->guard_name = 'web';
        $role->store_id   = $storeId;
        $role->save();

        $this->syncRolePermissions($role, (array) $request->permissions);

        return CommonHelper::responseSuccess('role_saved_successfully');
    }

    public function editRole($id)
    {
        $storeId = $this->storeId();
        if (!$storeId) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $role = Role::where('id', $id)->where('store_id', $storeId)->where('name', '!=', $this->ownerRoleName($storeId))->first();
        // Always return the delegation catalog so the edit modal can render the
        // permission checkboxes (not just the currently-selected ids).
        $allowed = $this->ownerPermissionNames($storeId);
        $data = [
            'categories' => PermissionCategory::with(['permissions' => fn ($q) => $q->whereIn('name', $allowed)])
                ->get()
                ->filter(fn ($c) => $c->permissions->isNotEmpty())
                ->values(),
            'user_permissions' => [],
        ];
        if ($role) {
            $data['name'] = $this->displayName($role->name);
            $data['user_permissions'] = $role->permissions()->pluck('id')->toArray();
        }
        return CommonHelper::responseWithData($data);
    }

    public function updateRole(Request $request)
    {
        $storeId = $this->storeId();
        if (!$storeId || !$this->can('store_role_manage')) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $role = Role::where('id', $request->id)->where('store_id', $storeId)->where('name', '!=', $this->ownerRoleName($storeId))->first();
        if (!$role) {
            return CommonHelper::responseError('invalid_role_selected');
        }
        if ($request->filled('name')) {
            $role->name = $this->storedName($storeId, $request->name, $role->id);
            $role->save();
        }
        $this->syncRolePermissions($role, (array) $request->permissions);

        return CommonHelper::responseSuccess('role_updated_successfully');
    }

    public function deleteRole(Request $request)
    {
        $storeId = $this->storeId();
        if (!$storeId || !$this->can('store_role_manage')) {
            return CommonHelper::responseError('you_do_not_have_access_to_this_section');
        }
        $role = Role::where('id', $request->id)->where('store_id', $storeId)->where('name', '!=', $this->ownerRoleName($storeId))->first();
        if (!$role) {
            return CommonHelper::responseSuccess('role_deleted_successfully');
        }
        $inUse = Admin::where('role_id', $role->id)->count();
        if ($inUse > 0) {
            return CommonHelper::responseError('you_cannot_delete_this_role_this_role_assigned_to_users', ['users' => $inUse]);
        }
        $role->delete();
        return CommonHelper::responseSuccess('role_deleted_successfully');
    }

    /* =============================== helpers =============================== */

    /** Grant only permissions the OWNER holds (the delegation ceiling). */
    private function syncRolePermissions(Role $role, array $permissionIds): void
    {
        $allowedIds = Permission::whereIn('name', $this->ownerPermissionNames((int) $role->store_id))->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $permissionIds), array_map('intval', $allowedIds)));
        $role->syncPermissions(Permission::whereIn('id', $ids)->get());
    }

    /** Unique stored name (spatie name+guard unique) — prefixed per store. */
    private function storedName(int $storeId, string $display, ?int $ignoreId = null): string
    {
        $base = 'store' . $storeId . ':' . trim($display);
        $name = $base;
        $i = 1;
        while (Role::where('name', $name)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $i++;
            $name = $base . ' (' . $i . ')';
        }
        return $name;
    }

    /** Strip the internal "store{id}:" prefix for display. */
    private function displayName(string $stored): string
    {
        return preg_replace('/^store\d+:/', '', $stored);
    }
}
