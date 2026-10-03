<template>
    <div>
        <transition name="rds-fade">
            <div v-if="show" class="rds-backdrop" @click="close"></div>
        </transition>

        <transition name="rds-slide">
            <aside v-if="show" class="rds-panel" role="dialog" aria-modal="true">
                <!-- Header -->
                <div class="rds-header">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold">{{ (record && record.return_number) || ('#' + (record ? record.id : form.id)) }}</h5>
                        <span class="badge" :class="statusBadgeClass(record ? record.status : form.status)">{{ statusLabel(record ? record.status : form.status) }}</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button v-if="record && record.order_id && $can('chat')" class="btn btn-sm btn-outline-primary"
                            :disabled="chatStarting" @click="chatWithCustomer">
                            <b-spinner small v-if="chatStarting"></b-spinner>
                            <MessageCircle v-else :size="15" /> <span class="d-none d-sm-inline">{{ __('chat_with_customer') }}</span>
                        </button>
                        <div v-if="navList.length > 1" class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" :disabled="!hasPrev" @click="goPrev">
                                <ChevronLeft :size="15" /> <span class="d-none d-sm-inline">{{ __('previous') }}</span>
                            </button>
                            <button class="btn btn-outline-primary" :disabled="!hasNext" @click="goNext">
                                <span class="d-none d-sm-inline">{{ __('next') }}</span> <ChevronRight :size="15" />
                            </button>
                        </div>
                        <button class="btn btn-sm slider-close" @click="close"><X :size="16" /></button>
                    </div>
                </div>

                <div class="rds-body" v-if="record">
                    <!-- Row 1: Customer + Return Reason -->
                    <div class="row g-2">
                        <div class="col-lg-6 d-flex">
                            <!-- Customer -->
                            <div class="rds-card w-100 mb-0">
                                <div class="rds-card-title"><User :size="16" class="rds-ic" /> {{ __('customer') }}</div>
                                <div class="rds-row">
                                    <span>{{ __('name') }}</span>
                                    <b>
                                        <span v-if="canOpenCustomer && record.user_id" class="rds-link"
                                            v-b-tooltip.hover :title="__('view_customer') || __('customer')"
                                            @click="openCustomer(record.user_id)">{{ getDisplayName(record.customer_name || record.name) }}</span>
                                        <template v-else>{{ getDisplayName(record.customer_name || record.name) }}</template>
                                    </b>
                                </div>
                                <div class="rds-row" v-if="record.customer_mobile">
                                    <span>{{ __('mobile') }}</span>
                                    <b>
                                        <a v-if="canContact" class="rds-link" :href="telHref(record.user_country_code, record.customer_mobile)"
                                            v-b-tooltip.hover :title="__('call')"><span v-if="record.user_country_code">{{ record.user_country_code }} </span>{{ $filters.mobileMask(record.customer_mobile) }}</a>
                                        <template v-else><span v-if="record.user_country_code">{{ record.user_country_code }} </span>{{ $filters.mobileMask(record.customer_mobile) }}</template>
                                    </b>
                                </div>
                                <div class="rds-row" v-if="record.customer_email">
                                    <span>{{ __('email') }}</span>
                                    <b>
                                        <a v-if="canContact" class="rds-link" :href="'mailto:' + record.customer_email"
                                            v-b-tooltip.hover :title="__('send_email')">{{ $filters.emailMask(record.customer_email) }}</a>
                                        <template v-else>{{ $filters.emailMask(record.customer_email) }}</template>
                                    </b>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 d-flex">
                            <!-- Return Reason -->
                            <div class="rds-card w-100 mb-0">
                                <div class="rds-card-title"><FileText :size="16" class="rds-ic" /> {{ __('return_reason') }}</div>
                                <div class="small text-muted">{{ record.return_reason || '-' }}</div>
                                <template v-if="record.reject_reason">
                                    <div class="rds-card-title mt-3"><Ban :size="16" class="rds-ic" /> {{ __('reject_reason') }}</div>
                                    <div class="small text-muted">{{ record.reject_reason }}</div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2: Product + Update Status -->
                    <div class="row g-2 mt-0">
                        <div class="col-lg-6 d-flex">
                            <!-- Product -->
                            <div class="rds-card w-100 mb-0">
                                <div class="rds-card-title"><Package :size="16" class="rds-ic" /> {{ __('product') }}</div>
                                <div class="d-flex gap-2 align-items-start">
                                    <img v-if="detail.image" :src="detail.image" class="rds-item-img" alt="" />
                                    <div class="flex-grow-1">
                                        <div class="fw-bold small mb-1" :class="{ 'rds-link': canOpenProduct && record.product_id }"
                                            @click="openProduct(record.product_id)">{{ detail.product_name || record.product_name }}</div>
                                        <div class="rds-eitem-charges" v-if="variantAttrs.length">
                                            <span v-for="(a, i) in variantAttrs" :key="i">{{ a }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="rds-row mt-2"><span>{{ __('quantity') }}</span><b>{{ record.quantity }}</b></div>
                                <div class="rds-row"><span>{{ __('price') }}</span><b>{{ curSymbol }}{{ detail.discounted_price || detail.price || record.price }}</b></div>

                                <!-- Refundable additional + surge charges, so admin sees what the refund includes. -->
                                <div class="rds-row rds-refund-line" v-for="(c, i) in refundableAdditional" :key="'ac-' + i">
                                    <span>{{ c.name }}</span><b>{{ curSymbol }}{{ c.amount }}</b>
                                </div>
                                <div class="rds-row rds-refund-line" v-for="(c, i) in refundableSurge" :key="'sc-' + i">
                                    <span>{{ c.name }}</span><b>{{ curSymbol }}{{ c.amount }}</b>
                                </div>

                                <div class="rds-row">
                                    <span>
                                        {{ __('total') }}
                                        <span v-if="returnTaxTotal > 0" v-b-tooltip.hover :title="taxHint" class="rds-tax-i"><Info :size="13" /></span>
                                    </span>
                                    <b>{{ curSymbol }}{{ record.sub_total }}</b>
                                </div>

                                <div class="rds-total" v-if="detail.amount_to_refund != null">
                                    <span>{{ __('refund_amount') }}</span>
                                    <b class="text-success">{{ curSymbol }}{{ detail.amount_to_refund }}</b>
                                </div>
                            </div>
                        </div>

                        <!-- Update status: advance one step + reject anytime -->
                        <div class="col-lg-6 d-flex" v-if="login_user.role_id == 3 || $can('return_request_update')">
                    <div class="rds-card w-100 mb-0">
                        <div class="rds-card-title">{{ __('update_status') }}</div>

                        <!-- Pick a delivery boy when the next step is assignment -->
                        <div class="mb-2" v-if="nextStatusId == 4">
                            <label class="form-label fw-bold">{{ __('assign_delivery_boy') }} <span class="text-danger">*</span></label>
                            <AppSelect class="form-control form-select" v-model="form.delivery_boy_id"
                                :options="deliveryBoyOptions" :placeholder="__('select_delivery_boy')" />
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button v-if="nextStatusId" class="btn btn-primary" :disabled="isLoading" @click="advance">
                                <ArrowRight :size="15" class="me-1" />{{ __('mark_as') }} {{ statusLabel(nextStatusId) }}
                            </button>
                            <button v-if="canReject && !rejecting" class="btn btn-outline-danger" :disabled="isLoading" @click="rejecting = true">
                                <X :size="15" class="me-1" />{{ __('reject') }}
                            </button>
                            <span v-if="!nextStatusId && !canReject" class="badge align-self-center" :class="statusBadgeClass(record ? record.status : form.status)">{{ statusLabel(record ? record.status : form.status) }}</span>
                        </div>

                        <!-- Who is already collecting this return -->
                        <div class="rds-assigned-boy" v-if="assignedDeliveryBoy">
                            <span class="rds-assigned-label"><Bike :size="14" /> {{ __('delivery_boy') }}</span>
                            <span class="rds-assigned-value">
                                {{ assignedDeliveryBoy.name }}
                                <span v-if="assignedDeliveryBoy.mobile">
                                    (<a v-if="canContact" class="rds-link" :href="telHref(assignedDeliveryBoy.country_code, assignedDeliveryBoy.mobile)"
                                        v-b-tooltip.hover :title="__('call')">{{ assignedDeliveryBoy.country_code }} {{ $filters.mobileMask(assignedDeliveryBoy.mobile) }}</a><template
                                        v-else>{{ assignedDeliveryBoy.country_code }} {{ $filters.mobileMask(assignedDeliveryBoy.mobile) }}</template>)
                                </span>
                            </span>
                            <button v-if="canChatDeliveryBoy" type="button" class="list-action-btn is-view ms-auto"
                                v-b-tooltip.hover :title="__('chat_with_delivery_boy')"
                                :disabled="dboyChatStarting" @click="chatWithDeliveryBoy(record.delivery_boy_id)">
                                <b-spinner small v-if="dboyChatStarting"></b-spinner>
                                <MessageCircle v-else :size="14" />
                            </button>
                        </div>

                        <!-- Reject reason -->
                        <div class="mt-3" v-if="rejecting">
                            <label class="form-label fw-bold">{{ __('reject_reason') }} <span class="text-danger">*</span></label>
                            <textarea v-model="form.reject_reason" class="form-control" :placeholder="__('reject_reason')" rows="3"></textarea>
                            <div class="d-flex gap-2 mt-2">
                                <button class="btn btn-danger btn-sm" :disabled="isLoading" @click="confirmReject">{{ __('confirm') }}</button>
                                <button class="btn btn-light btn-sm" @click="rejecting = false; form.reject_reason = ''">{{ __('cancel') }}</button>
                            </div>
                        </div>

                        <!-- Remark -->
                        <div class="mt-3">
                            <label class="form-label fw-bold">{{ __('remark') }}</label>
                            <textarea v-model="form.remark" class="form-control" :placeholder="__('remark')" rows="2"></textarea>
                        </div>
                    </div>
                        </div>
                    </div>

                    <!-- Row 3: Pickup Address + Timeline -->
                    <div class="row g-2 mt-0">
                        <div class="col-lg-6 d-flex" v-if="record.pickup_address">
                            <!-- Pickup Address -->
                            <div class="rds-card w-100 mb-0">
                                <div class="rds-card-title d-flex justify-content-between align-items-center">
                                    <span><MapPin :size="15" class="rds-ic" /> {{ __('pickup_address') }}</span>
                                    <button v-if="pickupMapUrl" class="btn btn-sm btn-outline-primary py-0" @click="openPickupMap">
                                        <MapPin :size="15" /> {{ __('delivery_location') }}
                                    </button>
                                </div>
                                <div class="rds-row" v-if="record.pickup_address.name"><span>{{ __('name') }}</span><b>{{ record.pickup_address.name }}</b></div>
                                <div class="rds-row" v-if="record.pickup_address.mobile">
                                    <span>{{ __('mobile') }}</span>
                                    <b>
                                        <a v-if="canContact" class="rds-link" :href="telHref('', record.pickup_address.mobile)"
                                            v-b-tooltip.hover :title="__('call')">{{ $filters.mobileMask(record.pickup_address.mobile) }}</a>
                                        <template v-else>{{ $filters.mobileMask(record.pickup_address.mobile) }}</template>
                                        <span v-if="record.pickup_address.alternate_mobile">
                                            /
                                            <a v-if="canContact" class="rds-link" :href="telHref('', record.pickup_address.alternate_mobile)"
                                                v-b-tooltip.hover :title="__('call')">{{ $filters.mobileMask(record.pickup_address.alternate_mobile) }}</a>
                                            <template v-else>{{ $filters.mobileMask(record.pickup_address.alternate_mobile) }}</template>
                                        </span>
                                    </b>
                                </div>
                                <div class="small text-muted mt-1">{{ record.pickup_address.address }}</div>
                            </div>
                        </div>
                        <div class="col-lg-6 d-flex" v-if="timeline.length">
                            <!-- Status timeline -->
                            <div class="rds-card w-100 mb-0">
                                <div class="rds-card-title"><CalendarDays :size="16" class="rds-ic" /> {{ __('timeline') }}</div>
                                <ul class="rds-timeline">
                                    <li v-for="(t, i) in timeline" :key="i"
                                        :class="{ current: i === timeline.length - 1 }">
                                        <span class="rds-tl-dot" :class="{ first: i === timeline.length - 1 }"></span>
                                        <div class="rds-tl-content">
                                            <div class="fw-bold small">{{ t.status_name }}</div>
                                            <div class="text-muted" style="font-size:.72rem">
                                                {{ $filters.formatDateTime(t.datetime) }}<span v-if="t.updated_by"> · {{ getDisplayName(t.updated_by) }}</span>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Skeleton until the record is available. -->
                <div class="rds-body" v-else>
                    <div class="row g-2">
                        <div v-for="n in 4" :key="'rdskel-' + n" class="col-lg-6 mb-2">
                            <div class="rds-card">
                                <div class="skel skel-line" style="width:40%;height:.9rem;margin-bottom:.9rem"></div>
                                <div class="skel skel-line" style="width:80%"></div>
                                <div class="skel skel-line" style="width:60%"></div>
                                <div class="skel skel-line" style="width:70%;margin-bottom:0"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </transition>
    </div>
</template>

<script>
import axios from 'axios';
import Auth from '../../Auth.js';
import {
    ChevronLeft, ChevronRight, X, User, MapPin, Package, FileText, Ban,
    CalendarDays, ArrowRight, Bike, MessageCircle, Info,
} from 'lucide-vue-next';

const STATUS_META = [
    { id: 1, label: 'return_requested', icon: '&#8635;', activeClass: 'border-warning bg-warning bg-opacity-10' },
    { id: 2, label: 'accept', icon: '&#9989;', activeClass: 'border-success bg-success bg-opacity-10' },
    { id: 3, label: 'reject', icon: '&#10060;', activeClass: 'border-danger bg-danger bg-opacity-10' },
    { id: 4, label: 'delivery_boy_assigned', icon: '&#128100;', activeClass: 'border-info bg-info bg-opacity-10' },
    { id: 5, label: 'out_for_pickup', icon: '&#128666;', activeClass: 'border-primary bg-primary bg-opacity-10' },
    { id: 6, label: 'received_from_customer', icon: '&#128230;', activeClass: 'border-secondary bg-secondary bg-opacity-10' },
    { id: 7, label: 'return_to_store', icon: '&#127980;', activeClass: 'border-dark bg-dark bg-opacity-10' },
    { id: 8, label: 'refund_completed', icon: '&#128176;', activeClass: 'border-success bg-success bg-opacity-10' },
];
// Forward status flow. Admin advances one step at a time; delivery boy handles the
// pickup leg (assigned -> out for pickup -> received -> return to store).
// Admin can advance through every status, one at a time. The delivery boy only handles
// the pickup leg (out for pickup -> received -> return to store).
const ADMIN_NEXT = { 1: 2, 2: 4, 4: 5, 5: 6, 6: 7, 7: 8 };
const DELIVERY_BOY_NEXT = { 4: 5, 5: 6, 6: 7 };

export default {
    name: 'ReturnDetailSlider',
    components: {
        ChevronLeft, ChevronRight, X, User, MapPin, Package, FileText, Ban,
        CalendarDays, ArrowRight, Bike, MessageCircle, Info,
    },
    props: {
        modelValue: { type: Boolean, default: false },
        record: { type: Object, default: null },
        navList: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue', 'updated', 'navigate'],
    data() {
        return {
            show: this.modelValue,
            isLoading: false,
            chatStarting: false,
            dboyChatStarting: false,
            rejecting: false,
            login_user: Auth.user,
            deliveryBoys: [],
            form: this.blankForm(),
        };
    },
    computed: {
        /* Tax frozen against the returned item. The refund already carries it — it sits
           inside sub_total — so this only makes it visible behind an (i). */
        returnTaxTotal() {
            const rows = (this.detail && this.detail.return_item && this.detail.return_item.tax_breakdown) || [];
            return Number((rows.reduce((t, r) => t + Number(r.amount || 0), 0)).toFixed(2));
        },
        taxHint() {
            const rows = (this.detail && this.detail.return_item && this.detail.return_item.tax_breakdown) || [];
            if (!rows.length) return '';
            return __('tax') + ': ' + rows
                .map(r => `${r.name} ${Number(r.rate || 0)}% ${this.curSymbol}${Number(r.amount || 0).toFixed(2)}`)
                .join(' · ');
        },

        isDeliveryBoy() { return Number(this.login_user?.role_id) === 3; },
        canChatDeliveryBoy() { return !this.isDeliveryBoy && this.$can('chat') && Number(this.record?.delivery_boy_id) > 0; },
        canOpenCustomer() { return !this.isDeliveryBoy && this.$can('order_list'); },
        canOpenProduct() { return !this.isDeliveryBoy && this.$can('product_list'); },
        canContact() { return this.$isDemo != 1; },

        // Names are translated objects, so resolve them before AppSelect renders.
        deliveryBoyOptions() {
            return (this.deliveryBoys || []).map(b => ({ id: b.id, name: this.getDisplayName(b.name) }));
        },
        assignedDeliveryBoy() {
            const id = Number(this.record?.delivery_boy_id || 0);
            if (!id) return null;
            if (this.record?.delivery_boy_name) {
                return {
                    name: this.getDisplayName(this.record.delivery_boy_name),
                    mobile: this.record.delivery_boy_mobile || '',
                    country_code: this.record.delivery_boy_country_code || '',
                };
            }
            const boy = (this.deliveryBoys || []).find(b => Number(b.id) === id);
            return boy ? { name: this.getDisplayName(boy.name), mobile: boy.mobile || '', country_code: boy.country_code || '' } : null;
        },
        // The order's own currency, falling back to the global one.
        curSymbol() {
            return (this.record && this.record.currency) || this.$currency;
        },
        // Google Maps link for the pickup address (when coordinates are present).
        pickupMapUrl() {
            const a = this.record && this.record.pickup_address;
            if (!a || !a.latitude || !a.longitude) return null;
            return `https://www.google.com/maps?q=${a.latitude},${a.longitude}`;
        },
        // The single next status the current user can advance to (null = nothing further).
        nextStatusId() {
            const cur = this.record ? Number(this.record.status) : 0;
            const map = this.login_user.role_id == 3 ? DELIVERY_BOY_NEXT : ADMIN_NEXT;
            return map[cur] || null;
        },
        // Admin can reject any time before the return is terminal (rejected / refunded).
        canReject() {
            if (this.login_user.role_id == 3) return false;
            const cur = this.record ? Number(this.record.status) : 0;
            return ![3, 8].includes(cur);
        },
        timeline() {
            return (this.record && Array.isArray(this.record.timeline)) ? this.record.timeline : [];
        },
        detail() {
            return (this.record && this.record.return_item) ? this.record.return_item : {};
        },
        // Keep only refundable charges (legacy rows without the flag default to true),
        // normalized to {name, amount} and dropping zero amounts.
        refundableAdditional() {
            return this.parseCharges(this.detail.additional_charges).map(c => ({
                name: c.name || __('additional_charge'), amount: Number(c.amount || 0),
            })).filter(c => c.amount > 0);
        },
        refundableSurge() {
            return this.parseCharges(this.detail.surge_charges).map(c => ({
                name: c.label || c.name || __('surge_charge'), amount: Number(c.charge || 0),
            })).filter(c => c.amount > 0);
        },
        variantAttrs() {
            const va = this.detail.variant_attributes;
            if (!Array.isArray(va)) return [];
            return va.map(a => {
                if (a == null) return '';
                if (typeof a === 'string') return a;
                const name = a.attribute_name || a.name || a.attribute || '';
                const val = a.value ?? a.attribute_value ?? '';
                return name ? `${name}: ${val}` : String(val);
            }).filter(Boolean);
        },
        currentIndex() {
            if (!this.record) return -1;
            return this.navList.findIndex(e => String(e.id) === String(this.record.id));
        },
        hasPrev() { return this.currentIndex > 0; },
        hasNext() { return this.currentIndex > -1 && this.currentIndex < this.navList.length - 1; },
    },
    watch: {
        modelValue(v) {
            this.show = v;
            if (v) this.init();
        },
        record() {
            if (this.show) this.init();
        },
        // Lock the background (datatable) from scrolling while the slider is open.
        show(v) {
            document.body.style.overflow = v ? 'hidden' : '';
        },
    },
    beforeUnmount() {
        document.body.style.overflow = '';
    },
    methods: {
        // Open (find-or-create) the order's admin↔customer chat and jump to it.
        chatWithCustomer() {
            const orderId = this.record && this.record.order_id;
            if (!orderId || this.chatStarting) return;
            this.chatStarting = true;
            axios.post(this.$apiUrl + '/chat/start_order', { order_id: orderId }).then(res => {
                if (res.data.status === 1 && res.data.data && res.data.data.id) {
                    this.$router.push({ name: 'Chat', query: { open: res.data.data.id } });
                } else {
                    this.showError(res.data.message || __('something_went_wrong'));
                }
            }).catch(err => {
                this.showError(err.response?.data?.message || __('something_went_wrong'));
            }).finally(() => { this.chatStarting = false; });
        },
        // Charges are stored as JSON on the order item; parse leniently and keep
        // only the refundable ones (legacy rows without the flag default to true).
        parseCharges(raw) {
            let list = raw;
            if (typeof list === 'string') { try { list = JSON.parse(list); } catch (e) { list = []; } }
            if (!Array.isArray(list)) return [];
            return list.filter(c => c && (c.is_refundable === undefined || c.is_refundable));
        },
        openPickupMap() {
            if (this.pickupMapUrl) window.open(this.pickupMapUrl, '_blank');
        },
        blankForm() {
            const r = this.record;
            return {
                id: r ? r.id : null,
                status: r ? String(r.status) : '',
                order_id: r ? r.order_id : '',
                order_item_id: r ? r.order_item_id : '',
                delivery_boy_id: r ? (r.delivery_boy_id || 0) : 0,
                remark: r ? (r.remarks || '') : '',
                reject_reason: r ? (r.reject_reason || '') : '',
            };
        },
        init() {
            this.form = this.blankForm();
            this.rejecting = false;
            // Only the admin assigns delivery boys; fetch the active list once.
            if (this.login_user.role_id != 3 && !this.deliveryBoys.length) this.getDeliveryBoys();
        },
        advance() {
            const next = this.nextStatusId;
            if (!next) return;
            if (next == 4 && !this.form.delivery_boy_id) {
                this.showError(__('select_delivery_boy'));
                return;
            }
            this.form.status = String(next);
            this.form.reject_reason = '';
            this.saveRecord();
        },
        confirmReject() {
            if (!this.form.reject_reason || !this.form.reject_reason.trim()) {
                this.showError(__('reject_reason'));
                return;
            }
            this.form.status = '3';
            this.saveRecord();
        },
        getDeliveryBoys() {
            // Scope to the return's zone — a rider from another zone can't collect it.
            axios.get(this.$apiUrl + '/delivery_boys', {
                params: { filterStatus: 1, zone_id: (this.record && this.record.zone_id) || '' },
            })
                .then(res => {
                    if (res.data && res.data.status === 1) {
                        this.deliveryBoys = res.data.data || [];
                    }
                })
                .catch(() => { });
        },
        getDisplayName(name) {
            if (name == null) return '';
            if (typeof name === 'string') return name;
            if (typeof name === 'object' && !Array.isArray(name)) {
                const appLocale = window.appLocale || (window.localStorage && window.localStorage.getItem('lang')) || 'en';
                const forLocale = name[appLocale];
                if (forLocale != null && String(forLocale).trim() !== '') return String(forLocale).trim();
                const firstNonEmpty = Object.values(name).find(v => v != null && String(v).trim() !== '');
                return firstNonEmpty != null ? String(firstNonEmpty).trim() : '';
            }
            return '';
        },
        statusLabel(status) {
            const m = STATUS_META.find(s => String(s.id) === String(status));
            return m ? __(m.label) : __('undefined');
        },
        statusBadgeClass(status) {
            switch (Number(status)) {
                case 1: return 'bg-warning';
                case 2: return 'bg-success';
                case 3: return 'bg-danger';
                case 4: return 'bg-info';
                case 5: return 'bg-primary';
                case 6: return 'bg-secondary';
                case 7: return 'bg-dark';
                case 8: return 'bg-success';
                default: return 'bg-danger';
            }
        },
        // Open (find-or-create) the admin↔delivery-boy thread for this rider.
        chatWithDeliveryBoy(deliveryBoyId) {
            if (!deliveryBoyId || this.dboyChatStarting) return;
            this.dboyChatStarting = true;
            axios.post(this.$apiUrl + '/chat/start_delivery_boy', { delivery_boy_id: deliveryBoyId }).then(res => {
                if (res.data.status === 1 && res.data.data && res.data.data.id) {
                    this.$router.push({ name: 'Chat', query: { open: res.data.data.id } });
                } else {
                    this.showError(res.data.message || __('something_went_wrong'));
                }
            }).catch(err => {
                this.showError(err.response?.data?.message || __('something_went_wrong'));
            }).finally(() => { this.dboyChatStarting = false; });
        },
        // Deep links: close the slider first, otherwise the overlay outlives the route change.
        openCustomer(id) {
            if (!id || !this.canOpenCustomer) return;
            this.close();
            this.$router.push('/users/view/' + id);
        },
        openProduct(id) {
            if (!id || !this.canOpenProduct) return;
            this.close();
            this.$router.push('/products/view/' + id);
        },
        /** tel: needs digits and a leading +, nothing else. */
        telHref(code, mobile) {
            return 'tel:' + String((code || '') + (mobile || '')).replace(/[^\d+]/g, '');
        },
        close() {
            this.show = false;
            this.$emit('update:modelValue', false);
        },
        goPrev() {
            if (this.hasPrev) this.$emit('navigate', this.navList[this.currentIndex - 1]);
        },
        goNext() {
            if (this.hasNext) this.$emit('navigate', this.navList[this.currentIndex + 1]);
        },
        saveRecord() {
            this.isLoading = true;
            const fd = new FormData();
            for (const key in this.form) fd.append(key, this.form[key]);

            let url = this.$apiUrl + '/return_requests/update';
            if (this.login_user.role_id == 3) {
                url = this.$deliveryBoyApiUrl + '/return_request_status_update';
            }

            axios.post(url, fd).then(res => {
                const data = res.data;
                if (data.status === 1) {
                    this.showMessage('success', data.message);
                    // Reload the list + refresh this record in place (keep slider open).
                    this.$emit('updated');
                } else {
                    this.showError(data.message);
                }
            }).catch(error => {
                this.showError(error.response?.data?.message || error.message || 'Something went wrong!');
            }).finally(() => { this.isLoading = false; });
        },
    },
};
</script>

<style scoped>
.rds-backdrop { position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 1050; }
.rds-panel {
    position: fixed; top: 0; inset-inline-end: 0; height: 100vh; width: 60vw; max-width: 920px;
    background: var(--app-card-bg); z-index: 1051; box-shadow: 0 0 24px rgba(0, 0, 0, .15);
    display: flex; flex-direction: column;
}
@media (max-width: 1199px) { .rds-panel { width: 80vw; max-width: none; } }
@media (max-width: 991px) { .rds-panel { width: 96vw; } }
.rds-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.5rem; border-bottom: 1px solid var(--app-card-border);
}
/* Phones: icon-only tools (labels hidden above), tighter padding, title never wraps. */
@media (max-width: 575.98px) {
    .rds-header { padding: .65rem .75rem; gap: .5rem; }
    .rds-header > div { gap: .35rem !important; min-width: 0; }
    .rds-header h5 { font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rds-header .badge { font-size: .62rem; }
    .rds-header .btn { padding: .3rem .45rem; }
    .rds-header .btn svg { margin: 0; }
}
.rds-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; background: var(--app-thead-bg); }
.rds-footer {
    display: flex; justify-content: flex-end; gap: .5rem;
    padding: .9rem 1.5rem; border-top: 1px solid var(--app-card-border); background: var(--app-card-bg);
}
.rds-card { background: var(--app-card-bg); border: 1px solid var(--app-card-border); border-radius: .7rem; padding: 1rem 1.1rem; margin-bottom: 1rem; }
/* Titles lead with a lucide icon; the global `svg { display: block }` would drop
   it to its own line, so flex keeps icon + text inline. */
.rds-assigned-boy {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .5rem;
    margin-top: .75rem;
    padding: .5rem .7rem;
    border: 1px solid var(--app-card-border, var(--app-card-border));
    border-radius: .5rem;
    background: var(--app-thead-bg);
    font-size: .82rem;
}

/* inline-flex keeps the icon on the label's line — as a plain inline SVG it wrapped. */
.rds-assigned-boy .rds-assigned-label {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    color: var(--app-muted);
    white-space: nowrap;
}

.rds-assigned-boy .rds-assigned-label svg {
    flex-shrink: 0;
    color: var(--bs-primary);
}

.rds-assigned-boy .rds-assigned-value {
    font-weight: 700;
    color: var(--app-ink);
    text-align: right;
}

.rds-card-title { font-weight: 700; font-size: .9rem; margin-bottom: .7rem; color: var(--app-ink); display: flex; align-items: center; gap: .4rem; }
.rds-card-title > span { display: inline-flex; align-items: center; gap: .35rem; }
.rds-card-title svg, .rds-ic { flex-shrink: 0; color: var(--bs-primary); }
/* Buttons: keep icon + label on one line. */
.rds-header .btn { display: inline-flex; align-items: center; gap: .3rem; }
.rds-header .btn svg { display: inline-block; }
.rds-row { display: flex; justify-content: space-between; gap: 1rem; padding: .28rem 0; font-size: .85rem; color: var(--app-muted); }
.rds-refund-line b { font-weight: 500; }
.rds-row b { color: var(--app-ink); text-align: right; }

/* Clickable customer / product / contact values. Colour is inherited from the
   surrounding text — only the cursor and a slight fade mark them as clickable. */
.rds-link { cursor: pointer; color: inherit; text-decoration: none; }
.rds-link:hover { color: inherit; opacity: .7; }
.rds-item-img { width: 54px; height: 54px; object-fit: cover; border-radius: .5rem; background: var(--app-hover); }
.rds-eitem-charges { display: flex; flex-wrap: wrap; gap: .35rem; font-size: .72rem; color: var(--app-muted); }
.rds-eitem-charges span { background: var(--app-thead-bg); border-radius: .35rem; padding: .1rem .45rem; }
.rds-total {
    display: flex; justify-content: space-between; align-items: center;
    background: var(--app-thead-bg); border-radius: .5rem; padding: .5rem .7rem; margin-top: .6rem; font-size: .9rem;
}
.rds-status-card { cursor: pointer; transition: border-color .15s; }
.rds-timeline { list-style: none; margin: 0; padding: 0 0 .35rem .4rem; }
/* Completed steps: primary at half opacity. */
.rds-timeline li {
    position: relative;
    padding: 0 0 .9rem 1.2rem;
    border-left: 2px solid rgba(var(--bs-primary-rgb), .5);
}
.rds-timeline li.current { border-left-color: transparent; padding-bottom: .35rem; }
.rds-tl-dot { position: absolute; left: -7px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: rgba(var(--bs-primary-rgb), .5); }
.rds-tl-dot.first { background: var(--bs-primary); box-shadow: 0 0 0 3px rgba(var(--bs-primary-rgb), .25); }
.rds-tl-content { line-height: 1.25; }
.rds-slide-enter-active, .rds-slide-leave-active { transition: transform .28s ease; }
/* Slide out through the panel's own edge — the opposite direction under RTL. */
.rds-slide-enter-from, .rds-slide-leave-to { transform: translateX(100%); }
body.rtl .rds-slide-enter-from, body.rtl .rds-slide-leave-to { transform: translateX(-100%); }
.rds-fade-enter-active, .rds-fade-leave-active { transition: opacity .28s ease; }
.rds-fade-enter-from, .rds-fade-leave-to { opacity: 0; }
.gap-2 { gap: .5rem; }

/* Tax hint icon — quiet until hovered. */
.rds-tax-i { display: inline-flex; align-items: center; color: #98a0af; cursor: help;
            vertical-align: -2px; margin-left: 3px; }
.rds-tax-i:hover { color: var(--bs-primary, #435ebe); }
</style>
