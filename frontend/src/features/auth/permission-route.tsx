import type { ReactNode } from 'react'

import { useAuth } from '@/features/auth/auth-state'
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
  const { user } = useAuth()

  if (!user) {
    return null
  }

  if (!hasPermission(user.permissions, permission)) {
    return <ForbiddenPage />
  }

  return children
}
