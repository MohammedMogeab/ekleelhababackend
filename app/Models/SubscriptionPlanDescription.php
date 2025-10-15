<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanDescription extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription_plan_description';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Indicates if the model has an incrementing ID.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the model.
     *
     * @var array
     */
    protected $primaryKey = ['subscription_plan_id', 'language_id'];

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscription_plan_id',
        'language_id',
        'name',
        'description',
    ];

    /**
     * Get the subscription plan that owns the description.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id', 'subscription_plan_id');
    }

    /**
     * Get the language for the description.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id', 'language_id');
    }

    /**
     * Scope to get descriptions for a specific plan.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $planId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForPlan($query, $planId)
    {
        return $query->where('subscription_plan_id', $planId);
    }

    /**
     * Scope to get descriptions in a specific language.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $languageId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLanguage($query, $languageId)
    {
        return $query->where('language_id', $languageId);
    }

    /**
     * Scope to get descriptions for the default language (language_id = 1).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefaultLanguage($query)
    {
        return $query->where('language_id', 1);
    }

    /**
     * Get the description attribute with fallback to empty string.
     *
     * @return string
     */
    public function getDescriptionAttribute(): string
    {
        return $this->attributes['description'] ?? '';
    }

    /**
     * Check if the description is in the default language.
     *
     * @return bool
     */
    public function isDefaultLanguage(): bool
    {
        return $this->language_id === 1;
    }

    /**
     * Get the language name if available.
     *
     * @return string
     */
    public function getLanguageNameAttribute(): ?string
    {
        if (isset($this->language)) {
            return $this->language->name;
        }
        
        // If language relationship isn't loaded, try to fetch it
        try {
            $language = DB::table('oc_language')->where('language_id', $this->language_id)->first();
            return $language ? $language->name : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}