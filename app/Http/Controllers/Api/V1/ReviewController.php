<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ReviewController extends Controller
{

    /**
     * Get reviews for a product.
     *
     * @urlParam productId required Product ID.
     * @queryParam page int Page number (default: 1).
     * @queryParam limit int Items per page (default: 10).
     * @queryParam sort string Sort by: newest, highest, lowest (default: newest).
     * @queryParam rating int Filter by rating (1-5).
     *
     * @param int $productId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($productId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:50',
            'sort' => 'string|in:newest,highest,lowest',
            'rating' => 'integer|between:1,5'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $sort = $request->get('sort', 'newest');
        $rating = $request->get('rating');

        try {
            $cacheKey = "product_reviews:{$productId}:{$page}:{$limit}:{$sort}:" . ($rating ?: 'all');
            $cacheDuration = now()->addMinutes(15);

            $reviews = Cache::remember($cacheKey, $cacheDuration, function () use ($productId, $sort, $rating) {
                $query = Review::where('product_id', $productId)
                    ->where('status', 1)
                    ->with(['user' => function ($query) {
                        $query->select('customer_id', 'firstname', 'lastname');
                    }]);

                if ($rating) {
                    $query->where('rating', $rating);
                }

                switch ($sort) {
                    case 'highest':
                        $query->orderBy('rating', 'desc');
                        break;
                    case 'lowest':
                        $query->orderBy('rating', 'asc');
                        break;
                    default:
                        $query->orderBy('date_added', 'desc');
                }

                return $query->paginate(10);
            });

            return response()->json([
                'data' => $this->formatReviews($reviews->items()),
                'meta' => [
                    'current_page' => $reviews->currentPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'total_pages' => $reviews->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve product reviews', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve product reviews',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }




    /**
     * Get reviews for a product.
     *
     * @urlParam productId required Product ID.
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     * @queryParam sort string Sort by: newest, highest, lowest.
     *
     * @param  int  $productId
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function byProduct($productId, Request $request)
    {
        $product = Product::active()->findOrFail($productId);

        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
            'sort' => 'nullable|in:newest,highest,lowest',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = Review::where('product_id', $productId)
            ->where('status', 1) // Only approved reviews
            ->with('customer:id,firstname,lastname');

        $sort = $request->get('sort', 'newest');
        switch ($sort) {
            case 'highest':
                $query->orderBy('rating', 'desc');
                break;
            case 'lowest':
                $query->orderBy('rating', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('date_added', 'desc');
                break;
        }

        $limit = $request->get('limit', 10);
        $reviews = $query->paginate($limit);

        $formatted = $reviews->getCollection()->map(function ($review) {
            return [
                'id' => $review->review_id,
                'author' => $review->author,
                'text' => $review->text,
                'rating' => $review->rating,
                'date_added' => $review->date_added,
                'helpful_count' => 0, // Implement if you have helpful votes table
                'reported' => false, // Implement if you have reports table
            ];
        });

        $averageRating = Review::where('product_id', $productId)
            ->where('status', 1)
            ->avg('rating') ?? 0;

        $ratingDistribution = Review::where('product_id', $productId)
            ->where('status', 1)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        return response()->json([
            'product' => [
                'id' => $product->product_id,
                'name' => $product->descriptions->first()?->name ?? '',
                'average_rating' => round($averageRating, 1),
                'total_reviews' => Review::where('product_id', $productId)->where('status', 1)->count(),
            ],
            'reviews' => [
                'data' => $formatted,
                'meta' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                ],
            ],
            'rating_distribution' => [
                5 => $ratingDistribution[5] ?? 0,
                4 => $ratingDistribution[4] ?? 0,
                3 => $ratingDistribution[3] ?? 0,
                2 => $ratingDistribution[2] ?? 0,
                1 => $ratingDistribution[1] ?? 0,
            ],
        ]);
    }

    /**
     * Get user's submitted reviews.
     *
     * @queryParam page int Page number.
     * @queryParam limit int Items per page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function byUser(Request $request)
    {
        $user = $request->user();

        $limit = $request->get('limit', 10);
        $reviews = Review::where('customer_id', $user->customer_id)
            ->with(['product' => function ($query) {
                $query->with(['descriptions' => fn($q) => $q->where('language_id', 1)]);
            }])
            ->orderBy('date_added', 'desc')
            ->paginate($limit);

        $formatted = $reviews->getCollection()->map(function ($review) {
            $product = $review->product;
            return [
                'id' => $review->review_id,
                'product_id' => $product->product_id,
                'product_name' => $product->descriptions->first()?->name ?? '',
                'text' => $review->text,
                'rating' => $review->rating,
                'status' => $review->status ? 'approved' : 'pending',
                'date_added' => $review->date_added,
                'date_modified' => $review->date_modified,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    /**
     * Add a new review for a product.
     *
     * @urlParam productId required Product ID.
     * @bodyParam rating integer required Rating (1-5).
     * @bodyParam text string required Review text (min: 10, max: 1000).
     *
     * @param int $productId
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($productId, Request $request)
    {
        $user = $request->user();

        // Check if user has purchased the product
        $hasPurchased = DB::table('oc_order_product as op')
            ->join('oc_order as o', 'op.order_id', '=', 'o.order_id')
            ->where('o.customer_id', $user->customer_id)
            ->where('op.product_id', $productId)
            ->where('o.order_status_id', '>', 0)
            ->exists();

        if (!$hasPurchased) {
            return response()->json([
                'message' => 'You must have purchased this product to review it'
            ], 403);
        }

        // Check if user has already reviewed this product
        $alreadyReviewed = Review::where('customer_id', $user->customer_id)
            ->where('product_id', $productId)
            ->exists();

        if ($alreadyReviewed) {
            return response()->json([
                'message' => 'You have already reviewed this product'
            ], 403);
        }

        // Apply rate limiting
        $rateLimitKey = 'review:' . $user->customer_id . ':' . $productId;
        if (!RateLimiter::attempt($rateLimitKey, 1, fn() => 3600)) {
            return response()->json([
                'message' => 'You can only submit one review per hour'
            ], 429);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|between:1,5',
            'text' => 'required|string|min:10|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $review = null;
            DB::transaction(function () use ($productId, $request, $user) {
                $review = Review::create([
                    'product_id' => $productId,
                    'customer_id' => $user->customer_id,
                    'author' => $user->firstname . ' ' . $user->lastname,
                    'text' => $request->text,
                    'rating' => $request->rating,
                    'status' => 1, // Approved
                    'date_added' => now(),
                    'date_modified' => now()
                ]);

                // Update product average rating
                $this->updateProductRating($productId);
            });

            // Clear review cache
            Cache::forget("product_reviews:{$productId}:*");

            return response()->json([
                'message' => 'Review submitted successfully',
                'data' => $this->formatReview($review)
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to submit review', [
                'user_id' => $user->customer_id,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to submit review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update an existing review.
     *
     * @urlParam productId required Product ID.
     * @urlParam id required Review ID.
     * @bodyParam rating integer Rating (1-5).
     * @bodyParam text string Review text (min: 10, max: 1000).
     *
     * @param int $productId
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($productId, $id, Request $request)
    {
        $user = $request->user();

        try {
            $review = Review::where('review_id', $id)
                ->where('product_id', $productId)
                ->where('customer_id', $user->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'rating' => 'integer|between:1,5',
                'text' => 'string|min:10|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Allow updates only within 24 hours
            if ($review->date_added < now()->subDay()) {
                return response()->json([
                    'message' => 'You can only edit your review within 24 hours of posting'
                ], 403);
            }

            DB::transaction(function () use ($review, $request) {
                $updateData = $request->only(['rating', 'text']);
                $updateData['date_modified'] = now();

                $review->update($updateData);

                // Update product average rating
                $this->updateProductRating($review->product_id);
            });

            // Clear review cache
            Cache::forget("product_reviews:{$productId}:*");

            return response()->json([
                'message' => 'Review updated successfully',
                'data' => $this->formatReview($review->fresh())
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Review not found or you do not have permission to edit it'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to update review', [
                'user_id' => $user->customer_id,
                'review_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to update review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete a review.
     *
     * @urlParam productId required Product ID.
     * @urlParam id required Review ID.
     *
     * @param int $productId
     * @param int $id
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($productId, $id, Request $request)
    {
        $user = $request->user();

        try {
            $review = Review::where('review_id', $id)
                ->where('product_id', $productId)
                ->where('customer_id', $user->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Allow deletion only within 24 hours
            if ($review->date_added < now()->subDay()) {
                return response()->json([
                    'message' => 'You can only delete your review within 24 hours of posting'
                ], 403);
            }

            DB::transaction(function () use ($review) {
                $productId = $review->product_id;
                $review->delete();

                // Update product average rating
                $this->updateProductRating($productId);
            });

            // Clear review cache
            Cache::forget("product_reviews:{$productId}:*");

            return response()->json([
                'message' => 'Review deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Review not found or you do not have permission to delete it'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete review', [
                'user_id' => $user->customer_id,
                'review_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to delete review',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }



    /**
     * Update product average rating.
     *
     * @param int $productId
     * @return void
     */
    private function updateProductRating($productId)
    {
        $stats = DB::table('oc_review')
            ->where('product_id', $productId)
            ->where('status', 1)
            ->select(
                DB::raw('AVG(rating) as average_rating'),
                DB::raw('COUNT(*) as review_count')
            )
            ->first();

        DB::table('oc_product')
            ->where('product_id', $productId)
            ->update([
                'rating' => $stats->average_rating,
                'reviews' => $stats->review_count
            ]);
    }


    /**
     * Format reviews for API response.
     *
     * @param array $reviews
     * @return array
     */
    private function formatReviews($reviews)
    {
        return array_map(function ($review) {
            return $this->formatReview($review);
        }, $reviews);
    }

    /**
     * Format a single review for API response.
     *
     * @param \App\Models\Review $review
     * @return array
     */
    private function formatReview($review)
    {
        return [
            'id' => $review->review_id,
            'rating' => $review->rating,
            'text' => $review->text,
            'author' => $review->author,
            'date_added' => $review->date_added,
            'date_modified' => $review->date_modified,
            'user' => $review->user ? [
                'id' => $review->user->customer_id,
                'name' => $review->user->firstname . ' ' . $review->user->lastname
            ] : null
        ];
    }


    /**
     * Report an inappropriate review.
     *
     * @urlParam id required Review ID.
     * @bodyParam reason string Reason for reporting.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function report(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $review = Review::findOrFail($id);

        // In a real app, you would store this in a reports table
        // For now, we'll just log it
        Log::warning('Review reported', [
            'review_id' => $review->review_id,
            'product_id' => $review->product_id,
            'customer_id' => $request->user()?->customer_id,
            'reason' => $request->reason,
            'ip' => $request->ip(),
        ]);

        // Optional: Send notification to admin
        // $this->notifyAdminOfReport($review, $request->reason);

        return response()->json([
            'message' => 'Review reported successfully. Our team will review it shortly.',
        ]);
    }

    /**
     * Helper: Notify admin of review report (optional).
     *
     * @param  \App\Models\Review  $review
     * @param  string  $reason
     */
    protected function notifyAdminOfReport($review, $reason)
    {
        // Implement email or notification system here
        // For example:
        // Mail::to('admin@yourstore.com')->send(new ReviewReported($review, $reason));
    }
}
