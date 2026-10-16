import type { DirectoryStatus } from '@/features/master-data/property-developments/property-development-api'

// URL state is purely navigational; server-side tenant and permissions are
// always resolved from the authenticated company context, never from URL data.
export type DirectoryPageSize = 10 | 25 | 50

export type DirectoryUrlState = {
  search: string
  status: DirectoryStatus
  page: number
  perPage: DirectoryPageSize
}

export const DEFAULT_DIRECTORY_STATE: Readonly<DirectoryUrlState> = {
  search: '',
  status: 'all',
  page: 1,
  perPage: 10,
}

// Explicit bounds prevent oversized/unexpected query input from reaching the
// API. Unknown keys (especially company_id) are never copied into requests.
export function readDirectoryUrlState(params: URLSearchParams): DirectoryUrlState {
  const rawSearch = params.get('search') ?? ''
  const search = rawSearch.length <= 100 ? rawSearch.trim() : ''
  const rawStatus = params.get('status')
  const status: DirectoryStatus = rawStatus === 'active' || rawStatus === 'inactive'
    ? rawStatus
    : 'all'
  const rawPage = params.get('page') ?? ''
  const page = /^[1-9][0-9]{0,5}$/.test(rawPage) ? Number(rawPage) : 1
  const rawPageSize = params.get('per_page')
  const perPage: DirectoryPageSize = rawPageSize === '25' ? 25 : rawPageSize === '50' ? 50 : 10

  return { search, status, page, perPage }
}

export function directoryUrlParams(state: DirectoryUrlState): URLSearchParams {
  const params = new URLSearchParams()
  const search = state.search.trim()
  if (search.length > 0 && search.length <= 100) params.set('search', search)
  if (state.status === 'active' || state.status === 'inactive') params.set('status', state.status)
  if (Number.isSafeInteger(state.page) && state.page > 1 && state.page <= 999999) {
    params.set('page', String(state.page))
  }
  if (state.perPage === 25 || state.perPage === 50) params.set('per_page', String(state.perPage))
  return params
}

export function directoryUrlSearch(state: DirectoryUrlState): string {
  const query = directoryUrlParams(state).toString()
  return query === '' ? '' : `?${query}`
}
