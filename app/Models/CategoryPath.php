<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryPath extends Model
{
    protected $table = 'oc_category_path';
    protected $primaryKey = ['category_id', 'path_id'];
    public $incrementing = false;
    public $timestamps = false;

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}