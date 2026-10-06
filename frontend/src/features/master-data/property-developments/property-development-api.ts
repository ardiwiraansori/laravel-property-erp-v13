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

type PropertyDevelopmentCollectionResponse = {
  data: PropertyDevelopment[]
}

type PropertyDevelopmentResponse = {
  data: PropertyDevelopment
}

export async function getPropertyDevelopments() {
  const response =
    await api.get<PropertyDevelopmentCollectionResponse>(
      '/api/master-data/property-developments',
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
