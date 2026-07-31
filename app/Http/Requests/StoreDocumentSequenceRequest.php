<?php

namespace App\Http\Requests;

use App\Models\Business\DocumentSequence;
use Illuminate\Support\Facades\Gate;

class StoreDocumentSequenceRequest extends DocumentSequenceRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', DocumentSequence::class);
    }

    public function rules(): array
    {
        return $this->documentSequenceRules();
    }
}
