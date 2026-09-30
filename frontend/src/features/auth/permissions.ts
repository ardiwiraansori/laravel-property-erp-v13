export const permissions = {
  dashboardView: 'dashboard.view',
  masterDataView: 'master-data.view',
  crmView: 'crm.view',
  salesView: 'sales.view',
  accountingView: 'accounting.view',
  reportsView: 'reports.view',
} as const

export type PermissionName = (typeof permissions)[keyof typeof permissions]

export function hasPermission(
  userPermissions: readonly string[],
  permission: PermissionName,
): boolean {
  return userPermissions.includes(permission)
}
