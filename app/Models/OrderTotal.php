<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTotal extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_order_total';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'order_total_id';

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
        'order_id' => 'integer',
        'value' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'extension',
        'code',
        'title',
        'value',
        'sort_order',
    ];

    /**
     * Get the order that owns the total record.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    /**
     * Scope to get sub-total entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSubTotal($query)
    {
        return $query->where('code', 'sub_total');
    }

    /**
     * Scope to get tax entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTax($query)
    {
        return $query->where('code', 'tax');
    }

    /**
     * Scope to get shipping entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeShipping($query)
    {
        return $query->where('code', 'shipping');
    }

    /**
     * Scope to get coupon/discount entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCoupon($query)
    {
        return $query->where('code', 'coupon');
    }

    /**
     * Scope to get the final total entry.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTotal($query)
    {
        return $query->where('code', 'total');
    }

    /**
     * Get the formatted value.
     *
     * @return string
     */
    public function getFormattedValueAttribute(): string
    {
        return number_format($this->value, 2);
    }

    /**
     * Check if this is a discount item (negative value).
     *
     * @return bool
     */
    public function isDiscount(): bool
    {
        return $this->value < 0;
    }

    /**
     * Get the absolute value (for discounts).
     *
     * @return float
     */
    public function getAbsoluteValue(): float
    {
        return abs($this->value);
    }
}