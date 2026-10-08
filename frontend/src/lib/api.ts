import axios from 'axios'

let tenantCompanyId: number | null = null
export function setTenantCompanyId(id: number | null) {
  tenantCompanyId = id
}

export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use(config => {
  // Authentication and company discovery must not inherit a tenant header.
  const url = config.url ?? ''
  const tenantRoute = url.startsWith('/api/') && !['/api/user', '/api/login', '/api/logout', '/api/companies', '/api/health'].includes(url.split('?')[0])
  if (tenantRoute && tenantCompanyId !== null && !config.headers.has('X-Company-ID')) {
    config.headers.set('X-Company-ID', String(tenantCompanyId))
  }
  return config
})
