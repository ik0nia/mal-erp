<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UptimeProbe extends Model
{
    public $timestamps = false;

    protected $fillable = ['target', 'is_up', 'response_ms', 'error', 'checked_at'];

    protected $casts = [
        'is_up'       => 'boolean',
        'response_ms' => 'integer',
        'checked_at'  => 'datetime',
    ];

    public const TARGETS = [
        'app'    => 'Aplicație (ERP + bază de date)',
        'bridge' => 'WinMentor API (bridge)',
    ];
}
