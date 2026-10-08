import { createContext, useContext } from 'react'

export type Company = { id: number; code: string; name: string }
export type CompanyAccess = Company & { roles: string[]; permissions: string[] }
export type CompanyContextValue = {
  companies: Company[]
  activeCompany: CompanyAccess | null
  loading: boolean
  switching: boolean
  error: string | null
  selectCompany: (id: number) => Promise<void>
  retry: () => void
}
export const CompanyContext = createContext<CompanyContextValue | null>(null)
export function useCompany() {
  const value = useContext(CompanyContext)
  if (!value) throw new Error('useCompany must be used inside CompanyProvider')
  return value
}
