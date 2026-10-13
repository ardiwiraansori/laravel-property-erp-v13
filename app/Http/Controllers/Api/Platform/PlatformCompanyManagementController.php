<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Platform\PlatformMutationAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PlatformCompanyManagementController extends Controller
{
    public function __construct(private readonly PlatformMutationAudit $audit)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,29}$/', Rule::unique('companies', 'code')],
            'name' => ['required', 'string', 'min:2', 'max:150'],
        ]);

        $company = DB::transaction(function () use ($request, $data): Company {
            $company = Company::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => true,
            ]);
            $this->audit->record($request, 'platform.company.create', $company->id, $company->id);

            return $company;
        });

        return response()->json(['data' => $this->view($company)], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'At least one field is required.'], 422);
        }

        DB::transaction(function () use ($request, $data, $company): void {
            // The permanent company code is intentionally not mutable.
            $company->update($data);
            $this->audit->record($request, 'platform.company.update', $company->id, $company->id);
        });

        return response()->json(['data' => $this->view($company->refresh())]);
    }

    private function view(Company $company): array
    {
        return [
            'id' => $company->id,
            'code' => $company->code,
            'name' => $company->name,
            'is_active' => $company->is_active,
        ];
    }
}
