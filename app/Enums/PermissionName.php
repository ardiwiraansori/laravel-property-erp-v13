<?php

namespace App\Enums;

enum PermissionName: string
{
    case DashboardView = 'dashboard.view';

    case MasterDataView = 'master-data.view';
    case MasterDataPropertyDevelopmentsView = 'master-data.property-developments.view';
    case MasterDataPropertyDevelopmentsCreate = 'master-data.property-developments.create';

    case CrmView = 'crm.view';
    case SalesView = 'sales.view';
    case AccountingView = 'accounting.view';
    case ReportsView = 'reports.view';
}
