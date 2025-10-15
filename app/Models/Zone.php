<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    protected $table = 'oc_zone';
    protected $primaryKey = 'zone_id';
    public $timestamps = false;

    protected $casts = [
        'status' => 'boolean',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function addresses()
    {
        return $this->hasMany(Address::class, 'zone_id');
    }
}