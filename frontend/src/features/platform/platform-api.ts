import { api } from '@/lib/api'

export type PaginationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type Paginated<T> = {
  data: T[]
  meta: PaginationMeta
}

export type PlatformCompany = {
  id: number
  code: string
  name: string
  is_active: boolean
  members_count: number
  created_at: string | null
}

export type PlatformUser = {
  id: number
  name: string
  email: string
  memberships_count: number
}

export type PlatformMembership = {
  id: number
  code: string
  name: string
  is_active: boolean
}

export async function getPlatformCompanies(page = 1, search = '', perPage = 20) {
  const response = await api.get<Paginated<PlatformCompany>>('/api/platform/companies', {
    params: { page, per_page: perPage, ...(search ? { search } : {}) },
  })
  return response.data
}

export async function getPlatformUsers(page = 1, search = '', perPage = 20) {
  const response = await api.get<Paginated<PlatformUser>>('/api/platform/users', {
    params: { page, per_page: perPage, ...(search ? { search } : {}) },
  })
  return response.data
}

export async function getPlatformMemberships(userId: number, page = 1) {
  const response = await api.get<Paginated<PlatformMembership>>(
    `/api/platform/users/${userId}/memberships`, { params: { page } },
  )
  return response.data
}

