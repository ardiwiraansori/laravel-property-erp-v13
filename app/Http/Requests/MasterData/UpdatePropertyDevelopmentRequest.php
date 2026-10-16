<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

/** Only a development's display name and availability can be modified. */
final class UpdatePropertyDevelopmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware authorizes the active company's independent update permission.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
            // Snapshot comparisons avoid relying on subsecond updated_at precision.
            'expected_name' => ['required', 'string', 'max:150'],
            'expected_is_active' => ['required', 'boolean'],
        ];
    }
}
