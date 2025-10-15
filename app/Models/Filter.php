<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Filter extends Model
{
    protected $table = 'oc_filter';
    protected $primaryKey = 'filter_id';
    public $timestamps = false;
}