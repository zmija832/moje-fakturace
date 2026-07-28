<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class CentralModel extends Model
{
    protected $connection = 'central';
}
