<?php

namespace App\Http\Requests;

use App\Models\Business\Client;

class UpdateClientRequest extends ClientRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateAny', Client::class) ?? false;
    }

    public function rules(): array
    {
        return $this->clientRules();
    }
}
