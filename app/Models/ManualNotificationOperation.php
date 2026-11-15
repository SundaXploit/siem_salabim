<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualNotificationOperation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'alert_id', 'user_id', 'payload_hash'];
}
