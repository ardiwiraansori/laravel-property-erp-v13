import type { DirectorySort, DirectoryStatus } from '@/features/master-data/property-developments/property-development-api'

// URL state is purely navigational; server-side tenant and permissions are
// always resolved from the authenticated company context, never from URL data.
export type DirectoryPageSize = 10 | 25 | 50

export type DirectoryUrlState = {
  search: string
  status: DirectoryStatus
  page: number
  perPage: DirectoryPageSize
  sort: DirectorySort
  createdFrom: string
  createdTo: string
}

export const DEFAULT_DIRECTORY_STATE: Readonly<DirectoryUrlState> = {
  search: '',
  status: 'all',
  page: 1,
  perPage: 10,
  sort: 'name_asc',
  createdFrom: '',
  createdTo: '',
}

// Calendar-safe ISO dates. Date parsing rejects rollover days (e.g. Feb 30),
// timezone suffixes and client-local time conversions. UTC is the API contract.
export function isValidDirectoryDate(value: string): boolean {
  if (!/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value)) return false
  const timestamp = Date.parse(`${value}T00:00:00.000Z`)
  return Number.isFinite(timestamp)
    && new Date(timestamp).toISOString().slice(0, 10) === value
}

function normalizeDateRange(from: string, to: string): { createdFrom: string; createdTo: string } {
  const createdFrom = isValidDirectoryDate(from) ? from : ''
  const createdTo = isValidDirectoryDate(to) ? to : ''
  // An impossible shared range must never be sent as a 422 or broadened
  // into a different one-sided range. Treat it as no date restriction.
  return createdFrom && createdTo && createdFrom > createdTo
    ? { createdFrom: '', createdTo: '' }
    : { createdFrom, createdTo }
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

  const rawSort = params.get('sort')
  const sort: DirectorySort = rawSort === 'name_desc' || rawSort === 'code_asc' || rawSort === 'code_desc'
    || rawSort === 'created_desc' || rawSort === 'created_asc' ? rawSort : 'name_asc'

  const { createdFrom, createdTo } = normalizeDateRange(
    params.get('created_from') ?? '', params.get('created_to') ?? '',
  )
  return { search, status, page, perPage, sort, createdFrom, createdTo }
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
  if (state.sort === 'name_desc' || state.sort === 'code_asc' || state.sort === 'code_desc'
    || state.sort === 'created_desc' || state.sort === 'created_asc') params.set('sort', state.sort)
  const { createdFrom, createdTo } = normalizeDateRange(state.createdFrom, state.createdTo)
  if (createdFrom) params.set('created_from', createdFrom)
  if (createdTo) params.set('created_to', createdTo)
  return params
}

export function directoryUrlSearch(state: DirectoryUrlState): string {
  const query = directoryUrlParams(state).toString()
  return query === '' ? '' : `?${query}`
}
