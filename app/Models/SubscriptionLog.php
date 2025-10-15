<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription_log';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'subscription_log_id';

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
        'subscription_log_id' => 'integer',
        'subscription_id' => 'integer',
        'status' => 'boolean',
        'date_added' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'subscription_id',
        'code',
        'description',
        'status',
        'date_added',
    ];

    /**
     * The default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'status' => false,
    ];

    /**
     * Get the subscription that owns the log entry.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    /**
     * Scope to get successful log entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', true);
    }

    /**
     * Scope to get failed log entries.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', false);
    }

    /**
     * Scope to get log entries within a date range.
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
     * Scope to get log entries by code.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $code
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
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
     * Get the status label.
     *
     * @return string
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->status ? 'Success' : 'Failed';
    }

    /**
     * Check if the log entry represents a successful operation.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status;
    }

    /**
     * Check if the log entry represents a failed operation.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return !$this->status;
    }

    /**
     * Get the error message if the operation failed.
     *
     * @return string|null
     */
    public function getErrorMessageAttribute(): ?string
    {
        return $this->isFailed() ? $this->description : null;
    }

    /**
     * Get recent logs for a subscription.
     *
     * @param int $subscriptionId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getRecentForSubscription(int $subscriptionId, int $limit = 10)
    {
        return self::where('subscription_id', $subscriptionId)
            ->orderBy('date_added', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Count failed attempts in a time period.
     *
     * @param int $subscriptionId
     * @param \DateTimeInterface $startDate
     * @return int
     */
    public static function countFailedAttempts(int $subscriptionId, \DateTimeInterface $startDate): int
    {
        return self::where('subscription_id', $subscriptionId)
            ->where('status', false)
            ->where('date_added', '>=', $startDate)
            ->count();
    }
}