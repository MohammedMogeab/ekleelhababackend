<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_order';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'order_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

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
        'total' => 'decimal:4',
        'commission' => 'decimal:4',
        'currency_value' => 'decimal:8',
        'date_added' => 'datetime',
        'date_modified' => 'datetime',
        'order_status_id' => 'integer',
        'customer_id' => 'integer',
        'store_id' => 'integer',
        'subscription_id' => 'integer',
        'invoice_no' => 'integer',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscription_id',
        'invoice_no',
        'invoice_prefix',
        'transaction_id',
        'store_id',
        'store_name',
        'store_url',
        'customer_id',
        'customer_group_id',
        'firstname',
        'lastname',
        'email',
        'telephone',
        'payment_firstname',
        'payment_lastname',
        'payment_address_1',
        'payment_address_2',
        'payment_city',
        'payment_postcode',
        'payment_country',
        'payment_country_id',
        'payment_zone',
        'payment_zone_id',
        'payment_address_format',
        'payment_custom_field',
        'payment_method',
        'payment_code',
        'shipping_firstname',
        'shipping_lastname',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_city',
        'shipping_postcode',
        'shipping_country',
        'shipping_country_id',
        'shipping_zone',
        'shipping_zone_id',
        'shipping_address_format',
        'shipping_custom_field',
        'shipping_method',
        'shipping_code',
        'comment',
        'total',
        'order_status_id',
        'affiliate_id',
        'commission',
        'marketing_id',
        'tracking',
        'language_id',
        'language_code',
        'currency_id',
        'currency_code',
        'currency_value',
        'ip',
        'forwarded_ip',
        'user_agent',
        'accept_language',
        'date_added',
        'date_modified',
        'fax',
        'custom_field',
        'payment_company',
        'payment_address_format',
        'payment_custom_field',
        'shipping_company',
        'shipping_address_format',
        'shipping_custom_field',
        'order_from',
        'order_id',
    ];

    /**
     * Get the order products for the order.
     */
    public function products(): HasMany
    {
        return $this->hasMany(OrderProduct::class, 'order_id', 'order_id');
    }

    /**
     * Get the order totals for the order.
     */
    public function totals(): HasMany
    {
        return $this->hasMany(OrderTotal::class, 'order_id', 'order_id');
    }

    /**
     * Get the order history for the order.
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderHistory::class, 'order_id', 'order_id')->orderBy('date_added', 'desc');
    }

    /**
     * Get the customer that owns the order.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /**
     * Get the order status for the order.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id', 'order_status_id');
    }

    /**
     * Get the subscription associated with the order.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    /**
     * Scope a query to only include pending orders.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('order_status_id', 1); // Assuming 1 is pending status
    }

    /**
     * Scope a query to only include completed orders.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('order_status_id', [3, 5]); // Assuming 3 is shipped, 5 is completed
    }

    /**
     * Get the formatted invoice number.
     *
     * @return string
     */
    public function getInvoiceNumberAttribute(): string
    {
        return $this->invoice_prefix . $this->invoice_no;
    }

    /**
     * Check if the order has free shipping.
     *
     * @return bool
     */
    public function hasFreeShipping(): bool
    {
        $shippingTotal = $this->totals()->where('code', 'shipping')->first();
        return $shippingTotal ? $shippingTotal->value == 0 : false;
    }

    /**
     * Get the order tax amount.
     *
     * @return float
     */
    public function getTaxAmount(): float
    {
        $taxTotal = $this->totals()->where('code', 'tax')->first();
        return $taxTotal ? (float)$taxTotal->value : 0.0;
    }
}