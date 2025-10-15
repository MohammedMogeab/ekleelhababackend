<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDescription extends Model
{
    protected $table = 'oc_product_description';
    protected $primaryKey = ['product_id', 'language_id'];
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['name', 'description', 'tag', 'meta_title', 'meta_description', 'meta_keyword'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}