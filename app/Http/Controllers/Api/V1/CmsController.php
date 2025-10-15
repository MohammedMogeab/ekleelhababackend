<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class CmsController extends Controller
{
    /**
     * Get home page content.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function home()
    {
        
        try {
            Cache::store('redis')->put('test_key', 'hello', 600);
            $cacheKey = 'cms_home_page';
            $cacheDuration = now()->addHours(1);

            $content = Cache::remember($cacheKey, $cacheDuration, function () {
                return [
                    'hero_banner' => $this->getHeroBanners(),
                    'featured_categories' => $this->getFeaturedCategories(),
                    'deals_of_the_day' => $this->getDealsOfTheDay(),
                    'top_selling_products' => $this->getTopSellingProducts(),
                    'new_arrivals' => $this->getNewArrivals(),
                    'testimonials' => $this->getTestimonials(),
                    'featured_brands' => $this->getFeaturedBrands()
                ];
            });

            return response()->json([
                'data' => $content
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve home page content', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve home page content',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get about page content.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function about()
    {
        try {
            $cacheKey = 'cms_about_page';
            $cacheDuration = now()->addHours(24);

            $content = Cache::remember($cacheKey, $cacheDuration, function () {
                return $this->getPageContent('about');
            });

            return response()->json([
                'data' => $content
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve about page content', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve about page content',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get page by slug.
     *
     * @urlParam slug required Page slug.
     * @return \Illuminate\Http\JsonResponse
     */
    public function page($slug)
    {
        try {
            $cacheKey = 'cms_page_' . $slug;
            $cacheDuration = now()->addHours(24);

            $content = Cache::remember($cacheKey, $cacheDuration, function () use ($slug) {
                return $this->getPageContent($slug);
            });

            if (!$content) {
                return response()->json([
                    'message' => 'Page not found'
                ], 404);
            }

            return response()->json([
                'data' => $content
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve page content', [
                'slug' => $slug,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve page content',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get banners for the app.
     *
     * @queryParam type string Filter by banner type (home, category, product).
     * @queryParam limit int Limit the number of banners returned.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function banners(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'string|in:home,category,product',
            'limit' => 'integer|min:1|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $type = $request->get('type');
        $limit = $request->get('limit', 10);

        try {
            $cacheKey = 'cms_banners:' . ($type ?: 'all') . ':' . $limit;
            $cacheDuration = now()->addHours(1);

            $banners = Cache::remember($cacheKey, $cacheDuration, function () use ($type, $limit) {
                return $this->getBanners($type, $limit);
            });

            return response()->json([
                'data' => $banners
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve banners', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve banners',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get hero banners.
     *
     * @return array
     */
    private function getHeroBanners()
    {
        // Check if we have the so_homeslider table (from schema.txt)
        $hasSliderTable = DB::select("SHOW TABLES LIKE 'oc_so_homeslider'");

        if (!empty($hasSliderTable)) {
            return DB::table('oc_so_homeslider')
                ->where('status', 1)
                ->orderBy('position')
                ->get()
                ->map(function ($banner) {
                    return [
                        'id' => $banner->id,
                        'url' => $banner->url,
                        'image' => $banner->image ? env("IMAGE_BASE_PATH") . $banner->image : null,
                        'position' => $banner->position
                    ];
                })
                ->toArray();
        }

        // Fallback to standard banners if no slider table
        return $this->getBanners('home', 5);
    }

    /**
     * Get featured categories.
     *
     * @return array
     */
    private function getFeaturedCategories()
    {
        return DB::table('oc_category as c')
            ->join('oc_category_description as cd', 'c.category_id', '=', 'cd.category_id')
            ->where('cd.language_id', 1)
            ->where('c.status', 1)
            ->where('c.parent_id', 0)
            ->whereNotNull('c.image')
            ->select(
                'c.category_id as id',
                'cd.name',
                'c.image'
            )
            ->orderBy('c.sort_order')
            ->limit(8)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'image' => $category->image ? env("IMAGE_BASE_PATH") . $category->image : null
                ];
            })
            ->toArray();
    }
    /**
     * Get deals of the day.
     *
     * @return array
     */
    private function getDealsOfTheDay()
    {
        return DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->join('oc_product_special as ps', 'p.product_id', '=', 'ps.product_id')
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->where('ps.date_start', '<=', now())
            ->where(function ($query) {
                $query->where('ps.date_end', '>=', now());
            })
            ->select(
                'p.product_id as id',
                'pd.name',
                'p.price',
                'ps.price as special_price',
                'p.image'
            )
            ->orderBy('ps.date_start', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($product) {
                $discountPercentage = round((($product->price - $product->special_price) / $product->price) * 100);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float)$product->price,
                    'special_price' => (float)$product->special_price,
                    'discount_percentage' => $discountPercentage,
                    'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null
                ];
            })
            ->toArray();
    }

    /**
     * Get top selling products.
     *
     * @param int $limit
     * @return array
     */
    private function getTopSellingProducts($limit = 5)
    {
        return DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->join('oc_order_product as op', 'p.product_id', '=', 'op.product_id')
            ->join('oc_order as o', 'op.order_id', '=', 'o.order_id')
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->where('o.date_added', '>=', now()->subMonth())
            ->where('o.order_status_id', '>', 0)
            ->select(
                'p.product_id as id',
                'pd.name',
                'p.price',
                DB::raw('COALESCE((
                    SELECT price
                    FROM oc_product_special
                    WHERE product_id = p.product_id
                      AND date_start <= NOW()
                      AND (date_end >= NOW() OR date_end IS NULL)
                    ORDER BY priority ASC
                    LIMIT 1
                ), p.price) as final_price'),
                'p.image',
                DB::raw('SUM(op.quantity) as total_sold')
            )
            ->groupBy('p.product_id', 'pd.name', 'p.price', 'p.image')
            ->orderBy('total_sold', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float)$product->price,
                    'final_price' => (float)$product->final_price,
                    'is_on_sale' => $product->final_price < $product->price,
                    'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                    'total_sold' => (int)$product->total_sold
                ];
            })
            ->toArray();
    }

    /**
     * Get new arrivals.
     *
     * @param int $limit
     * @return array
     */
    private function getNewArrivals($limit = 5)
    {
        return DB::table('oc_product as p')
            ->join('oc_product_description as pd', 'p.product_id', '=', 'pd.product_id')
            ->where('pd.language_id', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->where('p.date_added', '>=', now()->subDays(30))
            ->select(
                'p.product_id as id',
                'pd.name',
                'p.price',
                DB::raw('COALESCE((SELECT price FROM oc_product_special WHERE product_id = p.product_id AND date_start <= NOW() AND (date_end >= NOW() ) ORDER BY priority ASC LIMIT 1), p.price) as final_price'),
                'p.image',
                'p.date_added'
            )
            ->orderBy('p.date_added', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => (float)$product->price,
                    'final_price' => (float)$product->final_price,
                    'is_on_sale' => $product->final_price < $product->price,
                    'image' => $product->image ? env("IMAGE_BASE_PATH") . $product->image : null,
                    'date_added' => $product->date_added
                ];
            })
            ->toArray();
    }

    /**
     * Get testimonials.
     *
     * @return array
     */
    private function getTestimonials()
    {
        // Check if we have testimonial table from schema
        $hasTestimonialTable = DB::select("SHOW TABLES LIKE 'oc_testimonials'");

        if (!empty($hasTestimonialTable)) {
            return DB::table('oc_testimonials')
                ->where('status', 1)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($testimonial) {
                    return [
                        'id' => $testimonial->testimonial_id,
                        'name' => $testimonial->name,
                        'position' => $testimonial->position,
                        'content' => $testimonial->content,
                        'image' => $testimonial->image ? env("IMAGE_BASE_PATH") . $testimonial->image : null,
                        'rating' => $testimonial->rating
                    ];
                })
                ->toArray();
        }

        // Fallback to product reviews - FIX: Join with product description table
        return DB::table('oc_review as r')
            ->join('oc_product as p', 'r.product_id', '=', 'p.product_id')
            ->join('oc_product_description as pd', function ($join) {
                $join->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', 1); // Using default language ID 1
            })
            ->join('oc_customer as c', 'r.customer_id', '=', 'c.customer_id')
            ->where('r.status', 1)
            ->where('p.status', 1)
            ->where('p.quantity', '>', 0)
            ->select(
                'r.review_id as id',
                DB::raw('TRIM(CONCAT(COALESCE(c.firstname, ""), " ", COALESCE(c.lastname, ""))) as name'),
                'pd.name as product_name', // CORRECTED: Getting name from product description
                'r.text as content',
                'r.rating',
                'r.date_added'
            )
            ->orderBy('r.date_added', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($testimonial) {
                return [
                    'id' => $testimonial->id,
                    'name' => $testimonial->name ?: 'Customer',
                    'position' => 'Customer',
                    'content' => $testimonial->content,
                    'image' => null,
                    'rating' => $testimonial->rating
                ];
            })
            ->toArray();
    }

    /**
     * Get featured brands.
     *
     * @return array
     */
    private function getFeaturedBrands()
    {
        return DB::table('oc_manufacturer')
            ->whereNotNull('image')
            ->orderBy('sort_order')
            ->limit(8)
            ->get()
            ->map(function ($brand) {
                return [
                    'id' => $brand->manufacturer_id,
                    'name' => $brand->name,
                    'image' => $brand->image ? env("IMAGE_BASE_PATH") . $brand->image : null
                ];
            })
            ->toArray();
    }

    /**
     * Get page content by slug.
     *
     * @param string $slug
     * @return array|null
     */
    private function getPageContent($slug)
    {
        // Map slug to search terms
        $searchTermsMap = [
            'about' => 'about',
            'contact' => 'contact',
            'privacy' => 'privacy',
            'terms' => 'terms',
            'return' => 'return'
        ];

        $searchTerm = $searchTermsMap[$slug] ?? $slug;

        // Try to get page by joining information tables and searching by title
        $page = DB::table('oc_information as i')
            ->join('oc_information_description as id', 'i.information_id', '=', 'id.information_id')
            ->where('id.title', 'like', '%' . ucfirst($searchTerm) . '%')
            ->where('id.language_id', 1)
            ->select('i.information_id', 'id.*')
            ->first();

        // If not found by title, try meta_keyword
        if (!$page) {
            $page = DB::table('oc_information as i')
                ->join('oc_information_description as id', 'i.information_id', '=', 'id.information_id')
                ->where('id.meta_keyword', 'like', '%' . $searchTerm . '%')
                ->where('id.language_id', 1)
                ->select('i.information_id', 'id.*')
                ->first();
        }

        // If still not found, try hardcoded IDs (common OpenCart defaults)
        if (!$page) {
            $hardcodedIds = [
                'about' => 4,    // Common ID for About Us page
                'contact' => 5,  // Common ID for Contact Us page
                'privacy' => 6,  // Common ID for Privacy Policy
                'terms' => 3,    // Common ID for Terms & Conditions
                'return' => 7    // Common ID for Return Policy
            ];

            if (isset($hardcodedIds[$slug])) {
                $page = DB::table('oc_information as i')
                    ->join('oc_information_description as id', 'i.information_id', '=', 'id.information_id')
                    ->where('i.information_id', $hardcodedIds[$slug])
                    ->where('id.language_id', 1)
                    ->select('i.information_id', 'id.*')
                    ->first();
            }
        }

        if (!$page) {
            return null;
        }

        return [
            'id' => $page->information_id,
            'title' => $page->title,
            'content' => $page->description,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'meta_keyword' => $page->meta_keyword,
            'slug' => $slug
        ];
    }

    /**
     * Get banners.
     *
     * @param string|null $type
     * @param int $limit
     * @return array
     */
    private function getBanners($type = null, $limit = 10)
    {
        $query = DB::table('oc_banner_image as bi')
            ->join('oc_banner as b', 'bi.banner_id', '=', 'b.banner_id');

        if ($type) {
            $query->where('b.name', 'like', '%' . $type . '%');
        }

        $banners = $query
            ->where('bi.language_id', 1)
            ->select(
                'bi.banner_image_id as id',
                'bi.title',
                'bi.link',
                'bi.image',
                'bi.sort_order'
            )
            ->orderBy('bi.sort_order')
            ->limit($limit)
            ->get();

        return $banners->map(function ($banner) {
            return [
                'id' => $banner->id,
                'title' => $banner->title,
                'link' => $banner->link,
                'image' => $banner->image ? env("IMAGE_BASE_PATH") . $banner->image : null,
                'sort_order' => $banner->sort_order
            ];
        })->toArray();
    }
}
