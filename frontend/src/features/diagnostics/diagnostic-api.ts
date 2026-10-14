import { api } from '@/lib/api'

export type DiagnosticCategory =
  | 'page_error' | 'unexpected_behavior' | 'access_problem' | 'slow_response' | 'other'
export type DiagnosticModule =
  | 'dashboard' | 'master_data' | 'crm' | 'sales' | 'accounting' | 'reports' | 'navigation' | 'other'
export type DiagnosticImpact = 'blocked' | 'partial' | 'minor' | 'unknown'
export type DiagnosticErrorKind = 'none' | 'load_failed' | 'permission_denied' | 'timeout' | 'unexpected_result' | 'unknown'
export type DiagnosticBrowser = 'chrome' | 'edge' | 'firefox' | 'safari' | 'other' | 'unknown'
export type DiagnosticViewport = 'mobile' | 'tablet' | 'desktop' | 'unknown'
export type TenantDiagnosticStatus = 'new' | 'triaged' | 'in_progress' | 'resolved' | 'closed'

export type DiagnosticInput = {
  category: DiagnosticCategory
  module: DiagnosticModule
  impact: DiagnosticImpact
  error_kind: DiagnosticErrorKind
  browser_family: DiagnosticBrowser
  viewport: DiagnosticViewport
}

export type ReportReceipt = Pick<DiagnosticReport, 'public_id' | 'category' | 'module' | 'status'>
export type DiagnosticReport = {
  public_id: string
  category: DiagnosticCategory
  module: DiagnosticModule
  impact: DiagnosticImpact
  status: string
  created_at: string | null
}
export type DiagnosticPagination = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}
export type DiagnosticPage<T> = { data: T[]; meta: DiagnosticPagination }
export type TenantDiagnosticProgress = {
  data: DiagnosticReport & { updated_at: string | null }
  history: Array<{ from_status: TenantDiagnosticStatus; to_status: TenantDiagnosticStatus; created_at: string }>
  meta: DiagnosticPagination
}

export const categories: Array<[DiagnosticCategory, string]> = [
  ['page_error', 'Halaman tidak dapat dibuka'],
  ['unexpected_behavior', 'Fungsi tidak berjalan sesuai harapan'],
  ['access_problem', 'Masalah hak akses'],
  ['slow_response', 'Aplikasi berjalan lambat'],
  ['other', 'Masalah lain'],
]
export const modules: Array<[DiagnosticModule, string]> = [
  ['dashboard', 'Dashboard'], ['master_data', 'Master Data'], ['crm', 'CRM'],
  ['sales', 'Sales'], ['accounting', 'Accounting'], ['reports', 'Reports'],
  ['navigation', 'Navigasi / Menu'], ['other', 'Modul lain'],
]
export const impacts: Array<[DiagnosticImpact, string]> = [
  ['blocked', 'Tidak bisa melanjutkan pekerjaan'],
  ['partial', 'Sebagian fungsi terganggu'],
  ['minor', 'Gangguan kecil'], ['unknown', 'Belum tahu'],
]
export const errors: Array<[DiagnosticErrorKind, string]> = [
  ['none', 'Tidak ada pesan kesalahan'], ['load_failed', 'Gagal memuat halaman'],
  ['permission_denied', 'Akses ditolak'], ['timeout', 'Waktu tunggu habis'],
  ['unexpected_result', 'Hasil tidak sesuai'], ['unknown', 'Belum tahu'],
]

// Never transmit the raw User-Agent, exact device dimensions, current URL, or search params.
export function browserFamily(): DiagnosticBrowser {
  if (typeof navigator === 'undefined') return 'unknown'
  const agent = navigator.userAgent
  if (/Edg\//.test(agent)) return 'edge'
  if (/Firefox\//.test(agent)) return 'firefox'
  if (/Chrome\//.test(agent)) return 'chrome'
  if (/Safari\//.test(agent)) return 'safari'
  return 'other'
}
export function viewportClass(): DiagnosticViewport {
  if (typeof window === 'undefined') return 'unknown'
  if (window.innerWidth < 640) return 'mobile'
  if (window.innerWidth < 1024) return 'tablet'
  return 'desktop'
}

export async function submitDiagnosticReport(input: DiagnosticInput): Promise<ReportReceipt> {
  const response = await api.post<{ data: ReportReceipt }>('/api/diagnostics/reports', input)
  return response.data.data
}
export async function getMyDiagnosticReports(page = 1): Promise<DiagnosticPage<DiagnosticReport>> {
  const response = await api.get<DiagnosticPage<DiagnosticReport>>('/api/diagnostics/reports', {
    params: { page, per_page: 10 },
  })
  return response.data
}
export async function getMyDiagnosticProgress(publicId: string, page = 1): Promise<TenantDiagnosticProgress> {
  const response = await api.get<TenantDiagnosticProgress>(`/api/diagnostics/reports/${encodeURIComponent(publicId)}`, {
    params: { page },
  })
  return response.data
}
