<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Illuminate\Http\Request;
use LogicException;

final class CurrentCompanyContext
{
    public const ATTRIBUTE = 'resolved_company';

    public function __construct(private readonly Request $request)
    {
    }

    public function company(): Company
    {
        $company = $this->request->attributes->get(self::ATTRIBUTE);

        if (! $company instanceof Company) {
            throw new LogicException('Company context has not been resolved.');
        }

        return $company;
    }

    public function id(): int
    {
        return (int) $this->company()->getKey();
    }
}
