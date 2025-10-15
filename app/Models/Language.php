<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Language extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_language';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'language_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'language_id' => 'integer',
        'sort_order' => 'integer',
        'status' => 'boolean',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'code',
        'locale',
        'extension',
        'sort_order',
        'status',
    ];

    /**
     * Get the categories descriptions for this language.
     */
    public function categoryDescriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class, 'language_id', 'language_id');
    }

    /**
     * Get the product descriptions for this language.
     */
    public function productDescriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class, 'language_id', 'language_id');
    }

    /**
     * Get the order statuses for this language.
     */
    public function orderStatuses(): HasMany
    {
        return $this->hasMany(OrderStatus::class, 'language_id', 'language_id');
    }

    /**
     * Get the subscription statuses for this language.
     */
    public function subscriptionStatuses(): HasMany
    {
        return $this->hasMany(SubscriptionStatus::class, 'language_id', 'language_id');
    }

    /**
     * Get the subscription plan descriptions for this language.
     */
    public function subscriptionPlanDescriptions(): HasMany
    {
        return $this->hasMany(SubscriptionPlanDescription::class, 'language_id', 'language_id');
    }

    /**
     * Scope to get active languages.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * Scope to get languages ordered by sort order.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc');
    }

    /**
     * Check if the language is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status;
    }

    /**
     * Get the locale parts.
     *
     * @return array
     */
    public function getLocaleParts(): array
    {
        if (empty($this->locale)) {
            return [];
        }
        
        return explode(',', $this->locale);
    }

    /**
     * Get the primary locale.
     *
     * @return string|null
     */
    public function getPrimaryLocale(): ?string
    {
        $parts = $this->getLocaleParts();
        return !empty($parts) ? $parts[0] : null;
    }

    /**
     * Get the language direction (ltr or rtl).
     *
     * @return string
     */
    public function getDirectionAttribute(): string
    {
        // Arabic, Hebrew, and other RTL languages
        $rtlLanguages = ['ar', 'he', 'fa', 'ur'];
        
        return in_array($this->code, $rtlLanguages) ? 'rtl' : 'ltr';
    }

    /**
     * Check if the language is RTL (Right-to-Left).
     *
     * @return bool
     */
    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }

    /**
     * Check if the language is LTR (Left-to-Right).
     *
     * @return bool
     */
    public function isLtr(): bool
    {
        return $this->direction === 'ltr';
    }

    /**
     * Get the default language.
     *
     * @return \App\Models\Language|null
     */
    public static function getDefault()
    {
        return self::where('code', config('app.locale', 'en'))
            ->orWhere('language_id', 1)
            ->first();
    }

    /**
     * Get all active languages.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActiveLanguages()
    {
        return self::active()->ordered()->get();
    }
}