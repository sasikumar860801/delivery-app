<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Vendor extends Authenticatable
{
    use HasFactory, HasApiTokens;

    protected $table = 'vendors'; // your table name

    protected $fillable = [
        'name',
        'email',
        'phone',
        'status',
        // add any other fields you allow mass assignment
    ];

    protected $hidden = [
        'password', // if any
    ];

    /**
     * Optional: if you want to define a relationship to vendor_auth
     */
  
}
