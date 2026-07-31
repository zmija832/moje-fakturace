<?php

namespace App\Http\Requests;

use App\Models\Business\DocumentSequence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SetDefaultDocumentSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', DocumentSequence::class);
    }

    public function rules(): array
    {
        return [];
    }
}
