<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class OrderStatus extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_order_status';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'order_status_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
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
        'order_status_id',
        'language_id',
        'name',
    ];

    /**
     * Get the default order status name (language_id = 1).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDefaultLanguage(Builder $query)
    {
        return $query->where('language_id', 1);
    }

    /**
     * Get the order status name for a specific language.
     *
     * @param int $languageId
     * @return string|null
     */
    public function getNameForLanguage(int $languageId)
    {
        $status = self::where('order_status_id', $this->order_status_id)
            ->where('language_id', $languageId)
            ->first();

        return $status ? $status->name : $this->name;
    }

    /**
     * Get all available languages for this order status.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAvailableLanguages()
    {
        return self::where('order_status_id', $this->order_status_id)
            ->pluck('language_id', 'name');
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
     * Get common order status IDs.
     *
     * @return array
     */
    public static function getCommonStatusIds(): array
    {
        return [
            'pending' => self::where('name', 'Pending')->value('order_status_id'),
            'processing' => self::where('name', 'Processing')->value('order_status_id'),
            'shipped' => self::where('name', 'Shipped')->value('order_status_id'),
            'delivered' => self::where('name', 'Delivered')->value('order_status_id'),
            'canceled' => self::where('name', 'Canceled')->value('order_status_id'),
            'refunded' => self::where('name', 'Refunded')->value('order_status_id'),
        ];
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
}