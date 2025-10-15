<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_category';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'category_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'image',
        'parent_id',
        'top',
        'column',
        'sort_order',
        'status',
        'date_added',
        'date_modified',
        'code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'top' => 'boolean',
        'status' => 'boolean',
        'date_added' => 'datetime',
        'date_modified' => 'datetime',
    ];

    /**
     * Get the parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get the child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Get the category descriptions.
     */
    public function descriptions()
    {
        return $this->hasMany(CategoryDescription::class, 'category_id');
    }

    /**
     * Get the category paths.
     */
    public function paths()
    {
        return $this->hasMany(CategoryPath::class, 'category_id');
    }

    /**
     * Get the products in this category.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'oc_product_to_category',
            'category_id',
            'product_id'
        );
    }

    /**
     * Get the layout for this category.
     */
    public function layouts()
    {
        return $this->belongsToMany(
            Layout::class,
            'oc_category_to_layout',
            'category_id',
            'layout_id'
        );
    }

    /**
     * Get the stores for this category.
     */
    public function stores()
    {
        return $this->belongsToMany(
            Store::class,
            'oc_category_to_store',
            'category_id',
            'store_id'
        );
    }

    /**
     * Get the filters for this category.
     */
    public function filters()
    {
        return $this->belongsToMany(
            Filter::class,
            'oc_category_filter',
            'category_id',
            'filter_id'
        );
    }

    /**
     * Scope a query to only include active categories.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    /**
     * Scope a query to only include top-level categories.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTopLevel($query)
    {
        return $query->where('parent_id', 0);
    }

    /**
     * Accessor: Get the category name (from description).
     *
     * @return string
     */
    public function getNameAttribute()
    {
        return $this->descriptions->first()?->name ?? 'Unnamed Category';
    }

    /**
     * Accessor: Get the category description.
     *
     * @return string
     */
    public function getDescriptionAttribute()
    {
        return $this->descriptions->first()?->description ?? '';
    }

    /**
     * Accessor: Get the meta title.
     *
     * @return string
     */
    public function getMetaTitleAttribute()
    {
        return $this->descriptions->first()?->meta_title ?? $this->name;
    }

    /**
     * Accessor: Get the meta description.
     *
     * @return string
     */
    public function getMetaDescriptionAttribute()
    {
        return $this->descriptions->first()?->meta_description ?? '';
    }

    /**
     * Accessor: Get the full path (breadcrumb) for the category.
     *
     * @return array
     */
    public function getFullPathAttribute()
    {
        $path = [];
        $current = $this;

        while ($current) {
            $path[] = [
                'id' => $current->category_id,
                'name' => $current->name,
                'image' => $current->image ? url('image/' . $current->image) : null,
            ];
            $current = $current->parent;
        }

        return array_reverse($path);
    }

    /**
     * Accessor: Get direct children (active only).
     *
     * @return \Illuminate\Support\Collection
     */
    public function getActiveChildrenAttribute()
    {
        return $this->children()
            ->active()
            ->with('descriptions')
            ->get()
            ->map(function ($child) {
                return [
                    'id' => $child->category_id,
                    'name' => $child->name,
                    'image' => $child->image ? url('image/' . $child->image) : null,
                    'has_children' => $child->children()->active()->exists(),
                ];
            });
    }

    /**
     * Accessor: Check if category has children.
     *
     * @return bool
     */
    public function getHasChildrenAttribute()
    {
        return $this->children()->active()->exists();
    }

    /**
     * Accessor: Get the main image URL.
     *
     * @return string|null
     */
    public function getMainImageAttribute()
    {
        return $this->image ? url('image/' . $this->image) : null;
    }

    /**
     * Get all products in this category and its subcategories.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllProducts()
    {
        $categoryIds = $this->getAllChildCategoryIds();

        return Product::active()
            ->whereIn('product_id', function ($query) use ($categoryIds) {
                $query->select('product_id')
                    ->from('oc_product_to_category')
                    ->whereIn('category_id', $categoryIds);
            })
            ->with(['descriptions', 'images'])
            ->get();
    }

    /**
     * Get all child category IDs (including self).
     *
     * @return array
     */
    public function getAllChildCategoryIds()
    {
        $ids = [$this->category_id];

        $children = $this->children()->active()->get();
        foreach ($children as $child) {
            $ids = array_merge($ids, $child->getAllChildCategoryIds());
        }

        return $ids;
    }

    /**
     * Get the category tree (hierarchical structure).
     *
     * @param  int  $parentId
     * @param  int  $languageId
     * @return \Illuminate\Support\Collection
     */
    public static function getCategoryTree($parentId = 0, $languageId = 1)
    {
        return self::active()
            ->where('parent_id', $parentId)
            ->with(['descriptions' => function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
            }, 'children' => function ($query) use ($languageId) {
                $query->active()->with(['descriptions' => function ($q) use ($languageId) {
                    $q->where('language_id', $languageId);
                }]);
            }])
            ->orderBy('sort_order', 'ASC')
            ->get()
            ->map(function ($category) use ($languageId) {
                return [
                    'id' => $category->category_id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'image' => $category->main_image,
                    'has_children' => $category->has_children,
                    'children' => $category->children->isNotEmpty() ? self::getCategoryTree($category->category_id, $languageId) : [],
                    'product_count' => $category->products()->active()->count(),
                ];
            });
    }
}