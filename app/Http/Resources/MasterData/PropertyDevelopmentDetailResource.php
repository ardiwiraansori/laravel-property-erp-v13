<?php

namespace App\Http\Resources\MasterData;

use App\Models\PropertyDevelopment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PropertyDevelopment
 *
 * The detail contract adds updated_at without changing the legacy directory
 * resource shape or exposing the company_id ownership column.
 */
final class PropertyDevelopmentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
