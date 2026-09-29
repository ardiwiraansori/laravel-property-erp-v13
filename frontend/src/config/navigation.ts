import {
  BarChart3,
  Building2,
  Calculator,
  LayoutDashboard,
  Settings2,
  Users,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

export type NavigationItem = {
  title: string
  href: string
  icon: LucideIcon
  planned?: boolean
}

export const navigationItems: NavigationItem[] = [
  {
    title: 'Dashboard',
    href: '/dashboard',
    icon: LayoutDashboard,
  },
  {
    title: 'Master Data',
    href: '/master-data',
    icon: Building2,
    planned: true,
  },
  {
    title: 'CRM',
    href: '/crm',
    icon: Users,
    planned: true,
  },
  {
    title: 'Sales',
    href: '/sales',
    icon: BarChart3,
    planned: true,
  },
  {
    title: 'Accounting',
    href: '/accounting',
    icon: Calculator,
    planned: true,
  },
  {
    title: 'Reports',
    href: '/reports',
    icon: Settings2,
    planned: true,
  },
]