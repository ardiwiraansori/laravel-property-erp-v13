import { Building2, Check, ChevronsUpDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { useCompany } from '@/features/company/company-state'

export function CompanySwitcher() {
  const { companies, activeCompany, switching, selectCompany } = useCompany()
  if (!activeCompany) return null
  return <DropdownMenu>
    <DropdownMenuTrigger render={<Button variant="outline" size="sm" disabled={switching} className="max-w-56 gap-2" />}>
      <Building2 className="size-4 shrink-0" />
      <span className="truncate">{activeCompany.name}</span>
      <ChevronsUpDown className="size-3 shrink-0 opacity-60" />
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" className="min-w-56">
      <DropdownMenuLabel>Active company</DropdownMenuLabel>
      {companies.map(company => <DropdownMenuItem key={company.id} disabled={switching} onClick={() => void selectCompany(company.id)}>
        <span className="min-w-0 flex-1 truncate">{company.name} <span className="text-muted-foreground">({company.code})</span></span>
        {company.id === activeCompany.id && <Check className="size-4" />}
      </DropdownMenuItem>)}
    </DropdownMenuContent>
  </DropdownMenu>
}
