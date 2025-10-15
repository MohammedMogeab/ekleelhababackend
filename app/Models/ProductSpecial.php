<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecial extends Model
{
    protected $table = 'oc_product_special';
    protected $primaryKey = 'product_special_id';
    public $timestamps = false;

    protected $casts = [
        'price' => 'float',
        'date_start' => 'date',
        'date_end' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}