<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\CategoryPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Store a newly created category.
     *
     * @bodyParam parent_id int Parent category ID (0 for root).
     * @bodyParam name string required Category name.
     * @bodyParam description string Category description.
     * @bodyParam meta_title string Meta title.
     * @bodyParam meta_description string Meta description.
     * @bodyParam meta_keyword string Meta keywords.
     * @bodyParam image string Image path.
     * @bodyParam top boolean Show in top menu.
     * @bodyParam column int Number of columns for layout.
     * @bodyParam sort_order int Sort order.
     * @bodyParam status boolean Category status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'parent_id' => 'nullable|integer|exists:oc_category,category_id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:255',
            'meta_keyword' => 'nullable|string|max:255',
            'image' => 'nullable|string',
            'top' => 'nullable|boolean',
            'column' => 'nullable|integer|min:1|max:12',
            'sort_order' => 'nullable|integer',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $category = Category::create([
                'parent_id' => $request->parent_id ?? 0,
                'image' => $request->image,
                'top' => $request->top ?? false,
                'column' => $request->column ?? 1,
                'sort_order' => $request->sort_order ?? 0,
                'status' => $request->status,
                'date_added' => now(),
                'date_modified' => now(),
            ]);

            // Create description (language_id = 1 assumed)
            CategoryDescription::create([
                'category_id' => $category->category_id,
                'language_id' => 1,
                'name' => $request->name,
                'description' => $request->description ?? '',
                'meta_title' => $request->meta_title ?? $request->name,
                'meta_description' => $request->meta_description ?? '',
                'meta_keyword' => $request->meta_keyword ?? Str::slug($request->name),
            ]);

            // Rebuild category paths
            $this->rebuildCategoryPaths($category->category_id);

            DB::commit();

            return response()->json([
                'message' => 'Category created successfully',
                'category' => [
                    'id' => $category->category_id,
                    'name' => $request->name,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Category creation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create category',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified category.
     *
     * @urlParam id required Category ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'parent_id' => 'sometimes|required|integer|exists:oc_category,category_id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:255',
            'meta_keyword' => 'nullable|string|max:255',
            'image' => 'nullable|string',
            'top' => 'nullable|boolean',
            'column' => 'nullable|integer|min:1|max:12',
            'sort_order' => 'nullable|integer',
            'status' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Update category
            $category->fill($request->only([
                'parent_id', 'image', 'top', 'column', 'sort_order', 'status'
            ]));
            $category->date_modified = now();
            $category->save();

            // Update description
            if ($request->filled(['name', 'description', 'meta_title', 'meta_description', 'meta_keyword'])) {
                $desc = CategoryDescription::firstOrCreate([
                    'category_id' => $category->category_id,
                    'language_id' => 1,
                ]);

                $desc->update([
                    'name' => $request->name ?? $desc->name,
                    'description' => $request->description ?? $desc->description,
                    'meta_title' => $request->meta_title ?? $desc->meta_title,
                    'meta_description' => $request->meta_description ?? $desc->meta_description,
                    'meta_keyword' => $request->meta_keyword ?? $desc->meta_keyword,
                ]);
            }

            // Rebuild paths if parent changed
            if ($request->has('parent_id')) {
                $this->rebuildAllCategoryPaths();
            }

            DB::commit();

            return response()->json([
                'message' => 'Category updated successfully',
                'category' => [
                    'id' => $category->category_id,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Category update failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update category',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified category.
     *
     * @urlParam id required Category ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // Check if category has children or products
        if ($category->children()->exists()) {
            return response()->json([
                'message' => 'Cannot delete category with children. Delete children first.',
            ], 400);
        }

        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Cannot delete category with products. Reassign products first.',
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Delete description
            CategoryDescription::where('category_id', $id)->delete();

            // Delete paths
            CategoryPath::where('category_id', $id)->delete();

            // Delete category
            $category->delete();

            // Rebuild all paths
            $this->rebuildAllCategoryPaths();

            DB::commit();

            return response()->json([
                'message' => 'Category deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Category deletion failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete category',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rebuild paths for a single category and its descendants.
     *
     * @param  int  $categoryId
     */
    protected function rebuildCategoryPaths($categoryId)
    {
        // Delete existing paths for this category
        CategoryPath::where('category_id', $categoryId)->delete();

        // Get ancestors
        $ancestors = [];
        $currentId = $categoryId;

        while ($currentId) {
            $category = Category::find($currentId);
            if (!$category) break;

            array_unshift($ancestors, [
                'category_id' => $categoryId,
                'path_id' => $category->category_id,
                'level' => count($ancestors),
            ]);

            $currentId = $category->parent_id ?: null;
        }

        // Insert new paths
        foreach ($ancestors as $path) {
            CategoryPath::create($path);
        }

        // Recursively rebuild for children
        $children = Category::where('parent_id', $categoryId)->get();
        foreach ($children as $child) {
            $this->rebuildCategoryPaths($child->category_id);
        }
    }

    /**
     * Rebuild paths for all categories (use after structural changes).
     */
    protected function rebuildAllCategoryPaths()
    {
        CategoryPath::truncate();

        $rootCategories = Category::where('parent_id', 0)->get();
        foreach ($rootCategories as $root) {
            $this->rebuildCategoryPaths($root->category_id);
        }
    }
}