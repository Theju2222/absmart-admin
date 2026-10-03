<template>
    <div>
        <div class="page-heading">
            <div class="page-head">
                <h3 class="page-head-title">{{ isUpdate ? __('bulk_update') : __('bulk_upload') }}</h3>
                <button class="btn btn-outline-primary ms-auto d-inline-flex align-items-center gap-1"
                    @click="showInstructions = true">
                    <Info :size="16" /> {{ __('instructions') }}
                </button>
                <router-link to="/products" class="btn btn-outline-secondary ms-2 d-inline-flex align-items-center gap-1">
                    <ArrowLeft :size="16" /> {{ __('back') }}
                </router-link>
            </div>

            <!-- Full step-by-step walkthrough -->
            <b-modal v-model="showInstructions" :title="__('bulk_instructions_title')" size="lg" scrollable
                centered no-fade hide-footer>
                <ol class="ps-3 mb-0 d-flex flex-column gap-2">
                    <li><strong>{{ __('bulk_step_1_title') }}</strong><br>{{ __('bulk_step_1_desc') }}</li>
                    <li><strong>{{ __('bulk_step_2_title') }}</strong><br>{{ isUpdate ? __('bulk_step_2_desc_update') : __('bulk_step_2_desc_upload') }}</li>
                    <li v-if="!isUpdate"><strong>{{ __('bulk_step_3_title') }}</strong><br>{{ __('bulk_step_3_desc') }}</li>
                    <li><strong>{{ __('bulk_step_images_title') }}</strong><br>{{ __('bulk_step_images_desc') }}</li>
                    <li><strong>{{ __('bulk_step_pricing_title') }}</strong><br>{{ __('bulk_step_pricing_desc') }}</li>
                    <li v-if="isUpdate"><strong>{{ __('bulk_step_merge_title') }}</strong><br>{{ __('bulk_step_merge_desc') }}</li>
                    <li><strong>{{ __('bulk_step_upload_title') }}</strong><br>{{ __('bulk_step_upload_desc') }}</li>
                    <li><strong>{{ __('bulk_step_progress_title') }}</strong><br>{{ __('bulk_step_progress_desc') }}</li>
                </ol>
            </b-modal>

            <!-- Step 1: selection. The sheet's columns depend on this, so it comes first. -->
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">1. {{ __('select_category_and_stores') }}</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        {{ __('bulk_columns_depend_on_selection_hint') }}
                    </div>

                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>{{ __('sales_channel') }}</label>
                            <AppSelect class="form-select" v-model="salesChannel" :options="salesChannelOptions" :searchable="false" @update:model-value="onChannelChange" />
                        </div>

                        <div class="col-md-9 form-group">
                            <label>{{ __('category') }}</label>
                            <div class="d-flex flex-wrap gap-2">
                                <!-- Searchable: category lists get long. Clearing a level
                                     passes null so the child levels collapse. -->
                                <AppSelect v-for="(level, idx) in categoryLevels" :key="idx"
                                    class="form-select bulk-cat-select" :model-value="level.selectedId"
                                    :options="level.items" :placeholder="__('select_category')"
                                    @update:model-value="selectCategoryAt(idx, $event === '' ? null : Number($event))" />
                            </div>
                        </div>
                    </div>

                    <div class="form-group" v-if="stores.length">
                        <label>{{ __('stores') }} <small class="text-muted">({{ __('per_store_pricing_columns_will_be_added') }})</small></label>
                        <div class="d-flex flex-wrap gap-2">
                            <label v-for="s in stores" :key="s.id"
                                class="border rounded px-3 py-2 d-inline-flex align-items-center gap-2"
                                :class="{ 'border-primary bg-light': storeIds.includes(s.id) }" style="cursor:pointer">
                                <input type="checkbox" class="form-check-input mt-0" :value="s.id" v-model="storeIds">
                                <span>{{ s.name }} <small class="text-muted" v-if="s.city">({{ s.city }})</small></span>
                            </label>
                        </div>
                    </div>

                    <div class="alert alert-warning mb-0" v-if="metaLoaded && !meta.has_attributes">
                        {{ __('this_category_has_no_attributes_add_attributes_first') }}
                    </div>

                    <!-- Column preview so the admin knows exactly what file they'll get. -->
                    <div v-if="metaLoaded && meta.has_attributes" class="mt-2">
                        <label class="fw-bold">{{ __('file_columns') }} ({{ meta.columns.length }})</label>
                        <div class="d-flex flex-wrap gap-1">
                            <span v-for="(c, i) in meta.columns" :key="i" class="badge"
                                :class="c.required ? 'bg-primary' : 'bg-secondary'">{{ c.label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: file -->
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">2. {{ isUpdate ? __('download_edit_and_reupload') : __('download_sample_fill_and_upload') }}</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <p class="mb-1" v-if="!isUpdate">{{ __('bulk_upload_hint_handle_groups_variants') }}</p>
                        <p class="mb-1" v-if="isUpdate">{{ __('bulk_update_hint_merge_only') }}</p>
                        <p class="mb-0">{{ __('always_download_and_use_new_sample_file_if_you_did_updated_admin_panel_version') }}</p>
                    </div>

                    <button class="btn btn-outline-primary mb-3" :disabled="!selectionReady || downloading" @click="downloadFile">
                        <b-spinner small v-if="downloading" />
                        <Download :size="15" v-else />
                        {{ isUpdate ? __('download_products_for_update') : __('download_sample_file') }}
                    </button>

                    <!-- Row-level validation errors -->
                    <div class="alert alert-danger" v-if="rowErrors.length">
                        <p class="mb-2">
                            {{ __('validation_failed_for_some_rows_please_fix_and_re_upload') }}
                            <strong v-if="errorCount > rowErrors.length">({{ errorCount }} {{ __('total') }})</strong>
                        </p>
                        <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">{{ __('row') }}</th>
                                        <th>{{ __('errors') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(e, i) in rowErrors" :key="i">
                                        <td>{{ e.row }}</td>
                                        <td>{{ e.error }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Progress of a dispatched run (polled from bulk/status). -->
                    <div v-if="job" class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span>
                                {{ job.status === 'completed' ? __('import_finished') : (job.status === 'failed' ? __('import_failed') : __('importing_please_wait')) }}
                            </span>
                            <span>{{ job.processed_rows }} / {{ job.total_rows }}</span>
                        </div>
                        <div class="progress" style="height: 20px;">
                            <div class="progress-bar" role="progressbar"
                                :class="{ 'bg-success': job.status === 'completed' && !(job.errors || []).length,
                                          'bg-warning': job.status === 'completed' && (job.errors || []).length,
                                          'bg-danger': job.status === 'failed',
                                          'progress-bar-striped progress-bar-animated': job.status === 'processing' || job.status === 'pending' }"
                                :style="{ width: progressPct + '%' }">
                                {{ progressPct }}%
                            </div>
                        </div>
                        <div class="mt-2" v-if="job.status === 'completed'">
                            <span class="badge bg-success me-2">{{ __('successful') }}: {{ job.success_count }}</span>
                            <span class="badge bg-danger" v-if="(job.errors || []).length">{{ __('failed') }}: {{ job.errors.length }}</span>
                        </div>
                        <div class="alert alert-danger mt-2 mb-0" v-if="job.status === 'failed' && job.message">{{ job.message }}</div>
                        <!-- Runtime failures: rows that passed validation but failed to write. -->
                        <div class="alert alert-warning mt-2 mb-0" v-if="job.status === 'completed' && (job.errors || []).length">
                            <p class="mb-2">{{ __('some_rows_failed_during_processing') }}</p>
                            <div class="table-responsive" style="max-height: 240px; overflow-y: auto;">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr v-for="(e, i) in job.errors" :key="i">
                                            <td style="width: 80px;">{{ e.row }}</td>
                                            <td>{{ e.error }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="submit" enctype="multipart/form-data">
                        <FileUpload v-model="file" :label="__('upload_file') + ' ' + __('excel_file')"
                            accept=".xlsx,.xls" :show-preview="false"
                            :recommended-text="__('supported_formats') + ': XLSX, XLS'"
                            @change="rowErrors = []" />

                        <button type="submit" class="btn btn-primary mt-2" :disabled="!selectionReady || !file || isLoading || polling">
                            <b-spinner small v-if="isLoading || polling" />
                            <Upload :size="15" v-else />
                            {{ isUpdate ? __('update') : __('upload') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import FileUpload from '../../components/FileUpload.vue';
import { ArrowLeft, Download, Upload, Info } from 'lucide-vue-next';

export default {
    components: { FileUpload, ArrowLeft, Download, Upload, Info },
    props: {
        // 'upload' creates products; 'update' merges edits into existing ones.
        mode: { type: String, default: 'upload' },
    },
    data() {
        return {
            salesChannel: 'both',
            categoryLevels: [],
            categoryId: null,
            stores: [],
            storeIds: [],
            meta: {},
            metaLoaded: false,
            file: null,
            rowErrors: [],
            errorCount: 0,
            isLoading: false,
            downloading: false,
            job: null,
            polling: false,
            pollTimer: null,
            showInstructions: false,
        };
    },
    computed: {
        // Fixed option set — no search box needed.
        salesChannelOptions() {
            return [
                { id: 'both', name: (__('both')) },
                { id: 'quick', name: (__('quick')) },
                { id: 'ecommerce', name: (__('ecommerce')) },
            ];
        },
        isUpdate() {
            return this.mode === 'update';
        },
        selectionReady() {
            return !!this.categoryId && this.storeIds.length > 0 && this.metaLoaded && this.meta.has_attributes;
        },
        progressPct() {
            if (!this.job || !this.job.total_rows) {
                return 0;
            }
            return Math.round((this.job.processed_rows / this.job.total_rows) * 100);
        },
    },
    beforeUnmount() {
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
        }
    },
    watch: {
        storeIds() {
            this.fetchMeta();
        },
        // Same component backs both /bulk/upload and /bulk/update, so Vue reuses
        // the instance when switching routes — the previously selected file and
        // job state would otherwise leak across. Reset when the mode flips.
        mode() {
            this.resetState();
        },
    },
    created() {
        this.initCategoryLevels();
        this.fetchStores();
    },
    methods: {
        resetState() {
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
            this.salesChannel = 'both';
            this.categoryId = null;
            this.storeIds = [];
            this.meta = {};
            this.metaLoaded = false;
            this.file = null;
            this.rowErrors = [];
            this.errorCount = 0;
            this.isLoading = false;
            this.downloading = false;
            this.job = null;
            this.polling = false;
            this.showInstructions = false;
            this.initCategoryLevels();
            this.fetchStores();
        },
        onChannelChange() {
            this.storeIds = [];
            this.fetchStores();
        },
        fetchStores() {
            axios.get(this.$apiUrl + '/products/bulk/meta', { params: { sales_channel: this.salesChannel } })
                .then(r => { this.stores = r.data.data?.stores || []; })
                .catch(() => { this.stores = []; });
        },
        initCategoryLevels() {
            axios.get(this.$apiUrl + '/categories/main').then(r => {
                const items = r.data.data || [];
                this.categoryLevels = [{ parentId: 0, items, selectedId: null }];
            }).catch(() => { this.categoryLevels = [{ parentId: 0, items: [], selectedId: null }]; });
        },
        selectCategoryAt(level, id) {
            this.categoryLevels = this.categoryLevels.slice(0, level + 1);
            this.categoryLevels[level] = { ...this.categoryLevels[level], selectedId: id };
            this.categoryId = id;
            this.metaLoaded = false;
            axios.get(this.$apiUrl + '/categories', { params: { parent_id: id } }).then(r => {
                const children = (Array.isArray(r.data.data) ? r.data.data : [])
                    .filter(c => Number(c.parent_id || 0) === Number(id));
                if (children.length > 0) {
                    // Not a leaf yet — products attach to leaf categories.
                    this.categoryId = null;
                    this.categoryLevels.push({ parentId: id, items: children, selectedId: null });
                } else {
                    this.fetchMeta();
                }
            }).catch(() => this.fetchMeta());
        },
        fetchMeta() {
            if (!this.categoryId || !this.storeIds.length) {
                this.metaLoaded = false;
                return;
            }
            axios.get(this.$apiUrl + '/products/bulk/meta', {
                params: {
                    category_id: this.categoryId,
                    store_ids: this.storeIds.join(','),
                    sales_channel: this.salesChannel,
                    for_update: this.isUpdate ? 1 : 0,
                },
            }).then(r => {
                this.meta = r.data.data || {};
                this.metaLoaded = true;
            }).catch(() => { this.metaLoaded = false; });
        },
        downloadFile() {
            this.downloading = true;
            const endpoint = this.isUpdate ? '/products/bulk/export' : '/products/bulk/sample';
            axios.get(this.$apiUrl + endpoint, {
                params: { category_id: this.categoryId, store_ids: this.storeIds.join(',') },
                responseType: 'blob',
            }).then(async (res) => {
                // An error response also arrives as a blob — detect JSON and surface it.
                if (res.data.type === 'application/json') {
                    const body = JSON.parse(await res.data.text());
                    this.showError(body.message || __('something_went_wrong'));
                    return;
                }
                const url = window.URL.createObjectURL(new Blob([res.data], {
                    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                }));
                const a = document.createElement('a');
                a.href = url;
                a.download = (this.isUpdate ? 'products_' : 'sample_') + this.categoryId + '.xlsx';
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(url);
            }).catch(() => this.showError(__('something_went_wrong')))
                .finally(() => { this.downloading = false; });
        },
        submit() {
            this.isLoading = true;
            this.rowErrors = [];
            this.job = null;
            const fd = new FormData();
            fd.append('file', this.file);
            fd.append('category_id', this.categoryId);
            fd.append('store_ids', this.storeIds.join(','));
            const endpoint = this.isUpdate ? '/products/bulk/update' : '/products/bulk/upload';
            axios.post(this.$apiUrl + endpoint, fd, { headers: { 'Content-Type': 'multipart/form-data' } })
                .then(res => {
                    const data = res.data;
                    if (data.status === 1 && data.data && data.data.import_id) {
                        // Sync queue: this snapshot is already terminal. Async queue:
                        // it says pending/processing and we poll until done.
                        this.job = data.data;
                        this.file = null;
                        this.trackJob();
                    } else if (Array.isArray(data.errors)) {
                        this.rowErrors = data.errors;
                        this.errorCount = data.error_count || data.errors.length;
                        this.showError(data.message);
                    } else {
                        this.showError(data.message || __('something_went_wrong'));
                    }
                })
                .catch(() => this.showError(__('something_went_wrong')))
                .finally(() => { this.isLoading = false; });
        },
        trackJob() {
            if (!this.job) {
                return;
            }
            if (this.job.status === 'completed') {
                this.polling = false;
                const failed = (this.job.errors || []).length;
                if (failed) {
                    this.showError(__('some_rows_failed_during_processing'));
                } else {
                    this.showMessage('success', this.isUpdate ? __('products_updated_successfully') : __('products_imported_successfully'));
                }
                return;
            }
            if (this.job.status === 'failed') {
                this.polling = false;
                this.showError(this.job.message || __('something_went_wrong'));
                return;
            }
            // pending / processing -> poll
            this.polling = true;
            this.pollTimer = setTimeout(() => {
                axios.get(this.$apiUrl + '/products/bulk/status', { params: { id: this.job.import_id } })
                    .then(r => { this.job = r.data.data || this.job; })
                    .finally(() => this.trackJob());
            }, 1500);
        },
    },
};
</script>

<style scoped>
/* Cascading category levels sit in a flex row: wide enough to read a category
   name, narrow enough that several levels fit before wrapping. Overrides the
   global `.multiselect.form-select { width: 100% }`, which would put each level
   on its own line. */
.bulk-cat-select {
    width: 220px !important;
    flex: 0 0 auto;
}
</style>
