<?php

namespace Tests\Support\Models;

use App\Models\Business\BusinessModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name'])]
class TestBusinessRecord extends BusinessModel
{
    public $timestamps = false;

    protected $table = 'business_model_test_records';
}
