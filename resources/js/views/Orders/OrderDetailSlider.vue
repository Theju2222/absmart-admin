<template>
    <div>
        <transition name="ods-fade">
            <div v-if="show" class="ods-backdrop" @click="close"></div>
        </transition>

        <transition name="ods-slide">
            <aside v-if="show" class="ods-panel" :class="{ wide: isItemWise }" role="dialog" aria-modal="true">
                <!-- Header -->
                <div class="ods-header">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="mb-0 fw-bold">{{ orderNo }}</h5>
                        <span v-if="order" class="badge" :class="getStatusBadgeClass(order.active_status)">
                            {{ statusLabel(order.active_status) }}
                        </span>
                        <span v-if="isPickup" class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">
                            <PackageCheck :size="13" /> {{ __('self_pickup') }}
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button v-if="chatVisible" class="btn btn-sm btn-outline-primary" :disabled="chatStarting" @click="chatWithCustomer">
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

                <!-- Skeleton while the order loads. -->
                <div class="ods-body" v-if="isLoading">
                    <div class="row g-3">
                        <div v-for="n in 4" :key="'odskel-' + n" class="col-lg-6">
                            <div class="ods-card">
                                <div class="skel skel-line" style="width:40%;height:.9rem;margin-bottom:.9rem"></div>
                                <div class="skel skel-line" style="width:80%"></div>
                                <div class="skel skel-line" style="width:65%"></div>
                                <div class="skel skel-line" style="width:72%;margin-bottom:0"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ods-body" v-if="!isLoading && order">
                    <div class="row g-3">
                        <!-- ============ LEFT COLUMN ============ -->
                        <div class="col-lg-6">
                            <!-- Customer -->
                            <div class="ods-card">
                                <div class="ods-card-title"><User :size="16" class="ods-ic ods-ic-user" /> {{ __('customer') }}</div>
                                <div class="ods-row">
                                    <span>{{ __('name') }}</span>
                                    <b>
                                        <span v-if="canOpenCustomer && order.user_id" class="ods-link"
                                            v-b-tooltip.hover :title="__('view_customer') || __('customer')"
                                            @click="openCustomer(order.user_id)">{{ order.user_name }}</span>
                                        <template v-else>{{ order.user_name }}</template>
                                    </b>
                                </div>
                                <div class="ods-row" v-if="order.user_mobile">
                                    <span>{{ __('phone') }}</span>
                                    <b>
                                        <a v-if="canContact" class="ods-link" :href="telHref(order.user_country_code, order.user_mobile)"
                                            v-b-tooltip.hover :title="__('call')">{{ order.user_country_code }} {{ $filters.mobileMask(order.user_mobile) }}</a>
                                        <template v-else>{{ order.user_country_code }} {{ $filters.mobileMask(order.user_mobile) }}</template>
                                    </b>
                                </div>
                                <div class="ods-row" v-if="order.user_email">
                                    <span>{{ __('email') }}</span>
                                    <b>
                                        <a v-if="canContact" class="ods-link" :href="'mailto:' + order.user_email"
                                            v-b-tooltip.hover :title="__('send_email')">{{ $filters.emailMask(order.user_email) }}</a>
                                        <template v-else>{{ $filters.emailMask(order.user_email) }}</template>
                                    </b>
                                </div>
                            </div>

                            <!-- Pickup at store: the customer collects, so the store is the place and
                                 the customer is only a name and a number. -->
                            <div class="ods-card" v-if="isPickup">
                                <div class="ods-card-title d-flex justify-content-between align-items-center">
                                    <span><PackageCheck :size="15" class="ods-ic ods-ic-addr" /> {{ __('pickup_at_store') }}</span>
                                    <button v-if="storeMapUrl" class="btn btn-sm btn-outline-primary py-0" @click="openStoreMap">
                                        <MapPin :size="15" /> {{ __('store_location') }}
                                    </button>
                                </div>
                                <template v-if="order.store_info">
                                    <div class="ods-row"><span>{{ __('store') }}</span><b>{{ order.store_info.store_name }}</b></div>
                                    <div class="ods-row" v-if="order.store_info.mobile">
                                        <span>{{ __('contact') }}</span>
                                        <b><a class="ods-link" :href="telHref('', order.store_info.mobile)">{{ order.store_info.mobile }}</a></b>
                                    </div>
                                    <div class="ods-row" v-if="order.store_info.preparation_time">
                                        <span>{{ __('preparation_time') }}</span><b>{{ order.store_info.preparation_time }} {{ __('mins') }}</b>
                                    </div>
                                    <div class="small text-muted mt-1">{{ order.store_info.formatted_address || order.store_info.address }}</div>
                                </template>
                                <hr class="my-2" />
                                <template v-if="order.address">
                                    <div class="ods-row" v-if="order.address.name"><span>{{ __('customer') }}</span><b>{{ order.address.name }}</b></div>
                                    <div class="ods-row" v-if="order.address.mobile">
                                        <span>{{ __('mobile') }}</span>
                                        <b>
                                            <a v-if="canContact" class="ods-link" :href="telHref('', order.address.mobile)"
                                                v-b-tooltip.hover :title="__('call')">{{ $filters.mobileMask(order.address.mobile) }}</a>
                                            <template v-else>{{ $filters.mobileMask(order.address.mobile) }}</template>
                                        </b>
                                    </div>
                                    <!-- Billing address (what the invoice's Bill To shows) — a pickup order stores nothing else. -->
                                    <div class="small text-muted mt-1" v-if="order.address.address">
                                        <b class="text-body">{{ __('billing_address') }}:</b> {{ order.address.address }}
                                    </div>
                                </template>
                                <div class="small mt-2" v-if="order.order_note">
                                    <b>{{ __('order_note') }}:</b> {{ order.order_note }}
                                </div>
                            </div>

                            <!-- Delivery Address -->
                            <div class="ods-card" v-else>
                                <div class="ods-card-title d-flex justify-content-between align-items-center">
                                    <span><MapPin :size="15" class="ods-ic ods-ic-addr" /> {{ __('delivery_address') }}</span>
                                    <button v-if="mapUrl" class="btn btn-sm btn-outline-primary py-0" @click="openMap">
                                        <MapPin :size="15" /> {{ __('delivery_location') }}
                                    </button>
                                </div>
                                <template v-if="order.address">
                                    <div class="ods-row" v-if="order.address.name"><span>{{ __('name') }}</span><b>{{ order.address.name }}</b></div>
                                    <div class="ods-row" v-if="order.address.mobile">
                                        <span>{{ __('mobile') }}</span>
                                        <b>
                                            <a v-if="canContact" class="ods-link" :href="telHref('', order.address.mobile)"
                                                v-b-tooltip.hover :title="__('call')">{{ $filters.mobileMask(order.address.mobile) }}</a>
                                            <template v-else>{{ $filters.mobileMask(order.address.mobile) }}</template>
                                            <span v-if="order.address.alternate_mobile">
                                                /
                                                <a v-if="canContact" class="ods-link" :href="telHref('', order.address.alternate_mobile)"
                                                    v-b-tooltip.hover :title="__('call')">{{ $filters.mobileMask(order.address.alternate_mobile) }}</a>
                                                <template v-else>{{ $filters.mobileMask(order.address.alternate_mobile) }}</template>
                                            </span>
                                        </b>
                                    </div>
                                    <div class="small text-muted mt-1">{{ order.address.address }}</div>
                                </template>
                                <div class="small mt-2" v-if="order.order_note">
                                    <b>{{ __('order_note') }}:</b> {{ order.order_note }}
                                </div>
                            </div>

                            <!-- Live Tracking Map -->
                            <div class="ods-card" v-if="canShowLiveTracking">
                                <div class="ods-card-title d-flex justify-content-between align-items-center">
                                    <span><Bike :size="15" class="ods-ic ods-ic-boy" /> Live Tracking</span>
                                    <button class="btn btn-sm" :class="liveTrackShow ? 'btn-outline-secondary' : 'btn-success'" @click="toggleLiveTracking">
                                        <component :is="liveTrackShow ? 'X' : 'MapPinned'" :size="15" />
                                        {{ liveTrackShow ? __('close') : __('track_delivery') }}
                                    </button>
                                </div>
                                <div v-show="liveTrackShow" ref="liveMap" class="ods-live-map"></div>
                            </div>

                            <!-- Order Items (quick: order-wise list) -->
                            <div class="ods-card" v-if="!isItemWise">
                                <div class="ods-card-title"><Package :size="16" class="ods-ic ods-ic-items" /> {{ __('order_items') }}</div>
                                <div v-for="item in order_items" :key="item.id" class="ods-item">
                                    <img v-if="item.image" :src="item.image" class="ods-item-img" alt="" />
                                    <div class="flex-grow-1">
                                        <div class="fw-bold small" :class="{ 'ods-link': canOpenProduct && item.product_id }"
                                            @click="openProduct(item.product_id)">{{ item.product_name }}</div>
                                        <small class="text-muted"> {{ __('qty') }}: {{ item.quantity }} × {{ cur }}{{ item.discounted_price }}</small>
                                        <div v-if="item.variant_attributes && item.variant_attributes.length" class="text-muted" style="font-size:.72rem">
                                            {{ item.variant_attributes.map(a => a.name + ': ' + a.value).join(' · ') }}
                                        </div>
                                        <div><span class="badge badge-sm" :class="getStatusBadgeClass(item.active_status)">{{ statusLabel(item.active_status) }}</span></div>
                                        <div v-if="item.prescription_url" class="mt-1">
                                            <a :href="item.prescription_url" target="_blank" rel="noopener"
                                                class="btn btn-sm btn-outline-primary ods-prescription-btn">
                                                <FileText :size="12" class="me-1" />{{ __('view_prescription') }}
                                            </a>
                                        </div>
                                    </div>
                                    <div class="text-end fw-bold">{{ cur }}{{ item.sub_total }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- ============ RIGHT COLUMN ============ -->
                        <div class="col-lg-6">
                            <!-- Change Status (order-wise; ecommerce manages per item) -->
                            <div class="ods-card" v-if="(isDeliveryBoy || canUpdate) && !isItemWise">
                                <div class="ods-card-title"><RefreshCcw :size="16" class="ods-ic ods-ic-status" /> {{ __('update_status') }}</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button v-if="nextOrderStatus" class="btn btn-primary" :disabled="isLoadingUstatus" @click="advanceOrder">
                                        <b-spinner small v-if="isLoadingUstatus"></b-spinner>
                                        <ArrowRight v-else :size="15" class="me-1" />{{ __('mark_as') }} {{ getStatusDisplayName(nextOrderStatus) }}
                                    </button>
                                    <button v-if="canCancelOrder" class="btn btn-outline-danger" :disabled="isLoadingUstatus" @click="cancelOrder">
                                        <X :size="15" class="me-1" />{{ __('cancel_order') }}
                                    </button>
                                    <span v-if="!nextOrderStatus && !canCancelOrder" class="badge align-self-center" :class="getStatusBadgeClass(order.active_status)">{{ statusLabel(order.active_status) }}</span>
                                </div>
                            </div>

                            <!-- Cancellation reason (quick = order-wise; stored per item, same for all) -->
                            <div class="ods-card ods-cancel-reason" v-if="!isItemWise && Number(order.active_status) === 7 && quickCancellationReason">
                                <div class="ods-card-title"><X :size="16" class="ods-ic ods-ic-cancel" /> {{ __('cancellation_reason') }}</div>
                                <div class="ods-cancel-reason-text">{{ quickCancellationReason }}</div>
                            </div>

                            <!-- Delivery boy (order-wise; ecommerce assigns per item). Stays visible once
                                 the order reaches a terminal status so a delivered order still shows who
                                 delivered it; the select + button appear only while it can be changed. -->
                            <div class="ods-card" v-if="!isItemWise && !isPickup && (canAssignQuickDboy || quickDeliveryBoyName)">
                                <div class="ods-card-title">
                                    <Bike :size="16" class="ods-ic ods-ic-boy" />
                                    {{ canAssignQuickDboy ? __('assign_delivery_boy') : __('delivery_boy') }}
                                    <button v-if="canChatDeliveryBoy && Number(order.delivery_boy_id) > 0"
                                        type="button" class="list-action-btn is-view ms-auto"
                                        v-b-tooltip.hover :title="__('chat_with_delivery_boy')"
                                        :disabled="dboyChatStarting" @click="chatWithDeliveryBoy(order.delivery_boy_id)">
                                        <b-spinner small v-if="dboyChatStarting"></b-spinner>
                                        <MessageCircle v-else :size="15" />
                                    </button>
                                </div>
                                <div class="ods-row" v-if="quickDeliveryBoyName">
                                    <span>{{ __('name') }}</span><b>{{ quickDeliveryBoyName }}</b>
                                </div>
                                <template v-if="canAssignQuickDboy">
                                    <AppSelect class="form-select mb-2 mt-1" v-model="delivery_boy_id"
                                        :options="deliveryBoyOptions" />
                                    <button class="btn btn-primary w-100" :style="{ backgroundColor: primaryColor, borderColor: primaryColor }"
                                        :disabled="isLoadingDboy" @click="assignDeliveryBoy">
                                        <b-spinner small v-if="isLoadingDboy"></b-spinner>
                                        {{ Number(delivery_boy_id) === 0 ? __('unassign') : __('update') }}
                                    </button>
                                </template>
                            </div>

                            <!-- Billing (incl. payment method) -->
                            <div class="ods-card">
                                <div class="ods-card-title"><ReceiptText :size="16" class="ods-ic ods-ic-bill" /> {{ __('billing_details') }}</div>
                                <div class="ods-row align-items-center" v-if="storeName">
                                    <span>{{ __('store') }}</span><b>{{ storeName }}</b>
                                </div>
                                <div class="ods-row align-items-center">
                                    <span>{{ __('payment_method') }}</span>
                                    <b><span class="badge bg-light text-dark border">{{ order.payment_method }}</span></b>
                                </div>

                                <!-- Ecommerce: per-item charges already shown above; here just the total paid -->
                                <template v-if="isItemWise">
                                    <div class="ods-row" v-if="Number(order.wallet_balance) > 0"><span>{{ __('wallet_used') }}</span><b>{{ cur }}{{ order.wallet_balance }}</b></div>
                                    <div class="ods-total"><span>{{ __('total_paid') }}</span><b>{{ cur }}{{ totalPaid(order) }}</b></div>
                                </template>

                                <!-- Quick: full order-level breakdown -->
                                <template v-else>
                                    <div class="ods-row">
                                        <span>
                                            {{ __('total') }}
                                            <span v-if="orderTaxTotal > 0" v-b-tooltip.hover
                                                  :title="breakdownHint(order.tax_breakdown)" class="ods-tax-i"><Info :size="13" /></span>
                                        </span>
                                        <b>{{ cur }}{{ order.total }}</b>
                                    </div>
                                    <div class="ods-row" v-if="Number(order.promo_discount) > 0">
                                        <span>{{ __('promo_discount') }}<small v-if="order.promo_code"> ({{ order.promo_code }})</small></span>
                                        <b>- {{ cur }}{{ order.promo_discount }}</b>
                                    </div>
                                    <div class="ods-row">
                                        <span>
                                            {{ __('delivery_charge') }}
                                            <span v-if="chargeTaxHint(order.delivery_charges)" v-b-tooltip.hover :title="chargeTaxHint(order.delivery_charges)" class="ods-tax-i"><Info :size="13" /></span>
                                        </span>
                                        <b>{{ cur }}{{ order.delivery_charges ? order.delivery_charges.amount : order.delivery_charge }}</b>
                                    </div>
                                    <div class="ods-row" v-for="(s, i) in surgeCharges" :key="'s' + i">
                                        <span>
                                            {{ s.label || s.name || __('surge_charges') }}
                                            <span v-if="chargeTaxHint(s)" v-b-tooltip.hover :title="chargeTaxHint(s)" class="ods-tax-i"><Info :size="13" /></span>
                                        </span>
                                        <b>{{ cur }}{{ s.charge ?? s.amount }}</b>
                                    </div>
                                    <div class="ods-row" v-for="(a, i) in additionalCharges" :key="'a' + i">
                                        <span>
                                            {{ a.name || a.title || a.label || __('additional_charges') }}
                                            <span v-if="chargeTaxHint(a)" v-b-tooltip.hover :title="chargeTaxHint(a)" class="ods-tax-i"><Info :size="13" /></span>
                                        </span>
                                        <b>{{ cur }}{{ a.amount ?? a.charge }}</b>
                                    </div>
                                    <div class="ods-row" v-if="Number(order.wallet_balance) > 0"><span>{{ __('wallet_used') }}</span><b>{{ cur }}{{ order.wallet_balance }}</b></div>
                                    <div class="ods-total"><span>{{ __('total_paid') }}</span><b>{{ cur }}{{ totalPaid(order) }}</b></div>
                                </template>
                            </div>

                            <!-- Timeline (order-wise). Ecommerce shows per-item timelines instead, and
                                 puts the invoice action in the Order Items header → card is quick-only. -->
                            <div class="ods-card" v-if="!isItemWise">
                                <div class="ods-card-title"><CalendarDays :size="16" class="ods-ic ods-ic-time" /> {{ __('timeline') }}</div>
                                <ul class="ods-timeline" v-if="timelineDesc.length">
                                    <li v-for="(t, i) in timelineDesc" :key="i"
                                        :class="{ current: t.is_current, pending: !t.is_done, last: i === timelineDesc.length - 1 }">
                                        <div class="ods-tl-dot" :class="{ first: t.is_current, pending: !t.is_done }"></div>
                                        <div class="ods-tl-content">
                                            <div class="fw-bold small">{{ t.status_name }}</div>
                                            <small class="text-muted" v-if="t.is_done">{{ $filters.formatDateTime(t.datetime) }}<span v-if="t.created_by"> · {{ t.created_by }}</span></small>
                                        </div>
                                    </li>
                                </ul>
                                <div v-else class="text-muted small mb-2">{{ __('no_records_found') }}</div>

                                <!-- Invoice: view opens the PDF in a tab, download saves it. -->
                                <div v-if="!isDeliveryBoy" class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-secondary w-50" @click="viewInvoice()"
                                        :disabled="isViewingInvoice" v-b-tooltip.hover :title="__('view_invoice')">
                                        <b-spinner small v-if="isViewingInvoice"></b-spinner>
                                        <FileText v-else :size="15" /> {{ __('view') }}
                                    </button>
                                    <button class="btn btn-sm btn-secondary w-50" @click="downloadInvoice"
                                        :disabled="isLoadingInvoice" v-b-tooltip.hover :title="__('download_order_invoice')">
                                        <b-spinner small v-if="isLoadingInvoice"></b-spinner>
                                        <Download v-else :size="15" /> {{ __('download') }}
                                    </button>
                                </div>

                                <!-- Paper size comes from Settings → Invoice, so there is nothing to ask. -->
                                <button v-if="!isDeliveryBoy" class="btn btn-sm btn-outline-secondary w-100 mt-2"
                                    :disabled="isLoadingReceipt" @click="downloadReceipt()">
                                    <b-spinner small v-if="isLoadingReceipt"></b-spinner>
                                    <Printer v-else :size="15" /> {{ __('print_delivery_receipt') }}
                                </button>
                            </div>
                        </div>

                        <!-- Ecommerce: full-width item-wise management (product | summary | timeline) -->
                        <div v-if="isItemWise" class="col-12">
                            <div class="ods-card">
                                <div class="ods-card-title">
                                    <Package :size="16" class="ods-ic ods-ic-items" /> {{ __('order_items') }}
                                    <small class="text-muted ms-1">({{ __('managed_item_wise') }})</small>
                                    <!-- Multi-select: one action for many items, one notification. -->
                                    <div v-if="selectableItems.length > 1" class="form-check ods-bulk-all mb-0 ms-2">
                                        <input class="form-check-input" type="checkbox" id="ods-bulk-all"
                                            :checked="allSelected" @change="toggleAllItems($event)" />
                                        <label class="form-check-label small text-muted" for="ods-bulk-all">{{ __('select_all') }}</label>
                                    </div>
                                    <!-- Order-wide documents sit in the card title, so they stay
                                         icon buttons — same pair and order as the item rows below. -->
                                    <button v-if="!isDeliveryBoy" type="button" class="list-action-btn is-view ms-auto"
                                        v-b-tooltip.hover :title="__('view_invoice')"
                                        :disabled="isViewingInvoice" @click="viewInvoice()">
                                        <b-spinner small v-if="isViewingInvoice"></b-spinner>
                                        <FileText v-else :size="15" />
                                    </button>
                                    <button v-if="!isDeliveryBoy" type="button" class="list-action-btn is-view"
                                        v-b-tooltip.hover :title="__('download_order_invoice')"
                                        :disabled="isLoadingInvoice" @click="downloadInvoice">
                                        <b-spinner small v-if="isLoadingInvoice"></b-spinner>
                                        <Download v-else :size="15" />
                                    </button>
                                    <button v-if="!isDeliveryBoy" type="button" class="list-action-btn is-view"
                                        v-b-tooltip.hover :title="__('print_delivery_receipt')"
                                        :disabled="isLoadingReceipt" @click="downloadReceipt()">
                                        <b-spinner small v-if="isLoadingReceipt"></b-spinner>
                                        <Printer v-else :size="15" />
                                    </button>
                                </div>
                                <!-- Bulk bar: every action below sends ONE customer notification for
                                     the whole selection ("Blue Shirt +2 more"). -->
                                <div v-if="selectedItemIds.length" class="ods-bulk-bar">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-bold small">{{ selectedItemIds.length }} {{ __('items_selected') }}</span>
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                                            @click="clearSelection">{{ __('clear_selection') }}</button>
                                    </div>
                                    <div class="ods-bulk-controls">
                                        <button v-if="bulkNextStatus" class="btn btn-sm btn-primary"
                                            :style="{ backgroundColor: primaryColor, borderColor: primaryColor }"
                                            :disabled="!!bulkBusy" @click="bulkUpdateStatus">
                                            <b-spinner small v-if="bulkBusy === 'status'"></b-spinner>{{ __('mark_as') }} {{ getStatusDisplayName(bulkNextStatus) }}
                                        </button>
                                        <div v-if="canBulkAssignDboy" class="ods-bulk-group">
                                            <AppSelect class="form-select form-select-sm" v-model="bulkDboy"
                                                :options="deliveryBoyOptions" />
                                            <button class="btn btn-sm btn-primary flex-shrink-0"
                                                :style="{ backgroundColor: primaryColor, borderColor: primaryColor }"
                                                :disabled="!!bulkBusy || !Number(bulkDboy)" @click="bulkAssignDeliveryBoy">
                                                <b-spinner small v-if="bulkBusy === 'dboy'"></b-spinner>{{ __('assign') }}
                                            </button>
                                        </div>
                                        <button v-if="canBulkCancel" class="btn btn-sm btn-outline-danger"
                                            :disabled="!!bulkBusy" @click="bulkCancel">
                                            <b-spinner small v-if="bulkBusy === 'cancel'"></b-spinner>{{ __('cancel_items') }}
                                        </button>
                                    </div>
                                    <!-- Courier: the selection treated as ONE consignment — same
                                         agency / AWB / URL on every item. -->
                                    <div v-if="canBulkTrack" class="ods-bulk-controls">
                                        <input class="form-control form-control-sm ods-bulk-track" v-model="bulkTracking.courier_agency"
                                            :placeholder="__('courier_agency')" />
                                        <input class="form-control form-control-sm ods-bulk-track" v-model="bulkTracking.tracking_id"
                                            :placeholder="__('tracking_id')" />
                                        <input class="form-control form-control-sm ods-bulk-track" v-model="bulkTracking.tracking_url"
                                            :placeholder="__('tracking_url')" />
                                        <button class="btn btn-sm btn-primary flex-shrink-0"
                                            :style="{ backgroundColor: primaryColor, borderColor: primaryColor }"
                                            :disabled="!!bulkBusy" @click="bulkSaveTracking">
                                            <b-spinner small v-if="bulkBusy === 'tracking'"></b-spinner>{{ __('save_tracking') }}
                                        </button>
                                    </div>
                                    <div v-if="!bulkNextStatus" class="text-muted" style="font-size:.72rem">
                                        {{ __('no_common_status_for_selected_items') }}
                                    </div>
                                </div>
                                <div v-for="item in order_items" :key="item.id" class="ods-eitem"
                                    :class="{ 'is-selected': selectedItemIds.includes(item.id) }">
                                    <div class="row g-3">
                                        <!-- 1) product + controls -->
                                        <div class="col-md-5">
                                            <div class="d-flex gap-2">
                                                <input v-if="isSelectable(item)" class="form-check-input ods-item-check flex-shrink-0"
                                                    type="checkbox" :value="item.id" v-model="selectedItemIds" />
                                                <img v-if="item.image" :src="item.image" class="ods-item-img" alt="" />
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-start gap-1">
                                                        <span class="fw-bold small" :class="{ 'ods-link': canOpenProduct && item.product_id }"
                                                            @click="openProduct(item.product_id)">{{ item.product_name }}</span>
                                                        <span class="badge badge-sm flex-shrink-0" :class="getStatusBadgeClass(item.active_status)">{{ statusLabel(item.active_status) }}</span>
                                                    </div>
                                                    <small class="text-muted">{{ __('qty') }}: {{ item.quantity }} × {{ cur }}{{ item.discounted_price }}</small>
                                                    <div v-if="item.variant_attributes && item.variant_attributes.length" class="text-muted" style="font-size:.72rem">
                                                        {{ item.variant_attributes.map(a => a.name + ': ' + a.value).join(' · ') }}
                                                    </div>
                                                    <div v-if="item.prescription_url" class="mt-1">
                                                        <a :href="item.prescription_url" target="_blank" rel="noopener"
                                                            class="btn btn-sm btn-outline-primary ods-prescription-btn">
                                                            <FileText :size="12" class="me-1" />{{ __('view_prescription') }}
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Status advance + cancel -->
                                            <div v-if="isDeliveryBoy || canUpdate" class="ods-eitem-controls">
                                                <div class="d-flex flex-wrap gap-1">
                                                    <button v-if="nextItemStatus(item)" class="btn btn-primary btn-sm" :disabled="itemBusy[item.id]" @click="advanceItem(item)">
                                                        <b-spinner small v-if="itemBusy[item.id]"></b-spinner>{{ __('mark_as') }} {{ getStatusDisplayName(nextItemStatus(item)) }}
                                                    </button>
                                                    <button v-if="canCancelItem(item)" class="btn btn-outline-danger btn-sm" :disabled="itemBusy[item.id]" @click="cancelItem(item)">
                                                        {{ __('cancel_item') }}
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Cancellation reason (ecommerce = item-wise) -->
                                            <div v-if="Number(item.active_status) === 7 && item.cancellation_reason" class="ods-cancel-reason-inline mt-2">
                                                <strong>{{ __('cancellation_reason') }}:</strong> {{ item.cancellation_reason }}
                                            </div>

                                            <!-- Fulfillment: read-only for terminal statuses -->
                                            <template v-if="[6,7,8].includes(Number(item.active_status))">
                                                <div v-if="Number(item.delivery_boy_id) > 0" class="ods-accordion ods-accordion--active mt-2" :style="{ borderColor: primaryColor }">
                                                    <div class="ods-accordion-header" :style="{ background: primaryColor + '18', color: primaryColor }">
                                                        <span><Bike :size="15" class="ods-ic ods-ic-boy" /> {{ __('delivery_boy') }}</span>
                                                        <button v-if="canChatDeliveryBoy" type="button" class="list-action-btn is-view ms-auto"
                                                            v-b-tooltip.hover :title="__('chat_with_delivery_boy')"
                                                            :disabled="dboyChatStarting" @click.stop="chatWithDeliveryBoy(item.delivery_boy_id)">
                                                            <b-spinner small v-if="dboyChatStarting"></b-spinner>
                                                            <MessageCircle v-else :size="14" />
                                                        </button>
                                                    </div>
                                                    <div class="ods-accordion-body">
                                                        <AppSelect class="form-select form-select-sm"
                                                            v-model="itemDboy[item.id]" :options="deliveryBoyOptions"
                                                            disabled />
                                                    </div>
                                                </div>
                                                <div v-else-if="item.tracking_id" class="ods-accordion ods-accordion--active mt-2" :style="{ borderColor: primaryColor }">
                                                    <div class="ods-accordion-header" :style="{ background: primaryColor + '18', color: primaryColor }">
                                                        <span><Truck :size="15" class="ods-ic ods-ic-track" /> {{ __('order_tracking') }}</span>
                                                    </div>
                                                    <div class="ods-accordion-body">
                                                        <input class="form-control form-control-sm mb-1" :value="item.courier_agency" :placeholder="__('courier_agency')" disabled />
                                                        <input class="form-control form-control-sm mb-1" :value="item.tracking_id" :placeholder="__('tracking_id')" disabled />
                                                        <input class="form-control form-control-sm" :value="item.tracking_url" :placeholder="__('tracking_url')" disabled />
                                                    </div>
                                                </div>
                                            </template>

                                            <!-- Payment pending: no fulfillment until paid. -->
                                            <div v-else-if="Number(item.active_status) === 1" class="ods-cancel-reason-inline mt-2">
                                                {{ __('cannot_assign_delivery_boy_while_payment_pending') }}
                                            </div>

                                            <!-- Fulfillment: editable for non-terminal items (admin only) -->
                                            <div v-else-if="!isDeliveryBoy && canUpdate" class="ods-fulfillment mt-2">
                                                <!-- Delivery Boy accordion — hidden once courier tracking is set -->
                                                <div v-if="!item.tracking_id" class="ods-accordion" :class="{ 'ods-accordion--active': Number(item.delivery_boy_id) > 0 }" :style="Number(item.delivery_boy_id) > 0 ? { borderColor: primaryColor } : {}">
                                                    <div class="ods-accordion-header" :style="Number(item.delivery_boy_id) > 0 ? { background: primaryColor + '18', color: primaryColor } : {}" @click="Number(item.delivery_boy_id) === 0 && toggleFulfillment(item.id, 'db')">
                                                        <span><Bike :size="15" class="ods-ic ods-ic-boy" /> {{ __('delivery_boy') }}</span>
                                                        <button v-if="canChatDeliveryBoy && Number(item.delivery_boy_id) > 0"
                                                            type="button" class="list-action-btn is-view ms-auto"
                                                            v-b-tooltip.hover :title="__('chat_with_delivery_boy')"
                                                            :disabled="dboyChatStarting" @click.stop="chatWithDeliveryBoy(item.delivery_boy_id)">
                                                            <b-spinner small v-if="dboyChatStarting"></b-spinner>
                                                            <MessageCircle v-else :size="14" />
                                                        </button>
                                                        <component v-if="Number(item.delivery_boy_id) === 0" :is="itemFulfillmentOpen[item.id] === 'db' ? 'ChevronUp' : 'ChevronDown'" class="ms-auto" :size="13" style="color:#aab0be" />
                                                    </div>
                                                    <div v-show="itemFulfillmentOpen[item.id] === 'db' || Number(item.delivery_boy_id) > 0" class="ods-accordion-body">
                                                        <div class="d-flex gap-1">
                                                            <AppSelect class="form-select form-select-sm flex-grow-1"
                                                                v-model="itemDboy[item.id]" :options="deliveryBoyOptions"
                                                                :disabled="Number(item.delivery_boy_id) > 0" />
                                                            <button v-if="Number(item.delivery_boy_id) === 0" class="btn btn-sm btn-primary flex-shrink-0" :disabled="itemBusy[item.id]" @click="assignItemDeliveryBoy(item)">
                                                                <b-spinner small v-if="itemBusy[item.id]"></b-spinner>
                                                                {{ __('assign') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Courier Tracking accordion — hidden once delivery boy is assigned -->
                                                <div v-if="Number(item.delivery_boy_id) === 0" class="ods-accordion" :class="{ 'ods-accordion--active': item.tracking_id }" :style="item.tracking_id ? { borderColor: primaryColor } : {}">
                                                    <div class="ods-accordion-header" :style="item.tracking_id ? { background: primaryColor + '18', color: primaryColor } : {}" @click="toggleFulfillment(item.id, 'courier')">
                                                        <span><Truck :size="15" class="ods-ic ods-ic-track" /> {{ __('order_tracking') }}</span>
                                                        <component :is="itemFulfillmentOpen[item.id] === 'courier' ? 'ChevronUp' : 'ChevronDown'" class="ms-auto" :size="13" style="color:#aab0be" />
                                                    </div>
                                                    <div v-show="itemFulfillmentOpen[item.id] === 'courier'" class="ods-accordion-body">
                                                        <input class="form-control form-control-sm mb-1" v-model="itemTracking[item.id].courier_agency" :placeholder="__('courier_agency')" />
                                                        <input class="form-control form-control-sm mb-1" v-model="itemTracking[item.id].tracking_id" :placeholder="__('tracking_id')" />
                                                        <input class="form-control form-control-sm mb-2" type="url" v-model="itemTracking[item.id].tracking_url" :placeholder="__('tracking_url')" />
                                                        <div class="text-end">
                                                            <button class="btn btn-sm btn-primary" :disabled="itemBusy[item.id]" @click="saveItemTracking(item)">
                                                                <b-spinner small v-if="itemBusy[item.id]"></b-spinner>
                                                                {{ __('save') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 2) summary: all charges -->
                                        <div class="col-md-4">
                                            <div class="ods-eitem-sum">
                                                <div class="ods-row">
                                                    <span>
                                                        {{ __('sub_total') }}
                                                        <span v-if="itemTaxTotal(item) > 0" v-b-tooltip.hover
                                                              :title="breakdownHint(item.tax_breakdown)" class="ods-tax-i"><Info :size="13" /></span>
                                                    </span>
                                                    <b>{{ cur }}{{ item.sub_total }}</b>
                                                </div>
                                                <div class="ods-row" v-if="Number(item.delivery_charges ? item.delivery_charges.amount : item.delivery_charge) > 0">
                                                    <span>
                                                        {{ __('delivery_charge') }}
                                                        <span v-if="chargeTaxHint(item.delivery_charges)" v-b-tooltip.hover :title="chargeTaxHint(item.delivery_charges)" class="ods-tax-i"><Info :size="13" /></span>
                                                    </span>
                                                    <b>{{ cur }}{{ item.delivery_charges ? item.delivery_charges.amount : item.delivery_charge }}</b>
                                                </div>
                                                <div class="ods-row" v-for="(a, ai) in (item.additional_charges || [])" :key="'a'+ai">
                                                    <span>
                                                        {{ a.name || __('additional_charges') }}
                                                        <span v-if="chargeTaxHint(a)" v-b-tooltip.hover :title="chargeTaxHint(a)" class="ods-tax-i"><Info :size="13" /></span>
                                                    </span>
                                                    <b>{{ cur }}{{ a.amount }}</b>
                                                </div>
                                                <div class="ods-row" v-for="(s, si) in (item.surge_charges || [])" :key="'s'+si">
                                                    <span>
                                                        {{ s.label || __('surge_charges') }}
                                                        <span v-if="chargeTaxHint(s)" v-b-tooltip.hover :title="chargeTaxHint(s)" class="ods-tax-i"><Info :size="13" /></span>
                                                    </span>
                                                    <b>{{ cur }}{{ s.charge }}</b>
                                                </div>
                                                <div class="ods-row" v-if="Number(item.promo_discount) > 0"><span>{{ __('promo_discount') }}</span><b>- {{ cur }}{{ item.promo_discount }}</b></div>
                                                <div class="ods-row" v-if="Number(item.wallet_balance) > 0"><span>{{ __('wallet_used') }}</span><b>{{ cur }}{{ item.wallet_balance }}</b></div>
                                                <div class="ods-total"><span>{{ __('total_paid') }}</span><b>{{ cur }}{{ totalPaid(item) }}</b></div>
                                            </div>
                                            <!-- Promised delivery date. Editable while the item is still
                                                 in flight; saving it notifies the customer. -->
                                            <div class="ods-eta mt-2" v-if="isItemWise">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="ods-eta-k">{{ __('estimated_delivery_date') }}</span>
                                                    <b>{{ item.estimated_delivery_date ? formatEtaDate(item.estimated_delivery_date) : '—' }}</b>
                                                </div>
                                                <div class="d-flex gap-2 mt-2" v-if="!isDeliveryBoy && canEditEstimate(item)">
                                                    <DatePicker class="flex-grow-1"
                                                        :model-value="etaDraft[item.id] ?? item.estimated_delivery_date ?? ''"
                                                        :placeholder="__('estimated_delivery_date')"
                                                        @update:model-value="etaDraft[item.id] = $event" />
                                                    <button class="btn btn-sm btn-outline-primary text-nowrap"
                                                        :disabled="etaBusy[item.id]"
                                                        @click="saveDeliveryEstimate(item)">
                                                        <b-spinner small v-if="etaBusy[item.id]"></b-spinner>
                                                        <span v-else>{{ __('update') }}</span>
                                                    </button>
                                                </div>
                                            </div>
                                            <div v-if="!isDeliveryBoy" class="d-flex gap-2 mt-2">
                                                <button class="btn btn-sm btn-outline-secondary w-50"
                                                    :disabled="itemInvoiceViewBusy[item.id]" @click="viewInvoice(item)"
                                                    v-b-tooltip.hover :title="__('view_invoice')">
                                                    <b-spinner small v-if="itemInvoiceViewBusy[item.id]"></b-spinner>
                                                    <FileText v-else :size="15" /> {{ __('view') }}
                                                </button>
                                                <button class="btn btn-sm btn-secondary w-50"
                                                    :disabled="itemInvoiceBusy[item.id]" @click="downloadItemInvoice(item)">
                                                    <b-spinner small v-if="itemInvoiceBusy[item.id]"></b-spinner>
                                                    <Download v-else :size="15" /> {{ __('download') }}
                                                </button>
                                            </div>
                                            <button v-if="!isDeliveryBoy" class="btn btn-sm btn-outline-secondary w-100 mt-2"
                                                :disabled="itemReceiptBusy[item.id]" @click="downloadReceipt(item)">
                                                <b-spinner small v-if="itemReceiptBusy[item.id]"></b-spinner>
                                                <Printer v-else :size="15" /> {{ __('print_delivery_receipt') }}
                                            </button>
                                        </div>

                                        <!-- 3) timeline -->
                                        <div class="col-md-3">
                                            <div class="ods-card-title small mb-2"><CalendarDays :size="15" class="ods-ic ods-ic-time" /> {{ __('timeline') }}</div>
                                            <ul v-if="(item.timeline || []).length" class="ods-timeline">
                                                <li v-for="(t, i) in (item.timeline || [])" :key="i"
                                                    :class="{ current: t.is_current, pending: !t.is_done, last: i === (item.timeline || []).length - 1 }">
                                                    <div class="ods-tl-dot" :class="{ first: t.is_current, pending: !t.is_done }"></div>
                                                    <div class="ods-tl-content">
                                                        <div class="fw-bold small">{{ t.status_name }}</div>
                                                        <small class="text-muted" v-if="t.is_done">{{ $filters.formatDateTime(t.datetime) }}<span v-if="t.created_by"> · {{ t.created_by }}</span></small>
                                                    </div>
                                                </li>
                                            </ul>
                                            <div v-else class="text-muted small">{{ __('no_records_found') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else-if="!isLoading" class="ods-body text-center pt-5 text-muted">
                    {{ __('no_records_found') }}
                </div>
            </aside>
        </transition>
    </div>
</template>

<script>
import axios from "axios";
import Auth from "../../Auth.js";
import L from 'leaflet';
import { osmTileUrl, OSM_TILE_OPTIONS } from '../../utils/mapTiles.js';
import { loadGoogleMaps } from '../../utils/googleMaps.js';
import DatePicker from '../../components/DatePicker.vue';
import {
    FileText, ChevronLeft, ChevronRight, X, MapPin, ArrowRight, Download,
    MapPinned, ChevronUp, ChevronDown, PackageCheck,
    User, Bike, Package, RefreshCcw, ReceiptText, CalendarDays, Truck, MessageCircle, Info, Printer,
} from 'lucide-vue-next';
import 'leaflet/dist/leaflet.css';

const LIVE_SVG = {
    store: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 1px 2px #0006)"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63l-.79-.62a2 2 0 0 0-2.44 0l-.8.62a2.7 2.7 0 0 1-1.58.63a2.7 2.7 0 0 1-1.59-.63l-.79-.62a2 2 0 0 0-2.44 0l-.8.62A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>',
    home: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 1px 2px #0006)"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>',
    boy: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 1px 2px #0006)"><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/></svg>',
};
const svgDataUrl = svg => 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);

export default {
    components: {
        FileText, ChevronLeft, ChevronRight, X, MapPin, ArrowRight, Download, Printer,
        MapPinned, ChevronUp, ChevronDown, PackageCheck,
        User, Bike, Package, RefreshCcw, ReceiptText, CalendarDays, Truck, MessageCircle, Info,
        DatePicker,
    },
    name: 'OrderDetailSlider',
    props: {
        modelValue: { type: Boolean, default: false },
        orderId: { type: [Number, String], default: null },
        // 'admin' (default) or 'delivery_boy' — switches API endpoints + hides admin-only controls.
        mode: { type: String, default: 'admin' },
        // Delivery-boy ecommerce: the clicked order item. The order's items are then filtered
        // to only the ones assigned to the same delivery boy (a boy sees only their variants).
        orderItemId: { type: [Number, String], default: null },
        // Ordered list for prev/next navigation. Each entry: { id, itemId? }.
        // (quick/admin: itemId null; ecom item-wise: itemId = order_item_id.)
        navList: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue', 'updated', 'navigate'],
    data() {
        return {
            show: this.modelValue,
            isLoading: false,
            isLoadingUstatus: false,
            isLoadingDboy: false,
            isLoadingInvoice: false,
            isViewingInvoice: false,
            isLoadingReceipt: false,
            itemReceiptBusy: {},
            chatStarting: false,
            etaDraft: {},
            etaBusy: {},
            mapProvider: 'osm',
            googleMapKey: '',
            dboyChatStarting: false,
            order: null,
            order_items: [],
            timeline: [],
            itemStatus: {},
            itemDboy: {},
            itemBusy: {},
            itemInvoiceBusy: {},
            itemInvoiceViewBusy: {},
            itemTracking: {},
            itemFulfillmentOpen: {}, // per item: 'db' | 'courier' | null
            selectedItemIds: [],     // ecommerce multi-select
            bulkDboy: 0,
            bulkTracking: { courier_agency: '', tracking_id: '', tracking_url: '' },
            bulkBusy: '', // '' | 'status' | 'dboy' | 'cancel' | 'tracking' — spins only its own button
            deliveryBoys: [],
            statuses: [],
            payment_status: 'pending',
            order_status_id: '',
            delivery_boy_id: 0,
            liveTrackShow: false,
            liveTrackInterval: null,
        };
    },
    computed: {
        framePrintFails() {
            const ua = navigator.userAgent;
            const isIos = /iPad|iPhone|iPod/.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const isSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Android/.test(ua);
            return isSafari || isIos;
        },
        // Tax on the order as a whole, from its frozen breakdown.
        orderTaxTotal() {
            return this.sumBreakdown(this.order && this.order.tax_breakdown);
        },
        // Names are translated objects; 0 is the real "unassigned" value.
        deliveryBoyOptions() {
            return [{ id: 0, name: '— ' + __('unassigned') + ' —' }]
                .concat((this.deliveryBoys || []).map(b => ({ id: b.id, name: this.getDisplayName(b.name) })));
        },
        orderNo() {
            // Prefer the snapshotted order number (ORD-00042); fall back to padded id.
            if (this.order && this.order.order_number) return this.order.order_number;
            const id = this.order ? (this.order.order_id || this.order.id) : this.orderId;
            return id ? '#' + String(id).padStart(5, '0') : '';
        },
        // Currency symbol snapshotted on the order; fall back to the app currency.
        cur() {
            return (this.order && this.order.currency) ? this.order.currency : this.$currency;
        },
        // Chat button: admin can chat the order's customer; delivery boy only while
        // the order's delivery-boy↔customer chat is active.
        chatVisible() {
            if (!this.order) return false;
            if (this.isDeliveryBoy) return !!this.order.is_delivery_boy_chat_visible;
            return !!this.order.user_id && this.$can('chat');
        },
        primaryColor() { return window.adminThemeColor || '#435ebe'; },
        // role_id 3 = Delivery Boy. Checked alongside the mode prop so a rider who
        // reaches this slider from a notification is covered too.
        isDeliveryBoy() { return this.mode === 'delivery_boy' || Number(Auth.user?.role_id) === 3; },
        // Deep links out of the slider — never for delivery boys, and only where the
        // destination route would actually let the viewer in.
        // Quick orders: the assignment window closes at delivered/cancelled/returned,
        // but the rider must still be shown after that.
        canAssignQuickDboy() {
            return !this.isDeliveryBoy && this.canUpdate && !this.isEcommerce && !this.isPickup
                && [9, 10, 11, 5].includes(Number(this.order?.active_status));
        },
        quickDeliveryBoyName() {
            if (!this.order || !(Number(this.order.delivery_boy_id) > 0)) return '';
            return this.getDisplayName(this.order.delivery_boy_name_translation || this.order.delivery_boy_name) || '';
        },
        // Admin↔delivery-boy thread — riders don't chat with themselves.
        canChatDeliveryBoy() { return !this.isDeliveryBoy && this.$can('chat'); },
        canOpenCustomer() { return !this.isDeliveryBoy && this.$can('order_list'); },
        // Home-delivery and self-pickup orders carry separate update permissions.
        canUpdate() { return this.$can(this.isPickup ? 'self_pickup_order_update' : 'order_update'); },
        canOpenProduct() { return !this.isDeliveryBoy && this.$can('product_list'); },
        // Contacting the customer is allowed for delivery boys too — only the customer
        // detail page is off-limits to them. Demo mode still blocks it: the number and
        // address are masked there, and a live tel:/mailto: would hand out the real value.
        canContact() { return this.$isDemo != 1; },
        isEcommerce() { return !!(this.order && this.order.channel === 'ecommerce'); },
        isPickup() { return !!(this.order && this.order.delivery_type === 'pickup'); },
        // Item-wise management is an ecommerce DELIVERY thing. A pickup order — on either
        // channel — is one store, one status, one cancel: handled like a quick order.
        isItemWise() { return this.isEcommerce && !this.isPickup; },
        // --- multi-select -------------------------------------------------
        // Terminal items are out: nothing left to update, assign or cancel.
        selectableItems() {
            if (this.isDeliveryBoy || !this.canUpdate || !this.isItemWise) return [];
            return (this.order_items || []).filter(i => ![6, 7, 8].includes(Number(i.active_status)));
        },
        selectedItems() {
            return (this.order_items || []).filter(i => this.selectedItemIds.includes(i.id));
        },
        allSelected() {
            return this.selectableItems.length > 0
                && this.selectableItems.every(i => this.selectedItemIds.includes(i.id));
        },
        // One step forward, exactly like a single item's "Mark as" button. Only offered
        // when every selected item sits on the same status — a mixed selection has no
        // single next step.
        bulkNextStatus() {
            if (!this.selectedItems.length) return null;
            const first = Number(this.selectedItems[0].active_status);
            if (this.selectedItems.some(i => Number(i.active_status) !== first)) return null;
            return this.nextStatusFor(first);
        },
        // Assignment needs items with no rider and no courier tracking yet, and paid.
        canBulkAssignDboy() {
            return this.selectedItems.length > 0 && this.selectedItems.every(i =>
                Number(i.delivery_boy_id) === 0 && !i.tracking_id && Number(i.active_status) !== 1);
        },
        // Courier needs the same items assignment needs: unassigned, untracked, paid.
        canBulkTrack() { return this.canBulkAssignDboy; },
        canBulkCancel() {
            return this.selectedItems.length > 0 && this.selectedItems.every(i => this.canCancelItem(i));
        },
        storeMapUrl() {
            const si = this.order && this.order.store_info;
            if (!si || !si.latitude || !si.longitude) return null;
            return `https://www.google.com/maps?q=${si.latitude},${si.longitude}`;
        },
        // Quick orders cancel order-wise: the reason is written to every item, so
        // the first item that carries one represents the whole order.
        quickCancellationReason() {
            const it = (this.order_items || []).find(i => i.cancellation_reason);
            return it ? it.cancellation_reason : '';
        },
        navIndex() {
            const oid = this.orderId, iid = this.orderItemId ?? null;
            return this.navList.findIndex(e => String(e.id) === String(oid) && (e.itemId ?? null) == iid);
        },
        hasPrev() { return this.navIndex > 0; },
        hasNext() { return this.navIndex >= 0 && this.navIndex < this.navList.length - 1; },
        storeName() {
            if (!this.order) return '';
            return this.getDisplayName(this.order.store_name_translation) || this.order.store_name || '';
        },
        mapUrl() {
            if (!this.order) return null;
            const a = this.order.address || {};
            const lat = a.latitude;
            const lng = a.longitude;
            if (!lat || !lng) return null;
            return `https://www.google.com/maps?q=${lat},${lng}`;
        },
        timelineDesc() { return [...this.timeline]; },
        // Exclude cancelled/returned from the forward status flow.
        updatableStatuses() { return this.statuses.filter(s => ![7, 8].includes(Number(s.id))); },
        // The single next status for the whole (quick) order.
        nextOrderStatus() { return this.order ? this.nextStatusFor(this.order.active_status) : null; },
        // Admin can cancel until the order is delivered / already cancelled / returned.
        canCancelOrder() {
            return !this.isDeliveryBoy && this.order && ![6, 7, 8].includes(Number(this.order.active_status));
        },
        canShowLiveTracking() {
            if (!this.order || this.isPickup) return false;
            const OUT_FOR_DELIVERY = 5;
            // Quick orders: order-level delivery boy + order status must be out for delivery
            if (!this.isEcommerce) {
                return Number(this.order.delivery_boy_id) > 0 && Number(this.order.active_status) === OUT_FOR_DELIVERY;
            }
            // Ecommerce: any item assigned to a delivery boy and currently out for delivery
            return this.order_items.some(it => Number(it.delivery_boy_id) > 0 && Number(it.active_status) === OUT_FOR_DELIVERY);
        },
        storeCoords() {
            if (!this.order) return null;
            const lat = parseFloat(this.order.store_latitude);
            const lng = parseFloat(this.order.store_longitude);
            return (lat && lng) ? [lat, lng] : null;
        },
        addressCoords() {
            if (!this.order) return null;
            const a = this.order.address || {};
            const lat = parseFloat(a.latitude);
            const lng = parseFloat(a.longitude);
            return (lat && lng) ? [lat, lng] : null;
        },
        surgeCharges() { return Array.isArray(this.order && this.order.surge_charges) ? this.order.surge_charges : []; },
        additionalCharges() { return Array.isArray(this.order && this.order.additional_charges) ? this.order.additional_charges : []; },
        paymentStatusLabel() {
            const m = { success: __('paid'), failed: __('failed'), pending: __('pending') };
            return m[this.payment_status] || this.payment_status;
        },
        paymentBadgeClass() {
            if (this.payment_status === 'success') return 'bg-success';
            if (this.payment_status === 'failed') return 'bg-danger';
            return 'bg-secondary';
        },
    },
    watch: {
        modelValue(v) {
            this.show = v;
            if (v && this.orderId) this.getOrder();
        },
        // Prev/next navigation swaps orderId (+ orderItemId) while open -> reload.
        orderId() {
            this.clearSelection();
            if (this.show && this.orderId) this.getOrder();
        },
        // Lock the background (datatable) from scrolling while the slider is open.
        show(v) {
            document.body.style.overflow = v ? 'hidden' : '';
        },
    },
    beforeUnmount() {
        document.body.style.overflow = '';
        this.destroyLiveTrackMap();
    },
    methods: {
        sumBreakdown(rows) {
            return Number(((rows || []).reduce((t, r) => t + Number(r.amount || 0), 0)).toFixed(2));
        },
        itemTaxTotal(item) {
            return this.sumBreakdown(item && item.tax_breakdown);
        },
        // "CGST + SGST 18% · ₹4.27" — for a delivery / surge / additional charge.
        chargeTaxHint(charge) {
            if (!charge || !charge.tax_name || !Number(charge.tax_amount)) return '';
            const rate = Number(charge.tax_rate || 0);
            return `${charge.tax_name}${rate ? ' ' + rate + '%' : ''} · ${this.cur}${Number(charge.tax_amount).toFixed(2)}`;
        },
        // "CGST 9% ₹9.15 · SGST 9% ₹9.16" — for an item or the whole order.
        breakdownHint(rows) {
            if (!rows || !rows.length) return '';
            return rows
                .map(r => `${r.name} ${Number(r.rate || 0)}% ${this.cur}${Number(r.amount || 0).toFixed(2)}`)
                .join(' · ');
        },

        // Open (find-or-create) the order's chat and jump to the chat screen.
        // Admin → order_admin (admin↔customer); delivery boy → order-scoped
        // delivery_boy↔customer thread.
        chatWithCustomer() {
            if (!this.order || this.chatStarting) return;
            this.chatStarting = true;
            const orderId = this.order.order_id || this.order.id;
            const base = this.isDeliveryBoy ? this.$deliveryBoyApiUrl : this.$apiUrl;
            const payload = { order_id: orderId };
            if (this.isDeliveryBoy && this.orderItemId) payload.order_item_id = this.orderItemId;
            axios.post(base + '/chat/start_order', payload).then(res => {
                if (res.data.status === 1 && res.data.data && res.data.data.id) {
                    const routeName = this.isDeliveryBoy ? 'DeliveryBoyChat' : 'Chat';
                    this.$router.push({ name: routeName, query: { open: res.data.data.id } });
                } else {
                    this.showError(res.data.message || __('something_went_wrong'));
                }
            }).catch(err => {
                this.showError(err.response?.data?.message || __('something_went_wrong'));
            }).finally(() => { this.chatStarting = false; });
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
        // What the customer actually paid at placement: subtotal + all charges − promo.
        // Derived from immutable snapshot fields so it stays the original amount even
        // after a cancel/return. Works for an order (quick) or an order item (ecom).
        totalPaid(o) {
            if (!o) return '0.00';
            const num = v => Number(v || 0);
            const parseList = v => {
                if (Array.isArray(v)) return v;
                if (typeof v === 'string') { try { return JSON.parse(v) || []; } catch (e) { return []; } }
                return [];
            };
            const sub = num(o.sub_total != null ? o.sub_total : o.total);
            const delivery = num(o.delivery_charge);
            const addl = parseList(o.additional_charges).reduce((s, c) => s + num(c.amount != null ? c.amount : c.charge), 0);
            const surge = parseList(o.surge_charges).reduce((s, c) => s + num(c.charge != null ? c.charge : c.amount), 0);
            const promo = num(o.promo_discount);
            return Math.max(0, sub + delivery + addl + surge - promo).toFixed(2);
        },
        toggleLiveTracking() {
            this.liveTrackShow = !this.liveTrackShow;
            if (this.liveTrackShow) {
                this.loadMapSettings().then(() => this.$nextTick(() => this.initLiveTrackMap()));
            } else {
                this.destroyLiveTrackMap();
            }
        },
        /* A date can still be promised while the item is on its way; once it is
           delivered, cancelled or returned there is nothing left to promise. */
        canEditEstimate(item) {
            return ![6, 7, 8].includes(Number(item.active_status));
        },
        formatEtaDate(d) {
            if (!d) return '—';
            const dt = new Date(d);
            return isNaN(dt) ? d : dt.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
        },
        saveDeliveryEstimate(item) {
            const date = this.etaDraft[item.id] || item.estimated_delivery_date;
            if (!date) return;
            this.etaBusy = { ...this.etaBusy, [item.id]: true };
            axios.post(this.$apiUrl + '/orders/update_item_delivery_estimate', {
                order_item_id: item.id,
                estimated_delivery_date: date,
            }).then(res => {
                if (res.data.status === 1) {
                    item.estimated_delivery_date = date;
                    this.showMessage('success', res.data.message);
                } else {
                    this.showError(res.data.message || __('something_went_wrong'));
                }
            }).catch(err => this.showError(err.response?.data?.message || __('something_went_wrong')))
              .finally(() => { this.etaBusy = { ...this.etaBusy, [item.id]: false }; });
        },
        /** Which map to draw, and the key it needs. Read once, when the map first opens. */
        async loadMapSettings() {
            if (this._mapSettingsLoaded) return;
            try {
                const res = await axios.get(this.$apiUrl + '/store_settings');
                const rows = res.data?.data?.store_settings || [];
                const get = v => (rows.find(r => r.variable === v) || {}).value || '';
                this.mapProvider = get('map_provider') === 'google' ? 'google' : 'osm';
                this.googleMapKey = get('googleMapApiKey');
            } catch {
                this.mapProvider = 'osm';
            }
            this._mapSettingsLoaded = true;
        },
        /** Google when the panel is configured for it, Leaflet otherwise — the same
            choice every other map in the panel makes. */
        initLiveTrackMap() {
            if (this.mapProvider === 'google') {
                this.initGoogleLiveTrackMap();
            } else {
                this.initLeafletLiveTrackMap();
            }
        },
        /** Store, delivery address and (once it reports) the delivery boy. */
        liveTrackPoints() {
            const points = [];
            if (this.storeCoords) points.push({ coords: this.storeCoords, svg: LIVE_SVG.store, label: __('store') });
            if (this.addressCoords) points.push({ coords: this.addressCoords, svg: LIVE_SVG.home, label: __('delivery_address') });
            return points;
        },
        initLeafletLiveTrackMap() {
            if (this._liveMap) return;
            const el = this.$refs.liveMap;
            if (!el) return;

            this._liveDbIcon = L.divIcon({ html: LIVE_SVG.boy, iconSize: [26, 26], iconAnchor: [13, 13], className: '' });

            const map = L.map(el);
            L.tileLayer(osmTileUrl(), OSM_TILE_OPTIONS).addTo(map);

            const marks = this.liveTrackPoints();
            marks.forEach((m, i) => {
                const icon = L.divIcon({
                    html: m.svg, iconSize: [26, 26],
                    iconAnchor: i === 1 ? [13, 24] : [13, 13], className: '',
                });
                L.marker(m.coords, { icon }).addTo(map).bindPopup(m.label);
            });

            const points = marks.map(m => m.coords);
            if (points.length === 2) {
                this._liveStraightLine = L.polyline(points, {
                    color: '#435ebe', weight: 2, dashArray: '6 5', opacity: 0.7,
                }).addTo(map);
                this.drawOsmRoute(points[0], points[1]);
            }

            this._liveMap = map;

            if (points.length >= 2) {
                map.fitBounds(L.latLngBounds(points), { padding: [40, 40] });
            } else if (points.length === 1) {
                map.setView(points[0], 14);
            } else {
                map.setView([20, 0], 2);
            }

            this.startLivePolling();
        },
        async initGoogleLiveTrackMap() {
            if (this._gLiveMap) return;
            const el = this.$refs.liveMap;
            if (!el) return;
            try {
                await loadGoogleMaps(this.googleMapKey, []);
            } catch (e) {
                // No key, or the script refused to load — fall back rather than show nothing.
                this.mapProvider = 'osm';
                this.initLeafletLiveTrackMap();
                return;
            }

            const map = new google.maps.Map(el, {
                center: { lat: 20, lng: 0 }, zoom: 2,
                streetViewControl: false, mapTypeControl: false, fullscreenControl: true,
            });

            const marks = this.liveTrackPoints();
            const bounds = new google.maps.LatLngBounds();
            marks.forEach((m, i) => {
                const pos = { lat: m.coords[0], lng: m.coords[1] };
                new google.maps.Marker({
                    map, position: pos, title: m.label,
                    icon: {
                        url: svgDataUrl(m.svg),
                        scaledSize: new google.maps.Size(26, 26),
                        anchor: new google.maps.Point(13, i === 1 ? 24 : 13),
                    },
                });
                bounds.extend(pos);
            });

            if (marks.length === 2) {
                new google.maps.Polyline({
                    map,
                    path: marks.map(m => ({ lat: m.coords[0], lng: m.coords[1] })),
                    strokeOpacity: 0,
                    // Google has no dashArray; a repeated dash symbol is the equivalent.
                    icons: [{
                        icon: { path: 'M 0,-1 0,1', strokeOpacity: 0.7, strokeColor: '#435ebe', scale: 2 },
                        offset: '0', repeat: '11px',
                    }],
                });
            }

            this._gLiveMap = map;

            if (marks.length >= 2) {
                map.fitBounds(bounds, 40);
            } else if (marks.length === 1) {
                map.setCenter({ lat: marks[0].coords[0], lng: marks[0].coords[1] });
                map.setZoom(14);
            }

            this.startLivePolling();
        },

        drawOsmRoute(from, to) {
            if (!this._liveMap || !from || !to) return;

            const now = Date.now();
            if (this._lastRouteFrom
                && this.metresBetween(this._lastRouteFrom, from) < 50
                && now - (this._lastRouteAt || 0) < 60000) {
                return;
            }
            this._lastRouteFrom = from;
            this._lastRouteAt = now;

            const url = 'https://router.project-osrm.org/route/v1/driving/'
                + `${from[1]},${from[0]};${to[1]},${to[0]}?overview=full&geometries=geojson`;

            fetch(url)
                .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
                .then(data => {
                    const coords = data?.routes?.[0]?.geometry?.coordinates;
                    if (!Array.isArray(coords) || !coords.length || !this._liveMap) return;

                    // GeoJSON is [lng, lat]; Leaflet wants [lat, lng].
                    const line = coords.map(c => [c[1], c[0]]);
                    if (this._liveRouteLine) this._liveMap.removeLayer(this._liveRouteLine);
                    if (this._liveStraightLine) {
                        this._liveMap.removeLayer(this._liveStraightLine);
                        this._liveStraightLine = null;
                    }
                    this._liveRouteLine = L.polyline(line, {
                        color: '#435ebe', weight: 4, opacity: 0.85,
                    }).addTo(this._liveMap);
                    this._liveMap.fitBounds(this._liveRouteLine.getBounds(), { padding: [40, 40] });
                })
                .catch(() => {
                    this._lastRouteFrom = null;
                    this._lastRouteAt = 0;
                });
        },
        /** Great-circle distance in metres; only used to decide whether to re-route. */
        metresBetween(a, b) {
            const toRad = d => (d * Math.PI) / 180;
            const dLat = toRad(b[0] - a[0]);
            const dLng = toRad(b[1] - a[1]);
            const h = Math.sin(dLat / 2) ** 2
                + Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLng / 2) ** 2;
            return 6371000 * 2 * Math.asin(Math.sqrt(h));
        },
        startLivePolling() {
            this.fetchLivePosition();
            this.liveTrackInterval = setInterval(() => this.fetchLivePosition(), 10000);
        },
        fetchLivePosition() {
            const orderId = this.order ? (this.order.order_id || this.order.id) : this.orderId;
            if (!orderId || (!this._liveMap && !this._gLiveMap)) return;
            axios.get(this.$apiUrl + '/orders/live_tracking', { params: { order_id: orderId } })
                .then(res => {
                    if (res.data.status !== 1 || !res.data.data) return;
                    const lat = parseFloat(res.data.data.latitude);
                    const lng = parseFloat(res.data.data.longitude);
                    if (isNaN(lat) || isNaN(lng)) return;

                    if (this._gLiveMap) {
                        const pos = { lat, lng };
                        if (this._gLiveDbMarker) {
                            this._gLiveDbMarker.setPosition(pos);
                        } else {
                            this._gLiveDbMarker = new google.maps.Marker({
                                map: this._gLiveMap, position: pos, title: __('delivery_boy'),
                                icon: {
                                    url: svgDataUrl(LIVE_SVG.boy),
                                    scaledSize: new google.maps.Size(26, 26),
                                    anchor: new google.maps.Point(13, 13),
                                },
                            });
                        }
                        return;
                    }

                    const pos = [lat, lng];
                    if (this._liveDbMarker) {
                        this._liveDbMarker.setLatLng(pos);
                    } else {
                        this._liveDbMarker = L.marker(pos, { icon: this._liveDbIcon })
                            .addTo(this._liveMap).bindPopup(__('delivery_boy'));
                    }

                    if (this.addressCoords) this.drawOsmRoute(pos, this.addressCoords);
                })
                .catch(() => {});
        },
        destroyLiveTrackMap() {
            if (this.liveTrackInterval) { clearInterval(this.liveTrackInterval); this.liveTrackInterval = null; }
            if (this._liveMap) { this._liveMap.remove(); this._liveMap = null; }
            this._liveRouteLine = null;
            this._liveStraightLine = null;
            this._lastRouteFrom = null;
            this._lastRouteAt = 0;
            if (this._gLiveDbMarker) { this._gLiveDbMarker.setMap(null); this._gLiveDbMarker = null; }
            if (this._gLiveMap) {
                this._gLiveMap = null;
                if (this.$refs.liveMap) this.$refs.liveMap.innerHTML = '';
            }
            this._liveDbMarker = null;
            this._liveDbIcon = null;
        },
        close() {
            this.destroyLiveTrackMap();
            this.liveTrackShow = false;
            this.show = false;
            this.$emit('update:modelValue', false);
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
        goPrev() { if (this.hasPrev) this.$emit('navigate', this.navList[this.navIndex - 1]); },
        goNext() { if (this.hasNext) this.$emit('navigate', this.navList[this.navIndex + 1]); },
        getOrder() {
            this.destroyLiveTrackMap();
            this.liveTrackShow = false;
            this.isLoading = true;
            this.order = null;
            const req = this.isDeliveryBoy
                ? axios.get(this.$deliveryBoyApiUrl + '/order_by_id', { params: { order_id: this.orderId } })
                : axios.get(this.$apiUrl + '/orders/view/' + this.orderId);
            req.then((res) => {
                this.isLoading = false;
                const d = res.data;
                if (d.status === 1) {
                    this.order = d.data.order;
                    this.order_items = d.data.order_items || [];
                    this.timeline = d.data.timeline || [];
                    this.deliveryBoys = d.data.deliveryBoys || [];
                    this.payment_status = d.data.payment_status || 'pending';
                    this.order_status_id = (this.order.active_status && this.order.active_status != 0) ? this.order.active_status : '';
                    this.delivery_boy_id = (this.order.delivery_boy_id && this.order.delivery_boy_id != 0) ? this.order.delivery_boy_id : 0;
                    // Delivery-boy ecommerce: keep only items assigned to the same boy as the clicked item.
                    if (this.isDeliveryBoy && this.orderItemId) {
                        const clicked = this.order_items.find(i => Number(i.id) === Number(this.orderItemId));
                        const boy = clicked ? (Number(clicked.delivery_boy_id) || 0) : 0;
                        if (boy) this.order_items = this.order_items.filter(i => Number(i.delivery_boy_id) === boy);
                    }
                    // A reload moves statuses on — keep only ids still selectable.
                    this.selectedItemIds = this.selectedItemIds.filter(
                        id => this.selectableItems.some(i => i.id === id));
                    // Seed per-item status + delivery-boy selects + tracking (ecommerce).
                    const st = {}, db = {}, busy = {}, tracking = {}, fulfillOpen = {};
                    this.order_items.forEach(it => {
                        st[it.id] = (it.active_status && it.active_status != 0) ? it.active_status : '';
                        db[it.id] = (it.delivery_boy_id && it.delivery_boy_id != 0) ? it.delivery_boy_id : 0;
                        busy[it.id] = false;
                        tracking[it.id] = {
                            courier_agency: it.courier_agency || '',
                            tracking_id:    it.tracking_id    || '',
                            tracking_url:   it.tracking_url   || '',
                        };
                        // Auto-open the panel that already has data.
                        fulfillOpen[it.id] = it.tracking_id ? 'courier' : (Number(it.delivery_boy_id) > 0 ? 'db' : null);
                    });
                    this.itemStatus          = st;
                    this.itemDboy            = db;
                    this.itemBusy            = busy;
                    this.itemTracking        = tracking;
                    this.itemFulfillmentOpen = fulfillOpen;
                    this.loadStatuses();
                } else {
                    this.showError(d.message);
                    this.close();
                }
            }).catch(() => {
                this.isLoading = false;
                this.showError(__('something_went_wrong'));
            });
        },
        loadStatuses() {
            const params = {};
            if (this.order && this.order.channel) params.channel = this.order.channel;
            if (this.order && this.order.delivery_type) params.delivery_type = this.order.delivery_type;
            const url = this.isDeliveryBoy ? this.$deliveryBoyApiUrl + '/order_statuses' : this.$apiUrl + '/order_statuses';
            axios.get(url, { params }).then((res) => {
                this.statuses = res.data.data || [];
            });
        },
        openMap() { if (this.mapUrl) window.open(this.mapUrl, '_blank'); },
        openStoreMap() { if (this.storeMapUrl) window.open(this.storeMapUrl, '_blank'); },
        statusLabel(id) {
            if (this.isPickup && Number(id) === 6) return this.__('collected');
            const s = this.statuses.find(x => Number(x.id) === Number(id));
            if (s) return this.getStatusDisplayName(s);
            const map = { 1: 'payment_pending', 2: 'received', 3: 'processed', 4: 'shipped', 5: 'outForDelivery', 6: 'delivered', 7: 'cancelled', 8: 'returned', 9: 'preparing', 10: 'ready_for_pickup', 11: 'picked_up' };
            return map[Number(id)] ? this.__(map[Number(id)]) : String(id ?? '');
        },
        getStatusDisplayName(status) {
            if (!status) return '';
            const sn = status.status_name;
            if (sn == null) return status.status || '';
            if (typeof sn === 'string') return sn.trim() || status.status || '';
            if (typeof sn === 'object') {
                const loc = window.appLocale || window.localStorage.getItem('lang') || 'en';
                const v = sn[loc] || Object.values(sn).find(x => x && String(x).trim() !== '');
                return v ? String(v).trim() : (status.status || '');
            }
            return status.status || '';
        },
        getDisplayName(name) {
            if (name == null) return '';
            if (typeof name === 'string') return name;
            if (typeof name === 'object' && !Array.isArray(name)) {
                const loc = window.appLocale || window.localStorage.getItem('lang') || 'en';
                const v = name[loc] || Object.values(name).find(x => x && String(x).trim() !== '');
                return v ? String(v).trim() : '';
            }
            return '';
        },
        getStatusBadgeClass(id) {
            const n = Number(id);
            if (n === 1) return 'bg-secondary';
            if (n === 2) return 'bg-primary';
            if (n === 3 || n === 9) return 'bg-info';
            if (n === 4 || n === 5 || n === 10 || n === 11) return 'bg-warning';
            if (n === 6) return 'bg-success';
            if (n === 7 || n === 8) return 'bg-danger';
            return 'bg-secondary';
        },
        // Next status (after the current one) in the channel's forward flow, or null.
        nextStatusFor(currentId) {
            // Payment pending (1) is moved only by the payment, never by an admin advance.
            if (Number(currentId) === 1) return null;
            const flow = this.updatableStatuses;
            const idx = flow.findIndex(s => Number(s.id) === Number(currentId));
            if (idx === -1 || idx >= flow.length - 1) return null;
            return flow[idx + 1];
        },
        nextItemStatus(item) { return this.nextStatusFor(item.active_status); },
        canCancelItem(item) {
            return !this.isDeliveryBoy && ![6, 7, 8].includes(Number(item.active_status));
        },
        advanceOrder() {
            if (!this.nextOrderStatus) return;
            this.order_status_id = this.nextOrderStatus.id;
            this.updateStatus();
        },
        advanceItem(item) {
            const next = this.nextItemStatus(item);
            if (!next) return;
            this.itemStatus[item.id] = next.id;
            this.updateItemStatus(item);
        },
        // --- multi-select actions ----------------------------------------
        isSelectable(item) {
            return this.selectableItems.some(i => i.id === item.id);
        },
        toggleAllItems(e) {
            this.selectedItemIds = e.target.checked ? this.selectableItems.map(i => i.id) : [];
        },
        clearSelection() {
            this.selectedItemIds = [];
            this.bulkDboy = 0;
            this.bulkTracking = { courier_agency: '', tracking_id: '', tracking_url: '' };
        },
        // After any bulk call: reload and drop the selection (statuses have moved on).
        afterBulk(res) {
            this.bulkBusy = '';
            if (res.data.status === 1) {
                this.showMessage('success', res.data.message);
                this.clearSelection();
                this.getOrder();
                this.$emit('updated');
            } else this.showError(res.data.message);
        },
        bulkUpdateStatus() {
            const next = this.bulkNextStatus;
            if (!next) return;
            const sid = next.id;
            this.$swal.fire({
                title: __('are_you_sure'), icon: 'warning', showCancelButton: true,
                confirmButtonText: __('yes_sure'), cancelButtonText: __('cancel'),
                confirmButtonColor: window.adminThemeColor || '#435ebe', cancelButtonColor: '#d33',
            }).then(r => {
                if (!r.value) return;
                this.bulkBusy = 'status';
                axios.post(this.$apiUrl + '/orders/update_items_status',
                    { ids: this.selectedItemIds.join(','), status_id: sid })
                    .then(res => this.afterBulk(res))
                    .catch(() => { this.bulkBusy = ''; this.showError(__('something_went_wrong')); });
            });
        },
        bulkAssignDeliveryBoy() {
            if (!Number(this.bulkDboy)) { this.showError(__('please_select_delivery_boy')); return; }
            this.bulkBusy = 'dboy';
            axios.post(this.$apiUrl + '/orders/assign_delivery_boy_to_items',
                { order_item_ids: this.selectedItemIds.join(','), delivery_boy_id: this.bulkDboy })
                .then(res => this.afterBulk(res))
                .catch(() => { this.bulkBusy = ''; this.showError(__('something_went_wrong')); });
        },
        bulkSaveTracking() {
            const t = this.bulkTracking;
            const agency = (t.courier_agency || '').trim();
            const tid    = (t.tracking_id || '').trim();
            const url    = (t.tracking_url || '').trim();
            if (!agency || !tid || !url) { this.showError(__('please_fill_all_tracking_fields')); return; }
            this.bulkBusy = 'tracking';
            axios.post(this.$apiUrl + '/orders/save_order_tracking_items', {
                order_item_ids: this.selectedItemIds.join(','),
                courier_agency: agency, tracking_id: tid, tracking_url: url,
            }).then(res => this.afterBulk(res))
                .catch(() => { this.bulkBusy = ''; this.showError(__('something_went_wrong')); });
        },
        bulkCancel() {
            this.$swal.fire({
                title: __('cancel_items'), text: __('cancel_items_confirm'), icon: 'warning',
                input: 'textarea',
                inputLabel: __('cancellation_reason'),
                inputPlaceholder: __('enter_cancellation_reason'),
                inputAttributes: { 'aria-label': __('cancellation_reason') },
                inputValidator: (v) => (!v || !v.trim()) ? __('cancellation_reason_is_required') : undefined,
                showCancelButton: true, confirmButtonText: __('yes_sure'), cancelButtonText: __('cancel'),
                confirmButtonColor: '#d33', cancelButtonColor: window.adminThemeColor || '#435ebe',
            }).then(r => {
                if (!r.value) return;
                this.bulkBusy = 'cancel';
                // Same reason stored on every selected item.
                axios.post(this.$apiUrl + '/orders/admin_cancel',
                    { order_item_ids: this.selectedItemIds.join(','), cancellation_reason: r.value })
                    .then(res => this.afterBulk(res))
                    .catch(() => { this.bulkBusy = ''; this.showError(__('something_went_wrong')); });
            });
        },
        cancelOrder() {
            this.$swal.fire({
                title: __('cancel_order'), text: __('cancel_order_confirm'), icon: 'warning',
                input: 'textarea',
                inputLabel: __('cancellation_reason'),
                inputPlaceholder: __('enter_cancellation_reason'),
                inputAttributes: { 'aria-label': __('cancellation_reason') },
                inputValidator: (v) => (!v || !v.trim()) ? __('cancellation_reason_is_required') : undefined,
                showCancelButton: true, confirmButtonText: __('yes_sure'), cancelButtonText: __('cancel'),
                confirmButtonColor: '#d33', cancelButtonColor: window.adminThemeColor || '#435ebe',
            }).then(r => {
                if (!r.value) return;
                this.isLoadingUstatus = true;
                axios.post(this.$apiUrl + '/orders/admin_cancel', { order_id: this.orderId, cancellation_reason: r.value })
                    .then((res) => {
                        this.isLoadingUstatus = false;
                        if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                        else this.showError(res.data.message);
                    }).catch(() => { this.isLoadingUstatus = false; this.showError(__('something_went_wrong')); });
            });
        },
        cancelItem(item) {
            this.$swal.fire({
                title: __('cancel_item'), text: __('cancel_item_confirm'), icon: 'warning',
                input: 'textarea',
                inputLabel: __('cancellation_reason'),
                inputPlaceholder: __('enter_cancellation_reason'),
                inputAttributes: { 'aria-label': __('cancellation_reason') },
                inputValidator: (v) => (!v || !v.trim()) ? __('cancellation_reason_is_required') : undefined,
                showCancelButton: true, confirmButtonText: __('yes_sure'), cancelButtonText: __('cancel'),
                confirmButtonColor: '#d33', cancelButtonColor: window.adminThemeColor || '#435ebe',
            }).then(r => {
                if (!r.value) return;
                this.itemBusy[item.id] = true;
                axios.post(this.$apiUrl + '/orders/admin_cancel', { order_item_id: item.id, cancellation_reason: r.value })
                    .then((res) => {
                        this.itemBusy[item.id] = false;
                        if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                        else this.showError(res.data.message);
                    }).catch(() => { this.itemBusy[item.id] = false; this.showError(__('something_went_wrong')); });
            });
        },
        updateStatus() {
            // Delivery boy delivering an OTP-protected order must enter the customer's OTP.
            const needsOtp = this.isDeliveryBoy && Number(this.order_status_id) === 6 && Number(this.order.otp) > 0;
            const opts = {
                title: __('are_you_sure'), icon: 'warning', showCancelButton: true,
                confirmButtonText: __('yes_sure'), cancelButtonText: __('cancel'),
                confirmButtonColor: window.adminThemeColor || '#435ebe', cancelButtonColor: '#d33',
            };
            if (needsOtp) {
                opts.input = 'number';
                opts.inputLabel = __('enter_delivery_otp');
                opts.inputPlaceholder = __('otp');
                opts.inputValidator = (v) => (!v || !String(v).trim()) ? __('otp_is_required') : undefined;
            }
            this.$swal.fire(opts).then(r => {
                if (!r.value) return;
                this.isLoadingUstatus = true;
                const url = this.isDeliveryBoy ? this.$deliveryBoyApiUrl + '/update_status' : this.$apiUrl + '/orders/update_status';
                const payload = { order_id: this.orderId, status_id: this.order_status_id };
                if (needsOtp) payload.otp = r.value;
                axios.post(url, payload)
                    .then((res) => {
                        this.isLoadingUstatus = false;
                        if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                        else this.showError(res.data.message);
                    }).catch(() => { this.isLoadingUstatus = false; this.showError(__('something_went_wrong')); });
            });
        },
        assignDeliveryBoy() {
            this.isLoadingDboy = true;
            axios.post(this.$apiUrl + '/orders/assign_delivery_boy', { order_id: this.orderId, delivery_boy_id: this.delivery_boy_id })
                .then((res) => {
                    this.isLoadingDboy = false;
                    if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                    else this.showError(res.data.message);
                }).catch(() => { this.isLoadingDboy = false; this.showError(__('something_went_wrong')); });
        },
        // Sum a per-item charge JSON list by its amount/charge key.
        sumCharges(list, key) {
            if (!Array.isArray(list)) return 0;
            return Number(list.reduce((s, c) => s + (Number(c[key]) || 0), 0).toFixed(2));
        },
        // Ecommerce: update one item's status.
        updateItemStatus(item) {
            const sid = this.itemStatus[item.id];
            if (sid === '' || sid == null) { this.showError(__('select_order_status')); return; }
            // Delivery boy delivering an OTP-protected item must enter the customer's OTP.
            const needsOtp = this.isDeliveryBoy && Number(sid) === 6 && Number(item.otp) > 0;
            const opts = {
                title: __('are_you_sure'), icon: 'warning', showCancelButton: true,
                confirmButtonText: __('yes_sure'), cancelButtonText: __('cancel'),
                confirmButtonColor: window.adminThemeColor || '#435ebe', cancelButtonColor: '#d33',
            };
            if (needsOtp) {
                opts.input = 'number';
                opts.inputLabel = __('enter_delivery_otp');
                opts.inputPlaceholder = __('otp');
                opts.inputValidator = (v) => (!v || !String(v).trim()) ? __('otp_is_required') : undefined;
            }
            this.$swal.fire(opts).then(r => {
                if (!r.value) return;
                this.itemBusy[item.id] = true;
                const otpParam = needsOtp ? { otp: r.value } : {};
                const req = this.isDeliveryBoy
                    ? axios.post(this.$deliveryBoyApiUrl + '/update_item_status', { order_item_id: item.id, status_id: sid, ...otpParam })
                    : axios.post(this.$apiUrl + '/orders/update_items_status', { ids: String(item.id), status_id: sid });
                req.then((res) => {
                        this.itemBusy[item.id] = false;
                        if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                        else this.showError(res.data.message);
                    }).catch(() => { this.itemBusy[item.id] = false; this.showError(__('something_went_wrong')); });
            });
        },
        // Ecommerce: assign / unassign a delivery boy to one item.
        assignItemDeliveryBoy(item) {
            this.itemBusy[item.id] = true;
            axios.post(this.$apiUrl + '/orders/assign_delivery_boy_to_item', { order_item_id: item.id, delivery_boy_id: this.itemDboy[item.id] })
                .then((res) => {
                    this.itemBusy[item.id] = false;
                    if (res.data.status === 1) {
                        // Clear tracking display locally — backend already cleared it.
                        if (this.itemTracking[item.id]) {
                            this.itemTracking[item.id].courier_agency = '';
                            this.itemTracking[item.id].tracking_id    = '';
                            this.itemTracking[item.id].tracking_url   = '';
                        }
                        this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated');
                    } else this.showError(res.data.message);
                }).catch(() => { this.itemBusy[item.id] = false; this.showError(__('something_went_wrong')); });
        },
        toggleFulfillment(itemId, panel) {
            this.itemFulfillmentOpen[itemId] = this.itemFulfillmentOpen[itemId] === panel ? null : panel;
        },
        saveItemTracking(item) {
            const t = this.itemTracking[item.id] || {};
            const agency = (t.courier_agency || '').trim();
            const tid    = (t.tracking_id    || '').trim();
            const url    = (t.tracking_url   || '').trim();
            if (agency || tid || url) {
                if (!agency || !tid || !url) {
                    this.showError(__('please_fill_all_tracking_fields'));
                    return;
                }
                try { new URL(url); } catch (_) {
                    this.showError(__('tracking_url_must_be_valid_url'));
                    return;
                }
            }
            this.itemBusy[item.id] = true;
            axios.post(this.$apiUrl + '/orders/save_order_tracking', {
                order_item_id:  item.id,
                courier_agency: t.courier_agency || '',
                tracking_id:    t.tracking_id    || '',
                tracking_url:   t.tracking_url   || '',
            }).then((res) => {
                this.itemBusy[item.id] = false;
                if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                else this.showError(res.data.message);
            }).catch(() => { this.itemBusy[item.id] = false; this.showError(__('something_went_wrong')); });
        },
        clearItemTracking(item) {
            this.itemBusy[item.id] = true;
            axios.post(this.$apiUrl + '/orders/save_order_tracking', {
                order_item_id: item.id, courier_agency: '', tracking_id: '', tracking_url: '',
            }).then((res) => {
                this.itemBusy[item.id] = false;
                if (res.data.status === 1) { this.showMessage('success', res.data.message); this.getOrder(); this.$emit('updated'); }
                else this.showError(res.data.message);
            }).catch(() => { this.itemBusy[item.id] = false; this.showError(__('something_went_wrong')); });
        },
        // Ecommerce: download one item's invoice.
        downloadItemInvoice(item) {
            this.itemInvoiceBusy[item.id] = true;
            axios({ url: this.$apiUrl + '/orders/item_invoice_download', method: 'post', responseType: 'blob', data: { order_item_id: item.id } })
                .then((res) => {
                    const url = window.URL.createObjectURL(new Blob([res.data]));
                    const a = document.createElement('a');
                    a.href = url; a.setAttribute('download', 'Invoice-' + this.orderId + '-' + item.id + '.pdf');
                    document.body.appendChild(a); a.click(); a.remove();
                    this.itemInvoiceBusy[item.id] = false;
                }).catch(() => { this.itemInvoiceBusy[item.id] = false; this.showError(__('something_went_wrong')); });
        },

        // No size is sent: the API prints on the paper set in Settings → Invoice.
        downloadReceipt(item = null) {
            if (item) this.itemReceiptBusy[item.id] = true;
            else this.isLoadingReceipt = true;
            const done = () => {
                if (item) this.itemReceiptBusy[item.id] = false;
                else this.isLoadingReceipt = false;
            };
            const data = item ? { order_item_id: item.id } : { order_id: this.orderId };

            const tab = this.framePrintFails ? window.open('', '_blank') : null;
            axios({ url: this.$apiUrl + '/orders/delivery_receipt_download', method: 'post', responseType: 'blob', data })
                .then((res) => {
                    const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
                    if (tab) this.printInTab(tab, url);
                    else this.printPdf(url);
                    done();
                })
                .catch(() => {
                    if (tab) tab.close();
                    done();
                    this.showError(__('something_went_wrong'));
                });
        },

        printPdf(url) {
            const frame = document.createElement('iframe');
            frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:1000px;border:0;visibility:hidden;';
            frame.src = url;
            frame.onload = () => {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch (e) {
                    window.open(url, '_blank');
                }
                // Late cleanup: revoking while the dialog is open cancels the job.
                setTimeout(() => { frame.remove(); window.URL.revokeObjectURL(url); }, 60000);
            };
            document.body.appendChild(frame);
        },
        // The PDF opens in its own tab and prints itself once the viewer has painted.
        printInTab(tab, url) {
            tab.location.href = url;
            const tryPrint = () => {
                try {
                    tab.focus();
                    tab.print();
                } catch (e) {
                   
                }
            };
            // The viewer needs a moment before it has anything to print.
            setTimeout(tryPrint, 900);
            setTimeout(() => window.URL.revokeObjectURL(url), 60000);
        },
        /**
         * Open the invoice PDF in a tab instead of saving it. The tab is opened in the
         * click itself — opening it after the request returns is a popup, and blocked.
         */
        viewInvoice(item = null) {
            if (item) this.itemInvoiceViewBusy[item.id] = true;
            else this.isViewingInvoice = true;
            const done = () => {
                if (item) this.itemInvoiceViewBusy[item.id] = false;
                else this.isViewingInvoice = false;
            };

            const tab = window.open('', '_blank');
            const url = item ? '/orders/item_invoice_download' : '/orders/invoice_download';
            const data = item ? { order_item_id: item.id } : { order_id: this.orderId };

            axios({ url: this.$apiUrl + url, method: 'post', responseType: 'blob', data })
                .then((res) => {
                    const blobUrl = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
                    if (tab) tab.location.href = blobUrl;
                    else window.open(blobUrl, '_blank');
                    // Late revoke: the viewer still needs the blob while it renders.
                    setTimeout(() => window.URL.revokeObjectURL(blobUrl), 60000);
                    done();
                })
                .catch(() => {
                    if (tab) tab.close();
                    done();
                    this.showError(__('something_went_wrong'));
                });
        },
        downloadInvoice() {
            this.isLoadingInvoice = true;
            axios({ url: this.$apiUrl + '/orders/invoice_download', method: 'post', responseType: 'blob', data: { order_id: this.orderId } })
                .then((res) => {
                    const url = window.URL.createObjectURL(new Blob([res.data]));
                    const a = document.createElement('a');
                    a.href = url; a.setAttribute('download', 'Invoice-No:#' + this.orderId + '.pdf');
                    document.body.appendChild(a); a.click(); a.remove();
                    this.isLoadingInvoice = false;
                }).catch(() => { this.isLoadingInvoice = false; this.showError(__('something_went_wrong')); });
        },
    },
};
</script>

<style scoped>
.ods-backdrop { position: fixed; inset: 0; background: rgba(0, 0, 0, .4); z-index: 1050; }
.ods-panel {
    position: fixed; top: 0; inset-inline-end: 0; height: 100vh; width: 50vw;
    background: var(--app-card-bg); z-index: 1051; box-shadow: 0 0 24px rgba(0, 0, 0, .15);
    display: flex; flex-direction: column;
}
.ods-panel.wide { width: 74vw; }
@media (max-width: 991px) { .ods-panel, .ods-panel.wide { width: 96vw; } }
.ods-eitem-sum { background: var(--app-thead-bg); border: 1px solid var(--app-card-border); border-radius: .5rem; padding: .5rem .75rem; }
.ods-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.5rem; border-bottom: 1px solid var(--app-card-border);
}
/* Phones: icon-only tools (labels hidden above), tighter padding, title never wraps. */
@media (max-width: 575.98px) {
    .ods-header { padding: .65rem .75rem; gap: .5rem; }
    .ods-header > div { gap: .35rem !important; min-width: 0; }
    .ods-header h5 { font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ods-header .badge { font-size: .62rem; }
    .ods-header .btn { padding: .3rem .45rem; }
    .ods-header .btn svg { margin: 0; }
}
.ods-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; background: var(--app-thead-bg); }
.ods-card { background: var(--app-card-bg); border: 1px solid var(--app-card-border); border-radius: .7rem; padding: 1rem 1.1rem; margin-bottom: 1rem; }
.ods-prescription-btn { font-size: .72rem; padding: .12rem .5rem; display: inline-flex; align-items: center; }
/* Titles now lead with a lucide icon; the global `svg { display: block }` would
   otherwise drop it onto its own line. Flex keeps icon and label on one row. */
.ods-card-title { font-weight: 700; font-size: .9rem; margin-bottom: .7rem; color: var(--app-ink); display: flex; align-items: center; gap: .4rem; }
.ods-card-title svg { flex-shrink: 0; }

/* The accordion header is already flex, but its inner <span> wraps icon+label —
   and the global `svg { display: block }` stacks them. Flex the span too. */
.ods-accordion-header > span {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
}
.ods-accordion-header svg { flex-shrink: 0; }

/* Section icons sized here rather than via :size, so one rule governs them all. */
.ods-ic {
    width: 18px !important;
    height: 18px !important;
    stroke-width: 2px;
}
/* Section icons carry a semantic colour instead of inheriting the title's ink. */
.ods-ic-user { color: #6366f1; }
.ods-ic-addr { color: #ef4444; }
.ods-ic-items { color: #8b5cf6; }
.ods-ic-status { color: var(--bs-primary); }
.ods-ic-cancel { color: #dc2626; }
.ods-cancel-reason { border-left: 3px solid #dc2626; }
.ods-cancel-reason-text { font-size: .82rem; color: var(--bs-body-color); white-space: pre-wrap; }
.ods-cancel-reason-inline {
    font-size: .74rem;
    color: #b91c1c;
    background: rgba(220, 38, 38, .08);
    border: 1px solid rgba(220, 38, 38, .2);
    border-radius: 6px;
    padding: 4px 8px;
    white-space: pre-wrap;
}
.ods-ic-boy { color: #16a34a; }
.ods-ic-bill { color: #0891b2; }
.ods-ic-time { color: #f59e0b; }
.ods-ic-track { color: #0ea5e9; }
/* The inline labels (delivery address, tracking, delivery boy) do the same. */
.ods-card-title > span,
.ods-card-title span > span { display: inline-flex; align-items: center; gap: .35rem; }
.ods-row { display: flex; justify-content: space-between; gap: 1rem; padding: .28rem 0; font-size: .85rem; color: var(--app-muted); }
.ods-row b { color: var(--app-ink); text-align: right; }

/* Clickable customer / product / contact values. Colour is inherited from the
   surrounding text — only the cursor and a slight fade mark them as clickable. */
.ods-link { cursor: pointer; color: inherit; text-decoration: none; }
.ods-link:hover { color: inherit; opacity: .7; }
.ods-total {
    display: flex; justify-content: space-between; align-items: center;
    background: var(--app-thead-bg); border-radius: .5rem; padding: .6rem .8rem; margin-top: .6rem; font-size: .95rem;
}
.ods-eitem { border: 1px solid var(--app-card-border); border-radius: .6rem; padding: .7rem; margin-bottom: .7rem; }
.ods-eitem.is-selected { border-color: var(--app-primary, #435ebe); background: rgba(67, 94, 190, .04); }
.ods-item-check { margin-top: .15rem; }
.ods-bulk-all { display: flex; align-items: center; gap: .3rem; }
.ods-bulk-all .form-check-input { margin: 0; }
.ods-bulk-bar {
    display: flex; flex-direction: column; gap: .5rem;
    border: 1px solid var(--app-primary, #435ebe); border-radius: .6rem;
    padding: .55rem .7rem; margin-bottom: .7rem; background: var(--app-thead-bg);
}
.ods-bulk-controls { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.ods-bulk-group { display: flex; gap: .35rem; align-items: center; min-width: 210px; flex: 1 1 210px; }
.ods-bulk-track { flex: 1 1 140px; min-width: 120px; }
.ods-bulk-group .form-select { min-width: 0; }
@media (max-width: 575px) {
    .ods-bulk-group { flex: 1 1 100%; }
}
.ods-fulfillment { border-top: 1px solid var(--app-card-border); padding-top: .5rem; }
.ods-accordion { border: 1px solid var(--app-card-border); border-radius: .45rem; margin-bottom: .35rem; }
.ods-accordion-header {
    display: flex; align-items: center; gap: .4rem; padding: .45rem .6rem;
    font-size: .78rem; font-weight: 600; color: var(--app-ink); cursor: pointer;
    background: var(--app-thead-bg); user-select: none;
    border-radius: .4rem;
}
.ods-accordion-header:hover { background: var(--app-hover); }
.ods-accordion-body { padding: .5rem .6rem; background: var(--app-card-bg); border-radius: 0 0 .4rem .4rem; }
.ods-accordion-header:has(+ .ods-accordion-body) { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.ods-eitem-charges { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; font-size: .72rem; color: var(--app-muted); }
.ods-eitem-charges span { background: var(--app-thead-bg); border-radius: .35rem; padding: .1rem .4rem; }
.ods-eitem-controls { margin-top: .55rem; }
.ods-status-ig { align-items: stretch; }
.ods-status-ig .form-select, .ods-status-ig .btn {
    height: 40px; min-height: 40px; padding-top: 0; padding-bottom: 0;
    display: inline-flex; align-items: center;
}
.ods-eitem-controls .input-group { align-items: stretch; }
.ods-eitem-controls .input-group .form-select,
.ods-eitem-controls .input-group .btn {
    height: 32px;
    min-height: 32px;
    padding-top: 0;
    padding-bottom: 0;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    font-size: .8rem;
}
.ods-eitem-tl { margin-top: .6rem; }
.ods-item { display: flex; align-items: center; gap: .7rem; padding: .55rem 0; border-bottom: 1px solid var(--app-thead-bg); }
.ods-item:last-of-type { border-bottom: 0; }
.ods-item-img { width: 42px; height: 42px; object-fit: cover; border-radius: .5rem; background: var(--app-hover); }
.badge-sm { font-size: .62rem; }
.ods-timeline { list-style: none; margin: 0; padding: 0 0 .35rem .4rem; }
/* Completed steps: primary at half opacity for the dot + connecting line. */
.ods-timeline li {
    position: relative;
    padding: 0 0 .9rem 1.2rem;
    border-left: 2px solid rgba(var(--bs-primary-rgb), .5);
}
/* An item's left border is the segment joining IT to the one below, so only the
   LAST row drops its rail — the current status sits mid-list now and must stay
   connected to the steps that follow it. */
.ods-timeline li.last { border-left-color: transparent; padding-bottom: .35rem; }
/* Not reached yet: faded, hollow dot, dashed rail. The current step's own rail is
   dashed too, because what it leads to has not happened. */
.ods-timeline li.pending { opacity: .55; }
.ods-timeline li.pending:not(.last),
.ods-timeline li.current:not(.last) { border-left: 2px dashed var(--bs-border-color, #dee2e6); }
.ods-tl-dot { position: absolute; left: -7px; top: 2px; width: 12px; height: 12px; border-radius: 50%; background: rgba(var(--bs-primary-rgb), .5); }
.ods-tl-dot.first { background: var(--bs-primary); box-shadow: 0 0 0 3px rgba(var(--bs-primary-rgb), .25); }
.ods-tl-dot.pending { background: transparent; border: 2px solid var(--bs-border-color, #dee2e6); box-shadow: none; }
.ods-tl-content { line-height: 1.25; }
/* Space between timeline card and its Download button. */
.ods-timeline + .btn { margin-top: .35rem; }
.ods-slide-enter-active, .ods-slide-leave-active { transition: transform .28s ease; }
/* Slide out through the panel's own edge — the opposite direction under RTL. */
.ods-slide-enter-from, .ods-slide-leave-to { transform: translateX(100%); }
body.rtl .ods-slide-enter-from, body.rtl .ods-slide-leave-to { transform: translateX(-100%); }
.ods-fade-enter-active, .ods-fade-leave-active { transition: opacity .28s ease; }
.ods-fade-enter-from, .ods-fade-leave-to { opacity: 0; }
.gap-2 { gap: .5rem; }
.ods-live-map { height: 320px; border-radius: .5rem; overflow: hidden; margin-top: .25rem; }

/* Tax hint icon — quiet until hovered, so it never competes with the figure. */
.ods-tax-i { display: inline-flex; align-items: center; color: #98a0af; cursor: help;
            vertical-align: -2px; margin-left: 3px; }
.ods-tax-i:hover { color: var(--bs-primary, #435ebe); }

/* Promised delivery date block inside an ecommerce item's summary. */
.ods-eta { border-top: 1px dashed #e2e6ee; padding-top: 8px; font-size: 12.5px; }
.ods-eta-k { color: #7a8397; }
</style>
