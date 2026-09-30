import { Link, useLocation } from 'react-router'

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
  useSidebar,
} from '@/components/ui/sidebar'
import { navigationItems } from '@/config/navigation'
import { useAuth } from '@/features/auth/auth-state'
import { hasPermission } from '@/features/auth/permissions'

export function AppSidebar() {
  const location = useLocation()
  const { isMobile, setOpenMobile } = useSidebar()
  const { user } = useAuth()

  if (!user) {
    return null
  }

  const accessibleNavigationItems = navigationItems.filter((item) =>
    hasPermission(user.permissions, item.permission),
  )

  function handleNavigation() {
    if (isMobile) {
      setOpenMobile(false)
    }
  }

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
              {accessibleNavigationItems.map((item) => {
                const isActive =
                  location.pathname === item.href ||
                  (item.href !== '/dashboard' &&
                    location.pathname.startsWith(`${item.href}/`))

                return (
                  <SidebarMenuItem key={item.href}>
                    <SidebarMenuButton
                      render={
                        <Link
                          to={item.href}
                          onClick={handleNavigation}
                          aria-current={isActive ? 'page' : undefined}
                        />
                      }
                      tooltip={
                        item.planned ? `${item.title} · Planned` : item.title
                      }
                      isActive={isActive}
                    >
                      <item.icon />

                      <span>{item.title}</span>

                      {item.planned && (
                        <span className="ml-auto text-[10px] uppercase tracking-wide text-sidebar-foreground/50 group-data-[collapsible=icon]:hidden">
                          Planned
                        </span>
                      )}
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                )
              })}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      <SidebarRail />
    </Sidebar>
  )
}
