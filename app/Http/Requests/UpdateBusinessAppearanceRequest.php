<?php

namespace App\Http\Requests;

use App\Enums\BusinessThemeColor;
use App\Models\Business\CompanySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdateBusinessAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('updateAny', CompanySetting::class);
    }

    public function rules(): array
    {
        return [
            'theme_color' => ['required', Rule::enum(BusinessThemeColor::class)],
            'business_id' => ['prohibited'],
            'business_uuid' => ['prohibited'],
            'connection' => ['prohibited'],
        ];
    }
}
