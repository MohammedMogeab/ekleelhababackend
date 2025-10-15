<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $table = 'oc_country';
    protected $primaryKey = 'country_id';
    public $timestamps = false;

    protected $casts = [
        'postcode_required' => 'boolean',
        'status' => 'boolean',
    ];

    public function addresses()
    {
        return $this->hasMany(Address::class, 'country_id');
    }

    public function zones()
    {
        return $this->hasMany(Zone::class, 'country_id');
    }
}