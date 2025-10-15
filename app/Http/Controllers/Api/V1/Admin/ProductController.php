<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Models\ProductImage;
use App\Models\ProductSpecial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Store a newly created product.
     *
     * @bodyParam model string required Product model.
     * @bodyParam sku string Product SKU.
     * @bodyParam price numeric required Price.
     * @bodyParam quantity integer required Stock quantity.
     * @bodyParam status boolean required Product status.
     * @bodyParam name string required Product name (language_id=1).
     * @bodyParam description string Product description.
     * @bodyParam categories array List of category IDs.
     * @bodyParam image string Main image path.
     * @bodyParam gallery array List of additional image paths.
     * @bodyParam special_price numeric Special price (optional).
     * @bodyParam special_start string Special start date (Y-m-d).
     * @bodyParam special_end string Special end date (Y-m-d).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'model' => 'required|string|max:64',
            'sku' => 'nullable|string|max:64',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'status' => 'required|boolean',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:oc_category,category_id',
            'image' => 'nullable|string',
            'gallery' => 'nullable|array',
            'gallery.*' => 'string',
            'special_price' => 'nullable|numeric|min:0',
            'special_start' => 'nullable|date',
            'special_end' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $product = Product::create([
                'model' => $request->model,
                'sku' => $request->sku,
                'price' => $request->price,
                'quantity' => $request->quantity,
                'status' => $request->status,
                'image' => $request->image,
                'date_added' => now(),
                'date_modified' => now(),
                'manufacturer_id' => 0, // Set default or add to validation
                'stock_status_id' => 7, // In Stock (adjust based on your oc_stock_status)
                'tax_class_id' => 9,    // Taxable Goods (adjust based on your oc_tax_class)
                'weight_class_id' => 1,
                'length_class_id' => 1,
                'subtract' => 1,
                'minimum' => 1,
                'sort_order' => 0,
                'viewed' => 0,
            ]);

            // Create description
            ProductDescription::create([
                'product_id' => $product->product_id,
                'language_id' => 1,
                'name' => $request->name,
                'description' => $request->description ?? '',
                'tag' => '',
                'meta_title' => $request->name,
                'meta_description' => Str::limit(strip_tags($request->description ?? ''), 160),
                'meta_keyword' => Str::slug($request->name),
            ]);

            // Attach categories
            if ($request->filled('categories')) {
                DB::table('oc_product_to_category')->insert(
                    collect($request->categories)->map(fn($catId) => [
                        'product_id' => $product->product_id,
                        'category_id' => $catId,
                    ])->toArray()
                );
            }

            // Insert gallery images
            if ($request->filled('gallery')) {
                foreach ($request->gallery as $image) {
                    ProductImage::create([
                        'product_id' => $product->product_id,
                        'image' => $image,
                        'sort_order' => 0,
                    ]);
                }
            }

            // Create special price
            if ($request->filled('special_price')) {
                ProductSpecial::create([
                    'product_id' => $product->product_id,
                    'customer_group_id' => 1, // Default group
                    'priority' => 1,
                    'price' => $request->special_price,
                    'date_start' => $request->special_start ?? '0000-00-00',
                    'date_end' => $request->special_end ?? '0000-00-00',
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Product created successfully',
                'product' => [
                    'id' => $product->product_id,
                    'name' => $request->name,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Product creation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified product.
     *
     * @urlParam id required Product ID.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'model' => 'sometimes|required|string|max:64',
            'sku' => 'nullable|string|max:64',
            'price' => 'sometimes|required|numeric|min:0',
            'quantity' => 'sometimes|required|integer|min:0',
            'status' => 'sometimes|required|boolean',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:oc_category,category_id',
            'image' => 'nullable|string',
            'gallery' => 'nullable|array',
            'gallery.*' => 'string',
            'special_price' => 'nullable|numeric|min:0',
            'special_start' => 'nullable|date',
            'special_end' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Update product
            $product->update($request->only([
                'model', 'sku', 'price', 'quantity', 'status', 'image'
            ]));
            $product->date_modified = now();
            $product->save();

            // Update description
            if ($request->filled('name') || $request->filled('description')) {
                $desc = ProductDescription::firstOrCreate([
                    'product_id' => $product->product_id,
                    'language_id' => 1,
                ]);

                $desc->update([
                    'name' => $request->name ?? $desc->name,
                    'description' => $request->description ?? $desc->description,
                    'meta_title' => $request->name ?? $desc->meta_title,
                    'meta_description' => $request->description ? Str::limit(strip_tags($request->description), 160) : $desc->meta_description,
                    'meta_keyword' => $request->name ? Str::slug($request->name) : $desc->meta_keyword,
                ]);
            }

            // Sync categories
            if ($request->filled('categories')) {
                DB::table('oc_product_to_category')
                    ->where('product_id', $product->product_id)
                    ->delete();

                DB::table('oc_product_to_category')->insert(
                    collect($request->categories)->map(fn($catId) => [
                        'product_id' => $product->product_id,
                        'category_id' => $catId,
                    ])->toArray()
                );
            }

            // Replace gallery
            if ($request->filled('gallery')) {
                ProductImage::where('product_id', $product->product_id)->delete();

                foreach ($request->gallery as $image) {
                    ProductImage::create([
                        'product_id' => $product->product_id,
                        'image' => $image,
                        'sort_order' => 0,
                    ]);
                }
            }

            // Update special
            if ($request->filled('special_price')) {
                $special = ProductSpecial::firstOrNew([
                    'product_id' => $product->product_id,
                    'customer_group_id' => 1,
                ]);

                $special->fill([
                    'priority' => 1,
                    'price' => $request->special_price,
                    'date_start' => $request->special_start ?? '0000-00-00',
                    'date_end' => $request->special_end ?? '0000-00-00',
                ])->save();
            } elseif ($request->has('special_price') && $request->special_price === null) {
                // Delete special if explicitly set to null
                ProductSpecial::where('product_id', $product->product_id)->delete();
            }

            DB::commit();

            return response()->json([
                'message' => 'Product updated successfully',
                'product' => [
                    'id' => $product->product_id,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Product update failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified product.
     *
     * @urlParam id required Product ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        DB::beginTransaction();

        try {
            // Delete relations first
            ProductDescription::where('product_id', $id)->delete();
            ProductImage::where('product_id', $id)->delete();
            ProductSpecial::where('product_id', $id)->delete();
            DB::table('oc_product_to_category')->where('product_id', $id)->delete();
            DB::table('oc_product_related')->where('product_id', $id)->orWhere('related_id', $id)->delete();

            // Delete product
            $product->delete();

            DB::commit();

            return response()->json([
                'message' => 'Product deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Product deletion failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}