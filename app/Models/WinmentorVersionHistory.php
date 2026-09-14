<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinmentorVersionHistory extends Model
{
    protected $table = 'winmentor_version_history';

    protected $fillable = [
        'component',
        'version',
        'previous_version',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
        ];
    }

    public const COMPONENTS = [
        'mentor'       => 'WinMentor',
        'docimpserver' => 'DocImpServer',
        'mentorapi'    => 'MentorAPI',
    ];
}
