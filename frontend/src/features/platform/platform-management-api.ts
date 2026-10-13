import { api } from '@/lib/api'

export type PlatformCapabilities = {
  writes_enabled: boolean
  mfa_required: boolean
}

export async function getPlatformCapabilities(): Promise<PlatformCapabilities> {
  const response = await api.get<{ data: PlatformCapabilities }>('/api/platform/capabilities')
  return response.data.data
}

export async function createPlatformCompany(input: { code: string; name: string }) {
  return api.post('/api/platform/companies', input)
}

export async function updatePlatformCompany(id: number, input: { name?: string; is_active?: boolean }) {
  return api.patch(`/api/platform/companies/${id}`, input)
}

export async function updatePlatformUser(id: number, name: string) {
  return api.patch(`/api/platform/users/${id}`, { name })
}

export async function updatePlatformMembership(userId: number, companyId: number, operation: 'attach' | 'detach') {
  const url = `/api/platform/users/${userId}/memberships/${companyId}`
  return operation === 'attach' ? api.post(url) : api.delete(url)
}
