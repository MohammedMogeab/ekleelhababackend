<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionProduct extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription_product';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'subscription_product_id';

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
        'subscription_product_id' => 'integer',
        'subscription_id' => 'integer',
        'order_id' => 'integer',
        'order_product_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'trial_price' => 'decimal:4',
        'trial_tax' => 'decimal:4',
        'price' => 'decimal:4',
        'tax' => 'decimal:4',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscription_id',
        'order_id',
        'order_product_id',
        'product_id',
        'name',
        'model',
        'quantity',
        'trial_price',
        'trial_tax',
        'price',
        'tax',
    ];

    /**
     * Get the subscription that owns the product.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    /**
     * Get the product associated with the subscription product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }

    /**
     * Get the original order that created the subscription.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Get the original order product that created the subscription.
     */
    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo(OrderProduct::class, 'order_product_id', 'order_product_id');
    }

    /**
     * Scope to get subscription products for a specific subscription.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $subscriptionId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubscription($query, $subscriptionId)
    {
        return $query->where('subscription_id', $subscriptionId);
    }

    /**
     * Scope to get subscription products for a specific product.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $productId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Get the total price (price + tax) for the subscription product.
     *
     * @return float
     */
    public function getTotalPrice(): float
    {
        return (float)($this->price + $this->tax);
    }

    /**
     * Get the total trial price (trial_price + trial_tax) for the subscription product.
     *
     * @return float
     */
    public function getTotalTrialPrice(): float
    {
        return (float)($this->trial_price + $this->trial_tax);
    }

    /**
     * Check if the subscription product is currently in trial period.
     *
     * @return bool
     */
    public function inTrial(): bool
    {
        $subscription = $this->subscription;
        return $subscription ? $subscription->inTrial() : false;
    }

    /**
     * Get the current price based on whether in trial period.
     *
     * @return float
     */
    public function getCurrentPrice(): float
    {
        return $this->inTrial() ? (float)$this->trial_price : (float)$this->price;
    }

    /**
     * Get the current tax based on whether in trial period.
     *
     * @return float
     */
    public function getCurrentTax(): float
    {
        return $this->inTrial() ? (float)$this->trial_tax : (float)$this->tax;
    }

    /**
     * Get the current total (price + tax) based on whether in trial period.
     *
     * @return float
     */
    public function getCurrentTotal(): float
    {
        return $this->getCurrentPrice() + $this->getCurrentTax();
    }

    /**
     * Get the formatted current price.
     *
     * @return string
     */
    public function getFormattedCurrentPrice(): string
    {
        return number_format($this->getCurrentPrice(), 2);
    }

    /**
     * Get the formatted current total.
     *
     * @return string
     */
    public function getFormattedCurrentTotal(): string
    {
        return number_format($this->getCurrentTotal(), 2);
    }
}