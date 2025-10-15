<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\V1 as V1;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::prefix('v1')->group(function () {

    // ========================
    // AUTH MODULE
    // ========================
    Route::post('/auth/registertest', [V1\AuthTestController::class, 'register']);


    Route::post('/auth/register', [V1\AuthController::class, 'register']);
    Route::post('/auth/login', [V1\AuthController::class, 'login']);
    Route::post('/auth/logout', [V1\AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/auth/forgot-password', [V1\AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [V1\AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [V1\AuthController::class, 'me']);
        Route::put('/auth/me', [V1\AuthController::class, 'updateProfile']);
        Route::put('/auth/me/password', [V1\AuthController::class, 'changePassword']);
    });

    // ========================
    // SEARCH MODULE
    // ========================
    Route::get('/search', [V1\SearchController::class, 'index']);
    Route::get('/search/suggest', [V1\SearchController::class, 'suggest']);
    Route::get('/filters', [V1\SearchController::class, 'filters']);

    // ========================
    // PRODUCTS MODULE
    // ========================
    Route::get('/products/deals', [V1\ProductController::class, 'deals']);
    Route::get('/products/new', [V1\ProductController::class, 'newArrivals']);
    Route::get('/products', [V1\ProductController::class, 'index']);
    Route::get('/products/{id}', [V1\ProductController::class, 'show'])->where('id', '[0-9]+');
    Route::get('/products/top', [V1\ProductController::class, 'top']);
    Route::get('/products/related/{id}', [V1\ProductController::class, 'related']);
    Route::get('/products/similar/{id}', [V1\ProductController::class, 'similar']);

    // Admin Product Routes
    Route::middleware(['auth:sanctum', 'token.can:admin'])->prefix('admin')->group(function () {
        Route::post('/products', [V1\Admin\ProductController::class, 'store']);
        Route::put('/products/{id}', [V1\Admin\ProductController::class, 'update']);
        Route::delete('/products/{id}', [V1\Admin\ProductController::class, 'destroy']);
    });

    // ========================
    // CATEGORIES MODULE
    // ========================
    Route::get('/categories', [V1\CategoryController::class, 'tree']);
    Route::get('/categories/{id}', [V1\CategoryController::class, 'show']);

    // Admin Category Routes
    Route::middleware(['auth:sanctum', 'token.can:admin'])->prefix('admin')->group(function () {
        Route::post('/categories', [V1\Admin\CategoryController::class, 'store']);
        Route::put('/categories/{id}', [V1\Admin\CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [V1\Admin\CategoryController::class, 'destroy']);
    });

    // ========================
    // ADDRESSES MODULE
    // ========================
    Route::middleware(['auth:sanctum', 'token.can:user'])->prefix('users')->group(function () {
        Route::get('/addresses', [V1\AddressController::class, 'index']);
        Route::post('/addresses', [V1\AddressController::class, 'store']);
        Route::put('/addresses/{id}', [V1\AddressController::class, 'update']);
        Route::delete('/addresses/{id}', [V1\AddressController::class, 'destroy']);
    });

    // ========================
    // CART MODULE
    // ========================
    Route::middleware('auth:sanctum')->group(function () {
        // Authenticated user cart
        Route::get('/cart', [V1\CartController::class, 'show']);
        Route::post('/cart/items', [V1\CartController::class, 'addItem']);
        Route::put('/cart/items/{id}', [V1\CartController::class, 'updateItem']);
        Route::delete('/cart/items/{id}', [V1\CartController::class, 'removeItem']);
        Route::delete('/cart', [V1\CartController::class, 'clear']);
    });

    // Guest cart (optional — if you want to allow cart before login)
    // You can handle this via session_id or device_id in controller logic.
    Route::post('/cart/guest/items', [V1\CartController::class, 'addGuestItem']);
    Route::get('/cart/guest', [V1\CartController::class, 'showGuestCart']);

    // ========================
    // WISHLIST MODULE
    // ========================
    Route::middleware(['auth:sanctum', 'token.can:user'])->group(function () {
        Route::get('/wishlist', [V1\WishlistController::class, 'index']);
        Route::post('/wishlist', [V1\WishlistController::class, 'store']);
        Route::delete('/wishlist/{productId}', [V1\WishlistController::class, 'destroy']);
    });

    // ========================
    // ORDERS MODULE
    // ========================
    Route::middleware(['auth:sanctum', 'token.can:user'])->group(function () {
        Route::post('/checkout', [V1\OrderController::class, 'checkout']);
        Route::get('/orders', [V1\OrderController::class, 'index']);
        Route::get('/orders/{id}', [V1\OrderController::class, 'show']);
        Route::post('/orders/{id}/cancel', [V1\OrderController::class, 'cancel']);
        Route::post('/orders/{id}/return', [V1\OrderController::class, 'requestReturn']);
    });

    // Admin Order Routes
    Route::middleware(['auth:sanctum', 'token.can:admin'])->prefix('admin')->group(function () {
        Route::get('/orders', [V1\Admin\OrderController::class, 'index']);
        Route::get('/orders/{id}', [V1\Admin\OrderController::class, 'show']);
        Route::put('/orders/{id}/status', [V1\Admin\OrderController::class, 'updateStatus']);
    });

    // ========================
    // PAYMENTS MODULE
    // ========================
    // Initiate payment for an order
    Route::post('/payment/checkout', [V1\PaymentController::class, 'requestPayment']);
    Route::middleware('auth:sanctum')->group(function () {
        // Post-payment actions (by order_id)
        Route::post('/payment/rebill/{order_id}', [V1\PaymentController::class, 'rebillPayment']);
        Route::post('/payment/refund/{order_id}', [V1\PaymentController::class, 'refundPayment']);
        Route::post('/payment/reverse/{order_id}', [V1\PaymentController::class, 'reversePayment']);
    });
    // Status & callback
    Route::get('/payment/status/order/{order_id}', [V1\PaymentController::class, 'paymentStatusByOrder']);
    Route::get('/payment/callback', [V1\PaymentController::class, 'callback']);


    // ========================
    // REVIEWS MODULE
    // ========================
    Route::get('/reviews/product/{productId}', [V1\ReviewController::class, 'byProduct']);
    Route::middleware(['auth:sanctum', 'token.can:user'])->group(function () {
        Route::get('/reviews/user', [V1\ReviewController::class, 'byUser']);
        Route::post('/reviews', [V1\ReviewController::class, 'store']);
        Route::put('/reviews/{id}', [V1\ReviewController::class, 'update']);
        Route::delete('/reviews/{id}', [V1\ReviewController::class, 'destroy']);
        Route::post('/reviews/{id}/report', [V1\ReviewController::class, 'report']);
    });

    // ========================
    // SELLERS MODULE
    // ========================
    Route::get('/sellers', [V1\SellerController::class, 'index']);
    Route::get('/sellers/{id}', [V1\SellerController::class, 'show']);
    Route::get('/sellers/{id}/products', [V1\SellerController::class, 'products']);
    Route::post('/sellers/applications', [V1\SellerController::class, 'apply']);

    // ========================
    // COUPONS MODULE
    // ========================
    Route::get('/coupons/validate', [V1\CouponController::class, 'validate']);
    Route::get('/promotions', [V1\CouponController::class, 'promotions']);

    // Admin Coupon Routes
    Route::middleware(['auth:sanctum', 'token.can:admin'])->prefix('admin')->group(function () {
        Route::get('/coupons', [V1\Admin\CouponController::class, 'index']);
        Route::post('/coupons', [V1\Admin\CouponController::class, 'store']);
        Route::put('/coupons/{id}', [V1\Admin\CouponController::class, 'update']);
        Route::delete('/coupons/{id}', [V1\Admin\CouponController::class, 'destroy']);
    });

    // ========================
    // ANALYTICS MODULE (Admin Only)
    // ========================
    Route::middleware(['auth:sanctum', 'token.can:admin'])->prefix('admin')->group(function () {
        Route::get('/analytics/sales', [V1\Admin\AnalyticsController::class, 'sales']);
        Route::get('/analytics/products', [V1\Admin\AnalyticsController::class, 'products']);
        Route::get('/analytics/customers', [V1\Admin\AnalyticsController::class, 'customers']);
        Route::get('/analytics/traffic', [V1\Admin\AnalyticsController::class, 'traffic']);
        Route::get('/analytics/revenue', [V1\Admin\AnalyticsController::class, 'revenue']);
    });

    // ========================
    // NOTIFICATIONS MODULE
    // ========================
    Route::middleware(['auth:sanctum', 'token.can:user'])->group(function () {
        Route::get('/notifications', [V1\NotificationController::class, 'index']);
        Route::put('/notifications/{id}/read', [V1\NotificationController::class, 'markAsRead']);
        Route::delete('/notifications/{id}', [V1\NotificationController::class, 'destroy']);
        Route::put('/notifications/mark-all-read', [V1\NotificationController::class, 'markAllRead']);
    });

    // ========================
    // CMS MODULE
    // ========================
    Route::get('/pages/home', [V1\CmsController::class, 'home']);
    Route::get('/pages/about', [V1\CmsController::class, 'about']);
    Route::get('/pages/{slug}', [V1\CmsController::class, 'page']);
    Route::get('/banners', [V1\CmsController::class, 'banners']);

    // ========================
    // SETTINGS MODULE
    // ========================
    Route::get('/settings', [V1\SettingController::class, 'index']);
    Route::get('/settings/shipping', [V1\SettingController::class, 'shipping']);
    Route::get('/settings/return-policy', [V1\SettingController::class, 'returnPolicy']);
    Route::get('/settings/privacy', [V1\SettingController::class, 'privacy']);

    // ========================
    // ADMIN MODULE
    // ========================
    Route::middleware(['auth:sanctum', 'token.can:admin'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [V1\Admin\DashboardController::class, 'index']);
        Route::get('/users', [V1\Admin\UserController::class, 'index']);
        Route::put('/users/{id}/role', [V1\Admin\UserController::class, 'updateRole']);
        Route::delete('/users/{id}', [V1\Admin\UserController::class, 'destroy']);
        Route::get('/logs', [V1\Admin\LogController::class, 'index']); // optional
    });

    // ========================
    // WEBHOOKS MODULE
    // ========================
    Route::post('/webhooks/stripe', [V1\WebhookController::class, 'stripe']);
    Route::post('/webhooks/paypal', [V1\WebhookController::class, 'paypal']);
    Route::post('/webhooks/shipping', [V1\WebhookController::class, 'shipping']);


    // ========================
    // BRANDS MODULE
    // ========================
    Route::get('/brands', [V1\BrandController::class, 'index']);
    Route::get('/brands/featured', [V1\BrandController::class, 'featured']);
    Route::get('/brands/{id}', [V1\BrandController::class, 'show']);
    Route::get('/brands/letter/{letter}', [V1\BrandController::class, 'byLetter']);
});
