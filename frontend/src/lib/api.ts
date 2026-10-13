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
  // Platform APIs may never inherit a tenant header, even during navigation.
  const path = (config.url ?? '').split('?')[0]
  const platformRoute = path === '/api/platform' || path.startsWith('/api/platform/')
  const globalRoute = ['/api/user', '/api/login', '/api/logout', '/api/companies', '/api/health']
    .includes(path)
  const tenantRoute = path.startsWith('/api/') && !platformRoute && !globalRoute

  if (tenantRoute && tenantCompanyId !== null && !config.headers.has('X-Company-ID')) {
    config.headers.set('X-Company-ID', String(tenantCompanyId))
  }
  return config
})

// A session may expire while the user is viewing a directory.
// Never fall back to tenant permissions when a platform MFA proof expires.
api.interceptors.response.use(response => response, error => {
  if (axios.isAxiosError(error) && error.response?.status === 428) {
    const path = (error.config?.url ?? '').split('?')[0]
    if (path.startsWith('/api/platform/') && !path.startsWith('/api/platform/mfa/') &&
      window.location.pathname.startsWith('/platform') && window.location.pathname !== '/platform/mfa') {
      window.location.replace('/platform/mfa')
    }
  }
  return Promise.reject(error)
})
