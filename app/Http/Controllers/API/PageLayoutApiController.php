<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\PageLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Page Builder: standalone pages built from Home Builder sections. Pages are
 * global; the only ownership rule is that a store user sees and edits its own
 * store's pages, while admin pages (store_id null) belong to the admin panel.
 */
class PageLayoutApiController extends Controller
{
    private array $metaFields = ['name', 'slug', 'is_active'];

    private array $jsonFields = ['title', 'draft_json', 'published_json'];

    public function getPages(Request $request)
    {
        $pages = PageLayout::query()
            ->select(['id', 'name', 'slug', 'title', 'store_id', 'status', 'is_active', 'published_at', 'created_at', 'updated_at'])
            ->orderByDesc('id');

        $storeId = $this->ownStoreId();
        if ($storeId !== null) {
            $pages->where('store_id', $storeId);
        }

        return CommonHelper::responseWithData($pages->get());
    }

    /**
     * Pages a redirect picker may target. Every page is listed (the builder
     * labels drafts / inactive ones) so a banner can be wired before the page
     * goes live. A store user sees its own pages plus the admin's.
     */
    public function options()
    {
        $pages = PageLayout::query()
            ->select(['id', 'name', 'slug', 'status', 'is_active'])
            ->orderBy('name');

        $storeId = $this->ownStoreId();
        if ($storeId !== null) {
            $pages->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId));
        }

        return CommonHelper::responseWithData($pages->get());
    }

    public function edit($id)
    {
        $page = PageLayout::find($id);
        if (!$page || !$this->canAccess($page)) {
            return CommonHelper::responseError('page_not_found');
        }
        return CommonHelper::responseWithData($page);
    }

    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id'             => 'nullable|integer',
            'name'           => 'required|string|max:191',
            'slug'           => 'nullable|string|max:191',
            'title'          => 'nullable|array',
            'is_active'      => 'nullable|boolean',
            'draft_json'     => 'nullable|array',
            'published_json' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return CommonHelper::responseError($validator->errors()->first());
        }

        $existing = $request->filled('id') ? PageLayout::find($request->id) : null;
        if ($request->filled('id') && (!$existing || !$this->canAccess($existing))) {
            return CommonHelper::responseError('page_not_found');
        }

        // Slug: given → normalised and must be free; blank → kept, or derived from the name.
        $slug = $request->filled('slug')
            ? Str::slug($request->input('slug'))
            : ($existing?->slug ?: '');
        if ($slug === '') {
            $slug = CommonHelper::slugify($request->input('name'), 'page_layouts');
        }
        $taken = PageLayout::where('slug', $slug)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        if ($taken) {
            return CommonHelper::responseError('slug_already_exists');
        }
        $request->merge(['slug' => $slug]);

        return DB::transaction(function () use ($request, $existing) {
            $page = $existing ?: new PageLayout();

            foreach ($this->metaFields as $field) {
                if ($request->has($field)) {
                    $page->{$field} = $request->input($field);
                }
            }
            // Uploaded image URLs are stored as storage-relative paths, same as home layouts.
            foreach ($this->jsonFields as $field) {
                if ($request->has($field)) {
                    $page->{$field} = HomeLayoutApiController::normalizeImagePaths($request->input($field));
                }
            }

            if (!$page->exists) {
                $page->status   = 'draft';
                $page->store_id = $this->ownStoreId();
            }

            $page->save();

            return CommonHelper::responseSuccessWithData('page_saved_successfully', $page->fresh());
        });
    }

    public function publish(Request $request)
    {
        $page = $this->findOwned($request);
        if (!$page) {
            return CommonHelper::responseError('page_not_found');
        }

        if ($request->input('from') === 'published') {
            $page->markPublished();
        } else {
            $page->publishDraft();
        }

        return CommonHelper::responseSuccessWithData('page_published_successfully', $page->fresh());
    }

    public function clone(Request $request)
    {
        $source = $this->findOwned($request);
        if (!$source) {
            return CommonHelper::responseError('page_not_found');
        }

        // A copy starts as a draft seeded with whatever the source is showing.
        $copy = $source->replicate(['published_at']);
        $copy->name           = $request->filled('name') ? $request->input('name') : $source->name . ' (copy)';
        $copy->slug           = CommonHelper::slugify($copy->name, 'page_layouts');
        $copy->status         = 'draft';
        $copy->published_at   = null;
        $copy->draft_json     = $source->published_json ?: $source->draft_json;
        $copy->published_json = null;
        $copy->is_active      = 1;
        $copy->save();

        return CommonHelper::responseWithData([
            'id'      => $copy->id,
            'message' => __('page_cloned_successfully'),
        ]);
    }

    /** Only a published page can be switched on/off — a draft has nothing to serve. */
    public function toggleActive(Request $request)
    {
        $page = $this->findOwned($request);
        if (!$page) {
            return CommonHelper::responseError('page_not_found');
        }
        if ($page->status !== 'published') {
            return CommonHelper::responseError('only_published_page_can_be_activated');
        }
        $page->is_active = (int) $request->boolean('is_active');
        $page->save();

        return CommonHelper::responseSuccessWithData('page_status_updated', $page->fresh());
    }

    /**
     * A page that home layouts / other pages still redirect to is only deleted
     * with `force=1` — the first call reports who uses it so the panel can ask
     * again. Once gone, those redirects degrade to 'none' when served.
     */
    public function delete(Request $request)
    {
        if (!$request->filled('id')) {
            return CommonHelper::responseError('page_not_found');
        }
        $page = PageLayout::find($request->id);
        if (!$page) {
            return CommonHelper::responseSuccess('page_deleted_successfully');
        }
        if (!$this->canAccess($page)) {
            return CommonHelper::responseError('page_not_found');
        }

        if (!$request->boolean('force')) {
            $usedBy = $page->referencedBy();
            if ($usedBy) {
                return CommonHelper::responseWithData([
                    'in_use'  => true,
                    'used_by' => $usedBy,
                ]);
            }
        }

        $page->delete();

        return CommonHelper::responseSuccess('page_deleted_successfully');
    }

    /** Page images live in their own folder (page_builder/), apart from home layouts. */
    public function uploadImage(Request $request)
    {
        return (new HomeLayoutApiController())->uploadImage($request, 'page_builder');
    }

    // ---------------------------------------------------------------- helpers

    /** The page named by `id`, or null when missing / not this user's. */
    private function findOwned(Request $request): ?PageLayout
    {
        if (!$request->filled('id')) {
            return null;
        }
        $page = PageLayout::find($request->id);
        return $page && $this->canAccess($page) ? $page : null;
    }

    /** Store users own only their store's pages; everyone else is unrestricted. */
    private function canAccess(PageLayout $page): bool
    {
        $storeId = $this->ownStoreId();
        return $storeId === null || (int) $page->store_id === $storeId;
    }

    /** The store a store user acts for, null for admin-panel users. */
    private function ownStoreId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->isStoreUser() && $user->store) {
            return (int) $user->store->id;
        }
        return null;
    }
}
