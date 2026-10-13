import { api } from '@/lib/api'

export type MfaStatus = {
  enrolled: boolean
  setup_pending: boolean
  verified: boolean
  expires_at: string | null
  recovery_codes_remaining: number
}

export async function getPlatformMfaStatus() {
  const response = await api.get<{ data: MfaStatus }>('/api/platform/mfa/status')
  return response.data.data
}

export async function startPlatformMfaSetup(password: string) {
  const response = await api.post<{ data: {
    manual_secret: string
    otpauth_uri: string
    expires_in_seconds: number
  } }>('/api/platform/mfa/setup', { password })
  return response.data.data
}

export async function confirmPlatformMfa(code: string) {
  const response = await api.post<{ data: { verified: boolean; recovery_codes: string[] } }>(
    '/api/platform/mfa/confirm', { code },
  )
  return response.data.data
}

export async function challengePlatformMfa(code: string) {
  const response = await api.post<{ data: { verified: boolean; expires_at: string } }>(
    '/api/platform/mfa/challenge', { code },
  )
  return response.data.data
}

export async function recoverPlatformMfa(password: string, recoveryCode: string) {
  const response = await api.post('/api/platform/mfa/recover', {
    password, recovery_code: recoveryCode,
  })
  return response.data
}
