<?php

namespace App\Http\Requests;

use App\Models\Business\DocumentSequence;
use Illuminate\Support\Facades\Gate;

class UpdateDocumentSequenceRequest extends DocumentSequenceRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', DocumentSequence::class);
    }

    public function rules(): array
    {
        return $this->documentSequenceRules();
    }
}
