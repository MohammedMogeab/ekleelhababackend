<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Marketer extends Model
{
    protected $table = 'oc_marketers';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $casts = [
        'status' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected $fillable = [
        'name',
        'address',
        'phone',
        'phone2',
        'facebook_link',
        'telegram_link',
        'twitter_link',
        'tiktok_link',
        'instagram_link',
        'snap_link',
        'comment',
        'status',
        'others',
        'customer_id',
        'created_at',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}