<?php

namespace App\Http\Requests;

use App\Models\Business\Client;

class StoreClientRequest extends ClientRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Client::class) ?? false;
    }

    public function rules(): array
    {
        return $this->clientRules();
    }
}
