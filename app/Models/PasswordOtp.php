<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 6-digit email OTPs for the console forgot-password flow (hashed, short-lived). */
class PasswordOtp extends Model
{
    protected $fillable = ['email', 'otp_hash', 'attempts', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
