import axios from 'axios';
import { bootClarityForUser, trackEvent } from './utils/clarity.js';

class Auth {
    constructor () {
        this.token = window.localStorage.getItem('token');
        let userData = window.localStorage.getItem('user');
        this.user = userData ? JSON.parse(userData) : null;

        if (this.token) {
            axios.defaults.headers.common['Authorization'] = 'Bearer ' + this.token;
        }

        this.restoreIdentity();

        // Existing session: start the Clarity project for this user's panel.
        if (this.user) {
            bootClarityForUser(this.user);
        }
    }

    restoreIdentity () {
        if (!this.user) return;

        if (!window.Role) {
            window.Role = (this.user.role && this.user.role.name) || '';
        }
        if (!window.UserPermissions || !window.UserPermissions.length) {
            window.UserPermissions = JSON.stringify(this.user.allPermissions || []);
        }
        window.StoreId = (this.user.store && this.user.store.id) ? Number(this.user.store.id) : null;
        window.StoreName = (this.user.store && this.user.store.name) ? this.user.store.name : '';
        window.StoreZoneId = (this.user.store && this.user.store.zone_id) ? Number(this.user.store.zone_id) : null;
        window.StoreCountryId = (this.user.store && this.user.store.zone && this.user.store.zone.country_id) ? Number(this.user.store.zone.country_id) : null;
        window.IsStoreOwner = Number(this.user.is_store_owner) === 1;
    }

    login (token, user) {
        window.localStorage.setItem('token', token);
        window.localStorage.setItem('user', JSON.stringify(user));
        axios.defaults.headers.common['Authorization'] = 'Bearer ' + token;

        this.token = token;
        this.user = user;

        window.UserPermissions = JSON.stringify(user.allPermissions || []); //Fix Login Issue for Permission
        window.Role = (user.role && user.role.name) || window.Role || ''; //Fix Login Issue for Permission
        window.StoreId = (user.store && user.store.id) ? Number(user.store.id) : null;
        window.StoreName = (user.store && user.store.name) ? user.store.name : '';
        window.StoreZoneId = (user.store && user.store.zone_id) ? Number(user.store.zone_id) : null;
        window.StoreCountryId = (user.store && user.store.zone && user.store.zone.country_id) ? Number(user.store.zone.country_id) : null;
        window.IsStoreOwner = Number(user.is_store_owner) === 1;

        bootClarityForUser(user);
        trackEvent(Number(user.role_id) === 3 ? 'db_login' : 'admin_login');
    }

    check () {
        return !! this.token;
    }

    logout () {
        let role_id = this.user.role_id;
        // Store users belong to the dedicated store login, not the admin login.
        const isStoreUser = !!(this.user && (this.user.store_id || this.user.is_store_owner || (this.user.store && this.user.store.id)));
        trackEvent(Number(role_id) === 3 ? 'db_logout' : 'admin_logout');
        const lang = window.localStorage.getItem('lang');
        const language = window.localStorage.getItem('language');
        window.localStorage.clear();
        if (lang !== null) window.localStorage.setItem('lang', lang);
        if (language !== null) window.localStorage.setItem('language', language);

        if (role_id === 3) {
            window.location.replace('/delivery_boy/login');
        } else if (isStoreUser) {
            window.location.replace('/store/login');
        } else {
            window.location.replace('login');
        }
        this.user = null;
    }

}
export default new Auth();
