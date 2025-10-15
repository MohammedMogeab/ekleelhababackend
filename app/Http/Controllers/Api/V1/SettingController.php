<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    /**
     * Get general site settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $cacheKey = 'site_settings_' . app()->getLocale();
        
        $settings = Cache::remember($cacheKey, 3600, function () {
            // Get basic store settings
            $storeName = $this->getSettingValue('config_name', config('app.name'));
            $storeEmail = $this->getSettingValue('config_email', 'support@yourstore.com');
            $storePhone = $this->getSettingValue('config_telephone', '');
            $storeAddress = $this->getSettingValue('config_address', '');
            
            // Get currency settings
            $currency = DB::table('oc_currency')
                ->where('code', 'SAR') // Default to SAR, adjust as needed
                ->first();
                
            // Get language settings
            $languages = DB::table('oc_language')
                ->where('status', 1)
                ->orderBy('sort_order', 'asc')
                ->get()
                ->map(function ($lang) {
                    return [
                        'id' => $lang->language_id,
                        'name' => $lang->name,
                        'code' => $lang->code,
                        'locale' => $lang->locale,
                        'image' => $lang->image,
                        'is_default' => $lang->code === 'ar', // Adjust default language code
                    ];
                });

            return [
                'store' => [
                    'name' => $storeName,
                    'email' => $storeEmail,
                    'phone' => $storePhone,
                    'address' => $storeAddress,
                    'url' => config('app.url'),
                    'logo' => $this->getSettingValue('config_logo', ''),
                    'icon' => $this->getSettingValue('config_icon', ''),
                ],
                'currency' => [
                    'code' => $currency ? $currency->code : 'SAR',
                    'symbol' => $currency ? ($currency->symbol_left ?: $currency->symbol_right) : 'ر.س',
                    'decimal_place' => $currency ? $currency->decimal_place : 2,
                    'value' => $currency ? $currency->value : 1.00000000,
                ],
                'languages' => $languages,
                'contact' => [
                    'email' => $storeEmail,
                    'phone' => $storePhone,
                    'address' => $storeAddress,
                    'working_hours' => $this->getSettingValue('config_open', ''),
                    'social_media' => [
                        'facebook' => $this->getSettingValue('config_facebook', ''),
                        'twitter' => $this->getSettingValue('config_twitter', ''),
                        'instagram' => $this->getSettingValue('config_instagram', ''),
                        'youtube' => $this->getSettingValue('config_youtube', ''),
                    ],
                ],
                'features' => [
                    'guest_checkout' => true,
                    'wishlist_enabled' => true,
                    'reviews_enabled' => true,
                    'coupons_enabled' => true,
                ],
                'updated_at' => now(),
            ];
        });

        return response()->json($settings);
    }

    /**
     * Get shipping methods and costs.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function shipping()
    {
        $cacheKey = 'shipping_settings';
        
        $shipping = Cache::remember($cacheKey, 3600, function () {
            // Get shipping methods from settings
            $shippingMethods = [];
            
            // Flat rate shipping
            if ($this->getSettingValue('shipping_flat_status', 0)) {
                $shippingMethods[] = [
                    'id' => 'flat',
                    'name' => 'Flat Rate Shipping',
                    'description' => $this->getSettingValue('shipping_flat_description', 'Standard shipping rate'),
                    'cost' => (float) $this->getSettingValue('shipping_flat_cost', 15.00),
                    'tax_class_id' => (int) $this->getSettingValue('shipping_flat_tax_class_id', 0),
                    'geo_zone_id' => (int) $this->getSettingValue('shipping_flat_geo_zone_id', 0),
                    'status' => true,
                ];
            }
            
            // Free shipping
            if ($this->getSettingValue('shipping_free_status', 0)) {
                $shippingMethods[] = [
                    'id' => 'free',
                    'name' => 'Free Shipping',
                    'description' => $this->getSettingValue('shipping_free_description', 'Free shipping on orders over minimum amount'),
                    'minimum_order' => (float) $this->getSettingValue('shipping_free_total', 200.00),
                    'status' => true,
                ];
            }
            
            // Local pickup
            if ($this->getSettingValue('shipping_pickup_status', 0)) {
                $shippingMethods[] = [
                    'id' => 'pickup',
                    'name' => 'Local Pickup',
                    'description' => $this->getSettingValue('shipping_pickup_description', 'Pick up from our store'),
                    'cost' => 0.00,
                    'status' => true,
                ];
            }

            // Get geo zones for advanced shipping
            $geoZones = DB::table('oc_geo_zone')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($zone) {
                    return [
                        'id' => $zone->geo_zone_id,
                        'name' => $zone->name,
                        'description' => $zone->description,
                    ];
                });

            return [
                'methods' => $shippingMethods,
                'geo_zones' => $geoZones,
                'default_method' => $this->getSettingValue('config_shipping_method', 'flat'),
                'handling_fee' => (float) $this->getSettingValue('config_shipping_handling', 0.00),
                'tax_included' => (bool) $this->getSettingValue('config_shipping_tax', 0),
                'updated_at' => now(),
            ];
        });

        return response()->json($shipping);
    }

    /**
     * Get return policy.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function returnPolicy()
    {
        $cacheKey = 'return_policy_' . app()->getLocale();
        
        $policy = Cache::remember($cacheKey, 86400, function () {
            // Try to get from information table first
            $returnInfo = DB::table('oc_information as i')
                ->join('oc_information_description as id', 'i.information_id', '=', 'id.information_id')
                ->where('id.title', 'like', '%return%')
                ->where('id.language_id', $this->getLanguageId())
                ->first();

            if ($returnInfo) {
                return [
                    'title' => $returnInfo->title,
                    'content' => $returnInfo->description,
                    'meta_title' => $returnInfo->meta_title,
                    'meta_description' => $returnInfo->meta_description,
                    'updated_at' => now(),
                ];
            }

            // Fallback to settings or hardcoded content
            return [
                'title' => 'Return Policy',
                'content' => '<h3>Return Policy</h3><p>You may return most new, unopened items within 30 days of delivery for a full refund. We\'ll also pay the return shipping costs if the return is a result of our error (you received an incorrect or defective item, etc.).</p><p>You should expect to receive your refund within four weeks of giving your package to the return shipper, however, in many cases you will receive a refund more quickly.</p>',
                'meta_title' => 'Return Policy - Our Store',
                'meta_description' => 'Learn about our return policy and how to return items.',
                'updated_at' => now(),
            ];
        });

        return response()->json($policy);
    }

    /**
     * Get privacy policy.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacy()
    {
        $cacheKey = 'privacy_policy_' . app()->getLocale();
        
        $policy = Cache::remember($cacheKey, 86400, function () {
            // Try to get from information table first
            $privacyInfo = DB::table('oc_information as i')
                ->join('oc_information_description as id', 'i.information_id', '=', 'id.information_id')
                ->where('id.title', 'like', '%privacy%')
                ->where('id.language_id', $this->getLanguageId())
                ->first();

            if ($privacyInfo) {
                return [
                    'title' => $privacyInfo->title,
                    'content' => $privacyInfo->description,
                    'meta_title' => $privacyInfo->meta_title,
                    'meta_description' => $privacyInfo->meta_description,
                    'updated_at' => now(),
                ];
            }

            // Fallback to settings or hardcoded content
            return [
                'title' => 'Privacy Policy',
                'content' => '<h3>Privacy Policy</h3><p>Your privacy is important to us. It is our policy to respect your privacy regarding any information we may collect from you across our website.</p><p>We only ask for personal information when we truly need it to provide a service to you. We collect it by fair and lawful means, with your knowledge and consent. We also let you know why we\'re collecting it and how it will be used.</p>',
                'meta_title' => 'Privacy Policy - Our Store',
                'meta_description' => 'Learn about our privacy policy and how we protect your data.',
                'updated_at' => now(),
            ];
        });

        return response()->json($policy);
    }

    /**
     * Helper: Get setting value from oc_setting table.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    protected function getSettingValue($key, $default = null)
    {
        $setting = DB::table('oc_setting')
            ->where('key', $key)
            ->where('store_id', 0) // Default store
            ->first();

        if ($setting && $setting->serialized) {
            return unserialize($setting->value);
        }

        return $setting ? $setting->value : $default;
    }

    /**
     * Helper: Get current language ID.
     *
     * @return int
     */
    protected function getLanguageId()
    {
        // Default to language_id = 1 (English)
        // You can modify this based on user preferences or request headers
        return 1;
    }


    public function pages()
    {
        $pages = [
            'about' => $this->getPageContent('about'),
            'contact' => $this->getPageContent('contact'),
            'privacy' => $this->getPageContent('privacy'),
            'terms' => $this->getPageContent('terms'),
            'return' => $this->getPageContent('return_policy')
        ];

        return response()->json($pages);
    }

    private function getPageContent($key)
    {
        $page = DB::table('oc_information_description')
            ->where('information_id', function ($query) use ($key) {
                $query->select('information_id')
                    ->from('oc_information')
                    ->where('keyword', $key)
                    ->limit(1);
            })
            ->where('language_id', 1)
            ->first();

        if ($page) {
            return [
                'title' => $page->title,
                'content' => $page->description,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'updated_at' => now()->toDateTimeString()
        ];
    }
        // Fallback to default content if page not found
        switch ($key) {
            case 'about':
                return [
                    'title' => 'About Us',
                    'content' => '<h3>About Us</h3><p>Welcome to our online store.</p>',
                    'meta_title' => 'About Us - Our Store',
                    'meta_description' => 'Learn more about our company',
                    'updated_at' => now()->toDateTimeString()
                ];
            // Add other cases as needed
            default:
                return [
                    'title' => 'Page',
                    'content' => '<h3>Page</h3><p>Content not available</p>',
                    'meta_title' => 'Page - Our Store',
                    'meta_description' => 'Page content not available',
                    'updated_at' => now()->toDateTimeString()
                ];
        }
    }
    
}