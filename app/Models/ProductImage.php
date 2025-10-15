<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $table = 'oc_product_image';
    protected $primaryKey = 'product_image_id';
    public $timestamps = false;

    protected $fillable = ['image', 'sort_order'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}