<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'oc_customer'; // OpenCart customer table

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'customer_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    // protected $fillable = [
    //     'customer_group_id',
    //     'store_id',
    //     'firstname',
    //     'lastname',
    //     'email',
    //     'telephone',
    //     'fax',
    //     'password',
    //     'salt', // OpenCart uses salted passwords
    //     'cart',
    //     'wishlist',
    //     'newsletter',
    //     'address_id',
    //     'custom_field',
    //     'ip',
    //     'status',
    //     'safe',
    //     'token',
    //     'code',
    //     'date_added',
    //     'language_id',
    // ];


    protected $attributes = [
        'customer_group_id' => 1,
        'store_id' => 0,
        'language_id' => 1,
        'fax' => '',
        'cart' => '',
        'wishlist' => '',
        'newsletter' => 0,
        'address_id' => 0,
        'custom_field' => '[]',
        'ip' => '',
        'status' => 1,
        'safe' => 0,
        'token' => '',
        'code' => '',
        'verify_code' => '',
        'status_code' => 0,
        'delete_status' => 0,
        'from_come' => '',
        'is_marketer' => 0,
    ];


    protected $fillable = [
        'customer_group_id',
        'store_id',
        'language_id',
        'firstname',
        'lastname',
        'email',
        'telephone',
        'fax',
        'password',
        'salt', 
        'cart',
        'wishlist',
        'newsletter',
        'address_id',
        'custom_field',
        'ip',
        'status',
        'safe',
        'token',
        'code',
        'verify_code',
        'status_code',
        'delete_status',
        'from_come',
        'is_marketer',
        'date_added',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'salt',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_added' => 'datetime',
        'custom_field' => 'array', // if stored as JSON
    ];

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return trim("{$this->firstname} {$this->lastname}");
    }

    /**
     * Check if user is admin (you may need to define logic)
     * Example: if customer_group_id = 1 is admin, or use a meta field.
     *
     * @return bool
     */
    public function getIsAdminAttribute()
    {
        // Customize this logic based on your OpenCart setup
        // Option 1: Assume customer_group_id = 1 is admin
        // Option 2: Check a custom_field or separate table like oc_user

        return $this->customer_group_id == 0;
        // OR if you have oc_user table with permission:
        // return $this->adminUser !== null;
    }

    /**
     * Relationship: Admin user (if you have oc_user table for backend users)
     * Optional — only if you want to link backend admin users
     */
    // public function adminUser()
    // {
    //     return $this->hasOne(AdminUser::class, 'email', 'email');
    // }

    /**
     * Override password check to handle OpenCart’s salted passwords.
     *
     * OpenCart 3 uses: sha1(salt + sha1(salt + password))
     *
     * @param  string  $password
     * @return bool
     */
    public function validatePassword(string $password): bool
    {

        $hashed = sha1($this->salt . sha1($this->salt . $password));
    
        return hash_equals($this->password, $hashed);
    }
    // function isPasswordValid($inputPassword) {
    //     $salt =$this->salt ;
    //     $computedHash = sha1($salt . sha1($salt . sha1($inputPassword)));
    //     return $computedHash === $this->password;
    // }
    /**
     * Mutator: Hash password using OpenCart’s algorithm before saving.
     *
     * @param  string  $password
     */
    public function setPasswordAttribute($passwordWithSalt)
    {   
        $salt = $passwordWithSalt['salt'];
        $password = $passwordWithSalt['password'];
    
        $this->attributes['password'] = sha1($salt . sha1($salt . $password));
    }

    /**
     * Find user by email for Sanctum/Laravel Auth.
     *
     * @param  string  $email
     * @return \App\Models\User|null
     */
    public function findForPassport($email)
    {
        return $this->where('email', $email)->where('status', 1)->first();
    }

    /**
     * Scope: Only active users
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }


    public function tokens()
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }
}