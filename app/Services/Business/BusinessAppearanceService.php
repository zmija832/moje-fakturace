<?php

namespace App\Services\Business;

use App\Domain\BusinessContext\ActiveBusinessContext;
use App\Enums\BusinessThemeColor;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

final class BusinessAppearanceService
{
    public function __construct(private readonly ActiveBusinessContext $context) {}

    public function save(BusinessThemeColor $color): Business
    {
        $activeBusiness = $this->context->requireBusiness();

        $business = DB::connection('central')->transaction(function () use ($activeBusiness, $color): Business {
            $business = Business::query()->whereKey($activeBusiness->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $business->forceFill(['theme_color' => $color->value])->save();

            return $business->refresh();
        }, 3);

        $this->context->set($business);

        return $business;
    }
}
