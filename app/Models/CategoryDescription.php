<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryDescription extends Model
{
    protected $table = 'oc_category_description';
    protected $primaryKey = ['category_id', 'language_id'];
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'meta_title',
        'meta_description',
        'meta_keyword',
    ];
}