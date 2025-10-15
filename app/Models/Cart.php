<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cart extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_cart';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'cart_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'api_id',
        'customer_id',
        'session_id',
        'product_id',
        'recurring_id',
        'option',
        'quantity',
        'date_added',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'option' => 'array',
        'quantity' => 'integer',
        'date_added' => 'datetime',
    ];

    /**
     * Get the customer that owns the cart item.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the product for the cart item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Scope a query to only include items for a specific customer.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $customerId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope a query to only include items for a specific session.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $sessionId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope a query to only include items for authenticated users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAuthenticated($query)
    {
        return $query->where('customer_id', '>', 0)->where('session_id', '0');
    }

    /**
     * Scope a query to only include items for guest users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGuest($query)
    {
        return $query->where('customer_id', 0)->where('session_id', '!=', '0');
    }

    /**
     * Get the final price for the cart item (considering specials).
     *
     * @return float
     */
    public function getFinalPriceAttribute()
    {
        if (!$this->product) {
            return 0.0;
        }

        return $this->product->final_price;
    }

    /**
     * Get the subtotal for the cart item.
     *
     * @return float
     */
    public function getSubtotalAttribute()
    {
        return $this->final_price * $this->quantity;
    }

    /**
     * Check if the product is in stock for the requested quantity.
     *
     * @return bool
     */
    public function getInStockAttribute()
    {
        if (!$this->product) {
            return false;
        }

        return $this->product->quantity >= $this->quantity;
    }

    /**
     * Get the maximum quantity available for this product.
     *
     * @return int
     */
    public function getMaxQuantityAttribute()
    {
        return $this->product ? $this->product->quantity : 0;
    }
}