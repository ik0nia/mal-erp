<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinmentorArticleSnapshot extends Model
{
    protected $table = 'winmentor_articles_snapshot';

    protected $fillable = [
        'cod_extern',
        'denumire',
    ];
}
