<script>
export default {
    methods: {
        $can(permissionName) {
            return UserPermissions.indexOf(permissionName) !== -1;
        },
        $role(roleName) {
            return Role === roleName;
        },
        $isStoreUser() {
            return typeof window !== 'undefined' && window.StoreZoneId != null;
        },
        // The store OWNER (primary login) — distinct from delegated sub-users.
        $isStoreOwner() {
            return typeof window !== 'undefined' && window.IsStoreOwner === true;
        },
    },
};
</script>
