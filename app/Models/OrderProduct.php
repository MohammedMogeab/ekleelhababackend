<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProduct extends Model
{
    protected $table = 'oc_order_product';
    protected $primaryKey = 'order_product_id';
    public $timestamps = false;

    protected $casts = [
        'price' => 'float',
        'total' => 'float',
        'tax' => 'float',
        'reward' => 'integer',
    ];

    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'model',
        'quantity',
        'price',
        'total',
        'tax',
        'reward',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}