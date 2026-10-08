import axios from 'axios'
import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'

import { useAuth } from '@/features/auth/auth-state'
import { CompanyContext, type Company, type CompanyAccess } from '@/features/company/company-state'
import { api, setTenantCompanyId } from '@/lib/api'

const key = 'property-erp-active-company'
type Collection<T> = { data: T }

function getMessage(error: unknown) {
  if (axios.isAxiosError(error)) return error.response?.data?.message ?? 'Unable to load company access.'
  return 'Unable to load company access.'
}

export function CompanyProvider({ children }: { children: ReactNode }) {
  const { user, checkingSession } = useAuth()
  const [companies, setCompanies] = useState<Company[]>([])
  const [activeCompany, setActiveCompany] = useState<CompanyAccess | null>(null)
  const [loading, setLoading] = useState(true)
  const [switching, setSwitching] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [revision, setRevision] = useState(0)

  const userId = user?.id

  useEffect(() => {
    let current = true
    setTenantCompanyId(null)

    async function initialize() {
      // Start after the effect has returned. React state updates happen in the
      // async continuation, rather than synchronously inside the effect.
      await Promise.resolve()
      if (!current) return

      if (checkingSession || !userId) {
        setCompanies([])
        setActiveCompany(null)
        setError(null)
        setLoading(checkingSession)
        if (!checkingSession) sessionStorage.removeItem(key)
        return
      }

      setLoading(true)
      setActiveCompany(null)
      setError(null)
      try {
        const list = (await api.get<Collection<Company[]>>('/api/companies')).data.data
        if (!current) return
        setCompanies(list)
        const previous = Number(sessionStorage.getItem(key))
        const selected = list.find(item => item.id === previous) ?? list[0]
        if (!selected) {
          sessionStorage.removeItem(key)
          return
        }
        const access = (await api.get<Collection<CompanyAccess>>('/api/company-context', {
          headers: { 'X-Company-ID': String(selected.id) },
        })).data.data
        if (!current) return
        if (access.id !== selected.id || !Array.isArray(access.permissions) || !Array.isArray(access.roles)) {
          throw new Error('Invalid company authorization response.')
        }
        sessionStorage.setItem(key, String(selected.id))
        setTenantCompanyId(selected.id)
        setActiveCompany(access)
      } catch (cause) {
        if (current) {
          setTenantCompanyId(null)
          setError(getMessage(cause))
        }
      } finally {
        if (current) setLoading(false)
      }
    }
    void initialize()
    return () => { current = false }
  }, [checkingSession, userId, revision])

  const selectCompany = useCallback(async (id: number) => {
    if (switching || !companies.some(item => item.id === id)) return
    if (activeCompany?.id === id) return
    setSwitching(true)
    setError(null)
    try {
      const access = (await api.get<Collection<CompanyAccess>>('/api/company-context', {
        headers: { 'X-Company-ID': String(id) },
      })).data.data
      if (access.id !== id || !Array.isArray(access.roles) || !Array.isArray(access.permissions)) {
        throw new Error('Invalid company authorization response.')
      }
      // A full reload discards every tenant-specific component state and in-flight request.
      // Persist only the selected membership ID, never the permissions.
      sessionStorage.setItem(key, String(id))
      setTenantCompanyId(null)
      window.location.assign('/dashboard')
    } catch (cause) {
      setError(getMessage(cause))
      setSwitching(false)
    }
  }, [activeCompany?.id, companies, switching])

  const retry = useCallback(() => setRevision(value => value + 1), [])

  return <CompanyContext.Provider value={{ companies, activeCompany, loading, switching, error, selectCompany, retry }}>
    {children}
  </CompanyContext.Provider>
}
