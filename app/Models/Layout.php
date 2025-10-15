<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Layout extends Model
{
    protected $table = 'oc_layout';
    protected $primaryKey = 'layout_id';
    public $timestamps = false;
}