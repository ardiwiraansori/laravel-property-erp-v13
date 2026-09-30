import {
  BarChart3,
  Building2,
  Calculator,
  LayoutDashboard,
  Settings2,
  Users,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { permissions, type PermissionName } from '@/features/auth/permissions'

export type NavigationItem = {
  title: string
  href: string
  icon: LucideIcon
  permission: PermissionName
  planned?: boolean
}

export const navigationItems: NavigationItem[] = [
  {
    title: 'Dashboard',
    href: '/dashboard',
    icon: LayoutDashboard,
    permission: permissions.dashboardView,
  },
  {
    title: 'Master Data',
    href: '/master-data',
    icon: Building2,
    permission: permissions.masterDataView,
    planned: true,
  },
  {
    title: 'CRM',
    href: '/crm',
    icon: Users,
    permission: permissions.crmView,
    planned: true,
  },
  {
    title: 'Sales',
    href: '/sales',
    icon: BarChart3,
    permission: permissions.salesView,
    planned: true,
  },
  {
    title: 'Accounting',
    href: '/accounting',
    icon: Calculator,
    permission: permissions.accountingView,
    planned: true,
  },
  {
    title: 'Reports',
    href: '/reports',
    icon: Settings2,
    permission: permissions.reportsView,
    planned: true,
  },
]
