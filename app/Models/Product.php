<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'oc_product';
    protected $primaryKey = 'product_id';
    public $timestamps = false;

    protected $casts = [
        'price' => 'float',
        'quantity' => 'integer',
        'status' => 'boolean',
        'date_added' => 'datetime',
        'date_modified' => 'datetime',
    ];

    // Relationships
    public function descriptions()
    {
        return $this->hasMany(ProductDescription::class, 'product_id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'oc_product_to_category',
            'product_id',
            'category_id'
        )->withTimestamps();
    }

    public function specials()
    {
        return $this->hasMany(ProductSpecial::class, 'product_id');
    }

    public function relatedProducts()
    {
        return $this->belongsToMany(
            Product::class,
            'oc_product_related',
            'product_id',
            'related_id',
            'product_id',
            'product_id'
        );
    }

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'product_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 1)->where('quantity', '>', 0);
    }

    public function scopeAvailable($query)
    {
        return $query->where('quantity', '>', 0);
    }

    // Accessors
    public function getFinalPriceAttribute()
    {
        $special = $this->specials()
            ->where('date_start', '<=', now())
            ->where(function ($q) {
                $q->where('date_end', '>=', now())
                  ->orWhere('date_end', '0000-00-00');
            })
            ->orderBy('priority', 'ASC')
            ->first();

        return $special ? (float) $special->price : (float) $this->price;
    }

    public function getIsOnSaleAttribute()
    {
        return $this->final_price < $this->price;
    }

    public function getMainImageAttribute()
    {
        return $this->image ? $this->image : null;
    }

    public function getGalleryAttribute()
    {
        $images = $this->images->pluck('image')->map(fn($img) =>  $img);
        if ($this->image) {
            $images->prepend($this->main_image);
        }
        return $images->unique()->values();
    }



    
}