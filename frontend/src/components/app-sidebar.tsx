import {
  BarChart3,
  Building2,
  Calculator,
  LayoutDashboard,
  Settings2,
  Users,
} from 'lucide-react'

import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
} from '@/components/ui/sidebar'

const navigationItems = [
  {
    title: 'Dashboard',
    icon: LayoutDashboard,
    active: true,
    available: true,
  },
  {
    title: 'Master Data',
    icon: Building2,
    available: false,
  },
  {
    title: 'CRM',
    icon: Users,
    available: false,
  },
  {
    title: 'Sales',
    icon: BarChart3,
    available: false,
  },
  {
    title: 'Accounting',
    icon: Calculator,
    available: false,
  },
  {
    title: 'Reports',
    icon: Settings2,
    available: false,
  },
]

export function AppSidebar() {
  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton
              size="lg"
              tooltip="Property ERP"
              className="cursor-default"
            >
              <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary text-sm font-semibold text-sidebar-primary-foreground">
                PE
              </div>

              <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
                <span className="truncate font-semibold">Property ERP</span>

                <span className="truncate text-xs text-sidebar-foreground/70">
                  Management System
                </span>
              </div>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>Workspace</SidebarGroupLabel>

          <SidebarGroupContent>
            <SidebarMenu>
              {navigationItems.map((item) => (
                <SidebarMenuItem key={item.title}>
                  <SidebarMenuButton
                    tooltip={
                      item.available ? item.title : `${item.title} · Planned`
                    }
                    isActive={item.active}
                    className={
                      !item.available ? 'cursor-default opacity-50' : undefined
                    }
                  >
                    <item.icon />

                    <span>{item.title}</span>

                    {!item.available && (
                      <span className="ml-auto text-[10px] uppercase tracking-wide text-sidebar-foreground/50 group-data-[collapsible=icon]:hidden">
                        Planned
                      </span>
                    )}
                  </SidebarMenuButton>
                </SidebarMenuItem>
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarRail />
    </Sidebar>
  )
}
