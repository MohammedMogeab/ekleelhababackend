<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionHistory extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription_history';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'subscription_history_id';

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
        'subscription_history_id' => 'integer',
        'subscription_id' => 'integer',
        'subscription_status_id' => 'integer',
        'notify' => 'boolean',
        'date_added' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscription_id',
        'subscription_status_id',
        'notify',
        'comment',
        'date_added',
    ];

    /**
     * Get the subscription that owns the history record.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    /**
     * Get the subscription status for the history record.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(SubscriptionStatus::class, 'subscription_status_id', 'subscription_status_id');
    }

    /**
     * Scope to get only customer-notified history entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotified($query)
    {
        return $query->where('notify', true);
    }

    /**
     * Scope to get history entries within a date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date_added', [$startDate, $endDate]);
    }

    /**
     * Scope to get history entries for a specific status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $statusId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, $statusId)
    {
        return $query->where('subscription_status_id', $statusId);
    }

    /**
     * Get the formatted date.
     *
     * @return string
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->date_added ? $this->date_added->format('Y-m-d H:i:s') : '';
    }

    /**
     * Check if this history entry has a comment.
     *
     * @return bool
     */
    public function hasComment(): bool
    {
        return !empty($this->comment) && trim($this->comment) !== '';
    }

    /**
     * Get the status name attribute.
     *
     * @return string
     */
    public function getStatusNameAttribute(): string
    {
        return $this->status ? $this->status->name : 'Unknown Status';
    }

    /**
     * Check if this status is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status && $this->status->isActive();
    }

    /**
     * Check if this status is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status && $this->status->isPending();
    }

    /**
     * Check if this status is canceled.
     *
     * @return bool
     */
    public function isCanceled(): bool
    {
        return $this->status && $this->status->isCanceled();
    }

    /**
     * Check if this status is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->status && $this->status->isExpired();
    }

    /**
     * Check if this status is suspended.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->status && $this->status->isSuspended();
    }
}