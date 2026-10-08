import type { ReactNode } from 'react'

import { useCompany } from '@/features/company/company-state'
import { hasPermission, type PermissionName } from '@/features/auth/permissions'
import { ForbiddenPage } from '@/pages/forbidden-page'

type PermissionRouteProps = {
  permission: PermissionName
  children: ReactNode
}

export function PermissionRoute({
  permission,
  children,
}: PermissionRouteProps) {
  const { activeCompany } = useCompany()

  if (!activeCompany) {
    return null
  }

  if (!hasPermission(activeCompany.permissions, permission)) {
    return <ForbiddenPage />
  }

  return children
}
