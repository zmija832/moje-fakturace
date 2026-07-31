<?php

namespace App\Http\Requests;

use App\Models\Business\Client;
use Illuminate\Foundation\Http\FormRequest;

class ManageClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Client::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
