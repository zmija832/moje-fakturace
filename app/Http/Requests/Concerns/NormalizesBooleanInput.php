<?php

namespace App\Http\Requests\Concerns;

trait NormalizesBooleanInput
{
    protected function normalizedBooleanInput(string $field, bool $missingValue = false): mixed
    {
        if (! $this->exists($field)) {
            return $missingValue;
        }

        $value = $this->input($field);

        if (in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [false, 0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return $value;
    }
}
