<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    protected $table = 'oc_customer_wishlist';
    protected $primaryKey = ['customer_id', 'product_id'];
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'product_id',
        'date_added',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    // Override to allow composite key deletion
    public function delete()
    {
        return $this->newQuery()->where('customer_id', $this->customer_id)
            ->where('product_id', $this->product_id)
            ->delete();
    }
}