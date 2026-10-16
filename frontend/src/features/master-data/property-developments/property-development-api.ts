import { api } from '@/lib/api'

export type PropertyDevelopment = {
  id: number
  code: string
  name: string
  is_active: boolean
  created_at: string | null
}

export type CreatePropertyDevelopmentInput = {
  code: string
  name: string
}

export type DirectoryStatus = 'all' | 'active' | 'inactive'

export type PropertyDevelopmentDirectoryQuery = {
  page: number
  perPage: number
  search: string
  status: DirectoryStatus
}

export type DirectoryMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

type PropertyDevelopmentCollectionResponse = {
  data: PropertyDevelopment[]
}

type PropertyDevelopmentDirectoryResponse = PropertyDevelopmentCollectionResponse & {
  meta: DirectoryMeta
}

type PropertyDevelopmentResponse = {
  data: PropertyDevelopment
}

// Unfiltered callers retain the legacy array response contract.
export async function getPropertyDevelopments() {
  const response = await api.get<PropertyDevelopmentCollectionResponse>(
    '/api/master-data/property-developments',
  )

  return response.data.data
}

export async function getPropertyDevelopmentDirectory(query: PropertyDevelopmentDirectoryQuery) {
  const response = await api.get<PropertyDevelopmentDirectoryResponse>(
    '/api/master-data/property-developments',
    {
      params: {
        page: query.page,
        per_page: query.perPage,
        search: query.search || undefined,
        status: query.status,
      },
    },
  )

  return response.data
}

export async function createPropertyDevelopment(
  input: CreatePropertyDevelopmentInput,
) {
  const response = await api.post<PropertyDevelopmentResponse>(
    '/api/master-data/property-developments',
    input,
  )

  return response.data.data
}
