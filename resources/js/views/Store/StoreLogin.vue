<template>
    <div class="auth" :style="$panelLoginBackgroundImg ? { backgroundImage: `url(${$panelLoginBackgroundImg})` } : null">
        <div class="login-wrapper">
            <div class="auth-section">
                <div class="auth-card login-card">

                    <div class="lg-brand">
                        <img v-if="$appLogo != ''" :src="$storageUrl + $appLogo" class="lg-brand-logo" alt="" />
                        <img v-else :src="$baseUrl + '/images/logo.png'" class="lg-brand-logo" alt="" />
                    </div>

                    <h1 class="lg-title">{{ __('store_login') }}</h1>
                    <p class="lg-subtitle">{{ __('sign_in_to_your_store_panel') }}</p>

                    <form @submit.prevent="loginCheck()" novalidate>
                        <label class="lg-label" for="store-email">{{ __('email_address') }}</label>
                        <div class="lg-field">
                            <component :is="icons.mail" :size="18" class="lg-field-icon" />
                            <input id="store-email" type="email" class="lg-input" placeholder="you@example.com"
                                autocomplete="email" required v-model="user.email">
                        </div>

                        <label class="lg-label" for="store-password">{{ __('password') }}</label>
                        <div class="lg-field">
                            <component :is="icons.key" :size="18" class="lg-field-icon" />
                            <input id="store-password" :type="showPassword ? 'text' : 'password'" class="lg-input has-trail"
                                :placeholder="__('enter_password')" autocomplete="current-password" required
                                v-model="user.password">
                            <button type="button" class="lg-eye" @click="showPassword = !showPassword"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'">
                                <component :is="showPassword ? icons.eye : icons.eyeOff" :size="17" />
                            </button>
                        </div>

                        <button type="submit" class="lg-btn lg-btn-primary" :disabled="isLoading">
                            <b-spinner v-if="isLoading" small label="Signing in" />
                            <template v-else>
                                {{ __('login') }}
                                <component :is="icons.arrowRight" :size="17" />
                            </template>
                        </button>

                        <div class="lg-divider"><span>{{ __('or') }}</span></div>

                        <router-link to="/login" class="lg-btn lg-btn-soft">
                            <component :is="icons.layout" :size="17" />
                            {{ __('admin_login') }}
                        </router-link>
                    </form>

                    <div class="lg-copyright" v-html="copyrightDetails"></div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { markRaw } from 'vue';
import axios from 'axios';
import Auth from '../../Auth.js';
import { Mail, KeyRound, Eye, EyeOff, ArrowRight, LayoutDashboard } from 'lucide-vue-next';

export default {
    data: function () {
        return {
            icons: {
                mail: markRaw(Mail),
                key: markRaw(KeyRound),
                eye: markRaw(Eye),
                eyeOff: markRaw(EyeOff),
                arrowRight: markRaw(ArrowRight),
                layout: markRaw(LayoutDashboard),
            },
            isLoading: false,
            user: {
                email: (this.$isDemo === 1 || this.$isDemo === '1') ? 'mumbai@store.com' : '',
                password: (this.$isDemo === 1 || this.$isDemo === '1') ? 'Mumbai@1' : '',
                type: 'store',
            },
            showPassword: false,
            copyrightDetails: window.copyrightDetails,
        };
    },
    mounted() {
        if (Auth.user) this.$router.push('/store/dashboard').catch(() => { });
    },
    methods: {
        loginCheck: function () {
            let vm = this;
            this.isLoading = true;
            let url = this.$apiUrl + '/login';
            let payload = Object.assign({}, this.user, {
                fcm_token: window.panelFcmToken || '',
                language_code: window.localStorage.getItem('lang') || window.appLocale || 'en',
            });
            axios.post(url, payload).then(res => {
                vm.isLoading = false;
                let data = res.data;
                if (data.status === 1) {
                    if (!data.data.user) {
                        vm.showError(vm.__('user_is_not_register_with_this_email_address'));
                        return;
                    }
                    Auth.login(data.data.access_token, data.data.user);
                    // Guard re-prefixes to /store and resolves the first permitted page.
                    vm.$router.push('/dashboard');
                } else {
                    vm.showError(data.message);
                }
            }).catch(error => {
                vm.isLoading = false;
                this.showError(error.response?.data?.message || error.message || 'Something went wrong!');
            });
        }
    }
}
</script>
<style scoped>
/* Shared auth card styling lives in assets/css/custom/common.css (.lg-*). */
</style>
