<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class Subscription extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'subscription_id';

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
        'subscription_id' => 'integer',
        'order_id' => 'integer',
        'store_id' => 'integer',
        'customer_id' => 'integer',
        'payment_address_id' => 'integer',
        'shipping_address_id' => 'integer',
        'subscription_plan_id' => 'integer',
        'trial_price' => 'decimal:4',
        'trial_tax' => 'decimal:4',
        'trial_cycle' => 'integer',
        'trial_duration' => 'integer',
        'trial_remaining' => 'integer',
        'trial_status' => 'boolean',
        'price' => 'decimal:4',
        'tax' => 'decimal:4',
        'cycle' => 'integer',
        'duration' => 'integer',
        'remaining' => 'integer',
        'subscription_status_id' => 'integer',
        'date_added' => 'datetime',
        'date_modified' => 'datetime',
        'date_next' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'store_id',
        'customer_id',
        'payment_address_id',
        'payment_method',
        'shipping_address_id',
        'shipping_method',
        'subscription_plan_id',
        'trial_price',
        'trial_tax',
        'trial_frequency',
        'trial_cycle',
        'trial_duration',
        'trial_remaining',
        'trial_status',
        'price',
        'tax',
        'frequency',
        'cycle',
        'duration',
        'remaining',
        'date_next',
        'comment',
        'subscription_status_id',
        'language',
        'currency',
        'date_added',
        'date_modified',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'payment_method',
        'shipping_method',
    ];

    /**
     * Get the customer that owns the subscription.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /**
     * Get the order that created the subscription.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Get the subscription plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id', 'subscription_plan_id');
    }

    /**
     * Get the subscription status.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(SubscriptionStatus::class, 'subscription_status_id', 'subscription_status_id');
    }

    /**
     * Get the payment address for the subscription.
     */
    public function paymentAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'payment_address_id', 'address_id');
    }

    /**
     * Get the shipping address for the subscription.
     */
    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id', 'address_id');
    }

    /**
     * Get the products associated with the subscription.
     */
    public function products(): HasMany
    {
        return $this->hasMany(SubscriptionProduct::class, 'subscription_id', 'subscription_id');
    }

    /**
     * Get the subscription history.
     */
    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class, 'subscription_id', 'subscription_id')
            ->orderBy('date_added', 'desc');
    }

    /**
     * Get the latest history entry.
     */
    public function latestHistory(): HasOne
    {
        return $this->hasOne(SubscriptionHistory::class, 'subscription_id', 'subscription_id')
            ->orderBy('date_added', 'desc');
    }

    /**
     * Get the subscription logs.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SubscriptionLog::class, 'subscription_id', 'subscription_id')
            ->orderBy('date_added', 'desc');
    }

    /**
     * Scope to get active subscriptions.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        $activeStatusIds = SubscriptionStatus::where('name', 'Active')->pluck('subscription_status_id');
        return $query->whereIn('subscription_status_id', $activeStatusIds);
    }

    /**
     * Scope to get canceled subscriptions.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCanceled(Builder $query): Builder
    {
        $canceledStatusIds = SubscriptionStatus::where('name', 'like', '%canceled%')->pluck('subscription_status_id');
        return $query->whereIn('subscription_status_id', $canceledStatusIds);
    }

    /**
     * Scope to get subscriptions due for next payment.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('date_next', '<=', now())
            ->where('remaining', '>', 0)
            ->where('subscription_status_id', '=', function ($query) {
                $query->select('subscription_status_id')
                    ->from('oc_subscription_status')
                    ->where('name', 'Active')
                    ->where('language_id', 1)
                    ->limit(1);
            });
    }

    /**
     * Get the trial status as a readable string.
     *
     * @return string
     */
    public function getTrialStatusAttribute(): string
    {
        return $this->trial_status ? 'Active' : 'Completed';
    }

    /**
     * Get the subscription status name.
     *
     * @return string
     */
    public function getStatusNameAttribute(): string
    {
        return $this->status ? $this->status->name : 'Unknown';
    }

    /**
     * Check if the subscription is currently in trial period.
     *
     * @return bool
     */
    public function inTrial(): bool
    {
        return $this->trial_status && $this->trial_remaining > 0;
    }

    /**
     * Get the next payment date formatted.
     *
     * @return string
     */
    public function getNextPaymentDateAttribute(): string
    {
        return $this->date_next ? $this->date_next->format('Y-m-d H:i:s') : 'N/A';
    }

    /**
     * Calculate the next payment amount (considering trial period).
     *
     * @return float
     */
    public function getNextPaymentAmount(): float
    {
        if ($this->inTrial() && $this->trial_price !== null) {
            return (float)$this->trial_price;
        }
        
        return (float)$this->price;
    }

    /**
     * Get the frequency label for display.
     *
     * @return string
     */
    public function getFrequencyLabelAttribute(): string
    {
        $labels = [
            'day' => 'Daily',
            'week' => 'Weekly',
            'semi_month' => 'Semi-Monthly',
            'month' => 'Monthly',
            'year' => 'Yearly',
        ];
        
        return $labels[$this->frequency] ?? $this->frequency;
    }

    /**
     * Get the trial frequency label for display.
     *
     * @return string
     */
    public function getTrialFrequencyLabelAttribute(): string
    {
        $labels = [
            'day' => 'Daily',
            'week' => 'Weekly',
            'semi_month' => 'Semi-Monthly',
            'month' => 'Monthly',
            'year' => 'Yearly',
        ];
        
        return $labels[$this->trial_frequency] ?? $this->trial_frequency;
    }
}