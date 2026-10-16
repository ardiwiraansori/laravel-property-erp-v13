import { api } from '@/lib/api'

export type PropertyDevelopment = {
  id: number
  code: string
  name: string
  is_active: boolean
  created_at: string | null
}

export type PropertyDevelopmentDetail = PropertyDevelopment & { updated_at: string | null }

export type CreatePropertyDevelopmentInput = {
  code: string
  name: string
}

export type UpdatePropertyDevelopmentInput = {
  name: string
  is_active: boolean
  expected_name: string
  expected_is_active: boolean
}

export type DirectoryStatus = 'all' | 'active' | 'inactive'
export type DirectorySort = 'name_asc' | 'name_desc' | 'code_asc' | 'code_desc' | 'created_desc' | 'created_asc'

export type PropertyDevelopmentDirectoryQuery = {
  page: number
  perPage: number
  search: string
  status: DirectoryStatus
  sort: DirectorySort
  createdFrom: string
  createdTo: string
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

type PropertyDevelopmentDetailResponse = { data: PropertyDevelopmentDetail }

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
        sort: query.sort,
        created_from: query.createdFrom || undefined,
        created_to: query.createdTo || undefined,
      },
    },
  )

  return response.data
}

export async function getPropertyDevelopment(id: number): Promise<PropertyDevelopmentDetail> {
  const response = await api.get<PropertyDevelopmentDetailResponse>(
    `/api/master-data/property-developments/${id}`,
  )

  return response.data.data
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

export async function updatePropertyDevelopment(
  id: number,
  input: UpdatePropertyDevelopmentInput,
) {
  const response = await api.patch<PropertyDevelopmentResponse>(
    `/api/master-data/property-developments/${id}`,
    input,
  )

  return response.data.data
}

// Download uses the existing tenant-aware Axios instance and active directory filters.
// It returns a Blob; browser download is initiated only by an explicit user click.
export async function exportPropertyDevelopmentsCsv(
  filters: Pick<PropertyDevelopmentDirectoryQuery, 'search' | 'status' | 'sort' | 'createdFrom' | 'createdTo'>,
): Promise<Blob> {
  const response = await api.get<Blob>(
    '/api/master-data/property-developments/export',
    {
      params: {
        search: filters.search || undefined,
        status: filters.status,
        sort: filters.sort,
        created_from: filters.createdFrom || undefined,
        created_to: filters.createdTo || undefined,
      },
      responseType: 'blob',
    },
  )

  return response.data
}
