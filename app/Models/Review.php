<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $table = 'oc_review';
    protected $primaryKey = 'review_id';
    public $timestamps = false;

    protected $casts = [
        'rating' => 'integer',
        'status' => 'boolean',
        'date_added' => 'datetime',
        'date_modified' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 1);
    }
}