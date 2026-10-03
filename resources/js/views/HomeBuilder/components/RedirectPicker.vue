<template>
    <div class="hb-redirect">
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">{{ __('redirect_type') }}</label>
                <AppSelect class="form-select form-select-sm" v-model="config.redirect_type"
                    :options="redirectTypeOptions" :searchable="false" :allow-empty="false" />
            </div>
            <div class="col-md-8" v-if="config.redirect_type && config.redirect_type !== 'none'">
                <label class="form-label small text-muted mb-1">{{ __('redirect_target') }}</label>
                <AppSelect class="form-select form-select-sm" v-if="config.redirect_type === 'product'"
                    :model-value="config.redirect_id"
                    @update:model-value="config.redirect_id = $event"
                    :options="allProducts" :placeholder="__('select_product')" />
                <AppSelect v-else-if="config.redirect_type === 'category'"
                    :model-value="config.redirect_id"
                    @update:model-value="config.redirect_id = $event"
                    :options="allCategories" :placeholder="__('select_category')" />
                <AppSelect v-else-if="config.redirect_type === 'brand'"
                    :model-value="config.redirect_id"
                    @update:model-value="config.redirect_id = $event"
                    :options="allBrands" :placeholder="__('select_brand')" />
                <AppSelect v-else-if="config.redirect_type === 'page'"
                    :model-value="config.redirect_id"
                    @update:model-value="config.redirect_id = $event"
                    :options="allPages" :placeholder="__('select_page')" />
                <input v-else type="text" class="form-control form-control-sm" v-model="config.redirect_url"
                    placeholder="https://">
            </div>
        </div>
    </div>
</template>

<script>
import { redirectTypeOptions } from '../homeBuilderHelpers.js';

export default {
    name: 'RedirectPicker',
    props: {
        config: { type: Object, required: true },
        allProducts: { type: Array, default: () => [] },
        allCategories: { type: Array, default: () => [] },
        allBrands: { type: Array, default: () => [] },
        allPages: { type: Array, default: () => [] },
    },
    computed: {
        // Fixed option set — no search box needed.
        redirectTypeOptions() {
            return redirectTypeOptions();
        },
    },
};
</script>

<style scoped>
.hb-redirect {
    margin-top: .25rem;
}
.hb-lbl {
    font-size: .75rem;
    color: var(--app-muted);
    margin-bottom: .15rem;
    display: block;
}
</style>
