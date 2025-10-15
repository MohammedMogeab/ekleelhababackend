<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionPlan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription_plan';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'subscription_plan_id';

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
        'subscription_plan_id' => 'integer',
        'trial_duration' => 'integer',
        'trial_cycle' => 'integer',
        'trial_status' => 'boolean',
        'duration' => 'integer',
        'cycle' => 'integer',
        'status' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'trial_frequency',
        'trial_duration',
        'trial_cycle',
        'trial_status',
        'frequency',
        'duration',
        'cycle',
        'status',
        'sort_order',
    ];

    /**
     * The frequency options for subscriptions.
     *
     * @var array
     */
    const FREQUENCY_OPTIONS = [
        'day' => 'Daily',
        'week' => 'Weekly',
        'semi_month' => 'Semi-Monthly',
        'month' => 'Monthly',
        'year' => 'Yearly',
    ];

    /**
     * Get the descriptions for the subscription plan.
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(SubscriptionPlanDescription::class, 'subscription_plan_id', 'subscription_plan_id');
    }

    /**
     * Get the default language description for the subscription plan.
     */
    public function defaultDescription(): HasOne
    {
        return $this->hasOne(SubscriptionPlanDescription::class, 'subscription_plan_id', 'subscription_plan_id')
            ->where('language_id', 1);
    }

    /**
     * Scope to get active subscription plans.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * Scope to get plans with trial period.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithTrial(Builder $query): Builder
    {
        return $query->where('trial_status', true)
            ->where('trial_duration', '>', 0)
            ->where('trial_cycle', '>', 0);
    }

    /**
     * Scope to get plans by frequency.
     *
     * @param Builder $query
     * @param string $frequency
     * @return Builder
     */
    public function scopeByFrequency(Builder $query, string $frequency): Builder
    {
        return $query->where('frequency', $frequency);
    }

    /**
     * Get the trial frequency label.
     *
     * @return string
     */
    public function getTrialFrequencyLabelAttribute(): string
    {
        return self::FREQUENCY_OPTIONS[$this->trial_frequency] ?? $this->trial_frequency;
    }

    /**
     * Get the regular frequency label.
     *
     * @return string
     */
    public function getFrequencyLabelAttribute(): string
    {
        return self::FREQUENCY_OPTIONS[$this->frequency] ?? $this->frequency;
    }

    /**
     * Get the trial period duration in days.
     *
     * @return int
     */
    public function getTrialDurationInDaysAttribute(): int
    {
        if (!$this->trial_status || $this->trial_duration <= 0 || $this->trial_cycle <= 0) {
            return 0;
        }

        switch ($this->trial_frequency) {
            case 'day':
                return $this->trial_duration * $this->trial_cycle;
            case 'week':
                return $this->trial_duration * $this->trial_cycle * 7;
            case 'semi_month':
                return $this->trial_duration * $this->trial_cycle * 15;
            case 'month':
                return $this->trial_duration * $this->trial_cycle * 30;
            case 'year':
                return $this->trial_duration * $this->trial_cycle * 365;
            default:
                return 0;
        }
    }

    /**
     * Get the regular period duration in days.
     *
     * @return int
     */
    public function getDurationInDaysAttribute(): int
    {
        if ($this->duration <= 0 || $this->cycle <= 0) {
            return 0;
        }

        switch ($this->frequency) {
            case 'day':
                return $this->duration * $this->cycle;
            case 'week':
                return $this->duration * $this->cycle * 7;
            case 'semi_month':
                return $this->duration * $this->cycle * 15;
            case 'month':
                return $this->duration * $this->cycle * 30;
            case 'year':
                return $this->duration * $this->cycle * 365;
            default:
                return 0;
        }
    }

    /**
     * Check if this plan has a trial period.
     *
     * @return bool
     */
    public function hasTrial(): bool
    {
        return $this->trial_status && $this->trial_duration > 0 && $this->trial_cycle > 0;
    }

    /**
     * Check if this plan is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status;
    }

    /**
     * Get the plan name in the default language.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->defaultDescription->name ?? 'Unnamed Plan';
    }

    /**
     * Get the plan description in the default language.
     *
     * @return string
     */
    public function getDescriptionAttribute(): string
    {
        return $this->defaultDescription->description ?? '';
    }

    /**
     * Format the trial period for display.
     *
     * @return string
     */
    public function getFormattedTrialPeriodAttribute(): string
    {
        if (!$this->hasTrial()) {
            return 'No trial';
        }

        $trialDuration = $this->trial_duration * $this->trial_cycle;
        $frequencyLabel = $this->trialFrequencyLabel;

        return "{$trialDuration} {$frequencyLabel}";
    }

    /**
     * Format the regular billing period for display.
     *
     * @return string
     */
    public function getFormattedBillingPeriodAttribute(): string
    {
        $duration = $this->duration * $this->cycle;
        $frequencyLabel = $this->frequencyLabel;

        return "{$duration} {$frequencyLabel}";
    }
}