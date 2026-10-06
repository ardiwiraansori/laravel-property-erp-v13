<?php

namespace App\Http\Requests\MasterData;

use App\Models\PropertyDevelopment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePropertyDevelopmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        $name = $this->input('name');

        $this->merge([
            'code' => is_string($code)
                ? Str::upper(trim($code))
                : $code,
            'name' => is_string($name)
                ? trim($name)
                : $name,
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'alpha_dash:ascii',
                Rule::unique(PropertyDevelopment::class, 'code'),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
        ];
    }
}
