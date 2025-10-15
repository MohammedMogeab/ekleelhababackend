<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionStatus extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_subscription_status';

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
     * @var string
     */
    protected $primaryKey = 'subscription_status_id';

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
        'subscription_status_id',
        'language_id',
        'name',
    ];

    /**
     * Get the subscription status name for a specific language.
     *
     * @param int $languageId
     * @return string|null
     */
    public static function getNameForLanguage(int $subscriptionStatusId, int $languageId)
    {
        $status = self::where('subscription_status_id', $subscriptionStatusId)
            ->where('language_id', $languageId)
            ->first();

        return $status ? $status->name : null;
    }

    /**
     * Get all available languages for a specific status.
     *
     * @param int $subscriptionStatusId
     * @return \Illuminate\Support\Collection
     */
    public static function getAvailableLanguages(int $subscriptionStatusId)
    {
        return self::where('subscription_status_id', $subscriptionStatusId)
            ->pluck('name', 'language_id');
    }

    /**
     * Scope to get a specific status by name.
     *
     * @param Builder $query
     * @param string $name
     * @return Builder
     */
    public function scopeWithName(Builder $query, string $name)
    {
        return $query->where('name', 'like', '%' . $name . '%');
    }

    /**
     * Scope to get default language status (language_id = 1).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDefaultLanguage(Builder $query)
    {
        return $query->where('language_id', 1);
    }

    /**
     * Get common subscription status IDs.
     *
     * @return array
     */
    public static function getCommonStatusIds(): array
    {
        $statuses = [
            'pending' => self::where('name', 'Pending')->value('subscription_status_id'),
            'active' => self::where('name', 'Active')->value('subscription_status_id'),
            'suspended' => self::where('name', 'Suspended')->value('subscription_status_id'),
            'canceled' => self::where('name', 'like', '%canceled%')->value('subscription_status_id'),
            'expired' => self::where('name', 'Expired')->value('subscription_status_id'),
        ];

        // Filter out null values
        return array_filter($statuses, function ($value) {
            return $value !== null;
        });
    }

    /**
     * Get the status name attribute.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->attributes['name'] ?? 'Unknown Status';
    }

    /**
     * Check if this status is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return strtolower($this->name) === 'active';
    }

    /**
     * Check if this status is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return strtolower($this->name) === 'pending';
    }

    /**
     * Check if this status is canceled.
     *
     * @return bool
     */
    public function isCanceled(): bool
    {
        return stripos($this->name, 'canceled') !== false || 
               stripos($this->name, 'cancelled') !== false;
    }

    /**
     * Check if this status is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return strtolower($this->name) === 'expired';
    }

    /**
     * Check if this status is suspended.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return strtolower($this->name) === 'suspended';
    }
}