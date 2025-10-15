<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $table = 'oc_store';
    protected $primaryKey = 'store_id';
    public $timestamps = false;
}