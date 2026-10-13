import { Building2, LayoutDashboard, ShieldCheck, Users } from 'lucide-react'
import { Link, useLocation } from 'react-router'

import {
  Sidebar, SidebarContent, SidebarGroup, SidebarGroupContent, SidebarGroupLabel,
  SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarRail, useSidebar,
} from '@/components/ui/sidebar'

const links = [
  { title: 'Overview', href: '/platform', icon: LayoutDashboard },
  { title: 'Companies', href: '/platform/companies', icon: Building2 },
  { title: 'Users', href: '/platform/users', icon: Users },
]

export function PlatformSidebar() {
  const { pathname } = useLocation()
  const { isMobile, setOpenMobile } = useSidebar()

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <SidebarMenu><SidebarMenuItem>
          <SidebarMenuButton size="lg" tooltip="Platform Administration" className="cursor-default">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
              <ShieldCheck className="size-4" />
            </div>
            <div className="grid flex-1 text-left text-sm leading-tight group-data-[collapsible=icon]:hidden">
              <span className="truncate font-semibold">Property ERP</span>
              <span className="truncate text-xs text-sidebar-foreground/70">Platform Administration</span>
            </div>
          </SidebarMenuButton>
        </SidebarMenuItem></SidebarMenu>
      </SidebarHeader>
      <SidebarContent><SidebarGroup>
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarGroupContent><SidebarMenu>
          {links.map(item => {
            const active = item.href === '/platform' ? pathname === '/platform' :
              pathname === item.href || pathname.startsWith(`${item.href}/`)
            return <SidebarMenuItem key={item.href}>
              <SidebarMenuButton
                render={<Link to={item.href} onClick={() => { if (isMobile) setOpenMobile(false) }} aria-current={active ? 'page' : undefined} />}
                tooltip={item.title} isActive={active}>
                <item.icon /><span>{item.title}</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          })}
        </SidebarMenu></SidebarGroupContent>
      </SidebarGroup></SidebarContent>
      <SidebarRail />
    </Sidebar>
  )
}
