import { api } from '@/lib/api'
import type { DiagnosticPage } from '@/features/diagnostics/diagnostic-api'

export type DiagnosticStatus = 'new' | 'triaged' | 'in_progress' | 'resolved' | 'closed'
export const NEXT_STATUSES: Record<DiagnosticStatus, DiagnosticStatus[]> = {
  new: ['triaged'],
  triaged: ['in_progress'],
  in_progress: ['resolved', 'triaged'],
  resolved: ['closed', 'in_progress'],
  closed: ['triaged'],
}
export const DIAGNOSTIC_STATUSES: DiagnosticStatus[] = ['new', 'triaged', 'in_progress', 'resolved', 'closed']
export type AssignmentScope = 'all' | 'mine' | 'assigned' | 'unassigned'
export type OpenAgeBucket = 'under_24h' | 'day_1_3' | 'day_3_7' | 'over_7d'
export const AGE_BUCKETS: Array<[OpenAgeBucket, string]> = [
  ['under_24h', 'Under 24 hours'],
  ['day_1_3', '1 to <3 days'],
  ['day_3_7', '3 to <7 days'],
  ['over_7d', '7 days or more'],
]
export type SupportQueueFilters = {
  companyId: string
  status: string
  category: string
  impact: string
  assignment: AssignmentScope
  age: OpenAgeBucket | ''
  inactivity: OpenAgeBucket | ''
  sort: 'newest' | 'oldest' | 'stale_first' | 'recent_activity'
}
export const EMPTY_QUEUE_FILTERS: SupportQueueFilters = {
  companyId: '', status: '', category: '', impact: '', assignment: 'all', age: '', inactivity: '', sort: 'newest',
}
export type SupportQueueSummary = {
  total: number
  by_status: Record<DiagnosticStatus, number>
  assigned: number
  unassigned: number
  mine: number
}
export type OpenAgingSummary = { total: number } & Record<OpenAgeBucket, number>
export type WorkflowPerformanceSummary = {
  window_days: number
  reported: number
  triaged: number
  resolved: number
  reopened: number
  awaiting_first_triage: number
  average_hours_to_first_triage: number | null
  average_hours_to_first_resolution: number | null
}
export type PlatformReport = {
  public_id: string
  company_id: number
  company_code: string
  reporter_user_id: number
  category: string
  module: string
  impact: string
  error_kind: string
  browser_family: string
  viewport: string
  status: DiagnosticStatus
  assigned_platform_admin_user_id: number | null
  workflow_version: number
  created_at: string | null
  updated_at: string | null
}
export type PlatformReportPage = DiagnosticPage<PlatformReport> & {
  summary: SupportQueueSummary
  open_aging: OpenAgingSummary
  open_inactivity: OpenAgingSummary
  workflow_performance: WorkflowPerformanceSummary
}
export type WorkflowHistory = {
  action: 'status_changed' | 'assignment_changed'
  actor_user_id: number
  from_status: string | null
  to_status: string | null
  from_assignee_user_id: number | null
  to_assignee_user_id: number | null
  created_at: string
}
export type ReportDetail = {
  data: Pick<PlatformReport, 'public_id' | 'company_id' | 'reporter_user_id' | 'category' | 'module' | 'impact' | 'status' | 'assigned_platform_admin_user_id' | 'workflow_version'>
  history: WorkflowHistory[]
  meta: { current_page: number; last_page: number; total: number }
}
export type Assignee = { id: number; name: string }

export async function getPlatformReports(page: number, filters: SupportQueueFilters) {
  const response = await api.get<PlatformReportPage>('/api/platform/diagnostics/reports', {
    params: {
      page, per_page: 20,
      ...(filters.companyId ? { company_id: Number(filters.companyId) } : {}),
      ...(filters.status ? { status: filters.status } : {}),
      ...(filters.category ? { category: filters.category } : {}),
      ...(filters.impact ? { impact: filters.impact } : {}),
      ...(filters.assignment !== 'all' ? { assignment: filters.assignment } : {}),
      ...(filters.age ? { age: filters.age } : {}),
      ...(filters.inactivity ? { inactivity: filters.inactivity } : {}),
      ...(filters.sort !== 'newest' ? { sort: filters.sort } : {}),
    },
  })
  return response.data
}
export async function getPlatformReportDetail(publicId: string, page = 1) {
  const response = await api.get<ReportDetail>(`/api/platform/diagnostics/reports/${encodeURIComponent(publicId)}`, {
    params: { page },
  })
  return response.data
}
export async function getDiagnosticAssignees() {
  const response = await api.get<{ data: Assignee[] }>('/api/platform/diagnostics/assignees')
  return response.data.data
}
export async function changeDiagnosticStatus(publicId: string, status: DiagnosticStatus, workflowVersion: number) {
  const response = await api.patch(`/api/platform/diagnostics/reports/${encodeURIComponent(publicId)}/status`, {
    status, workflow_version: workflowVersion,
  })
  return response.data
}
export async function changeDiagnosticAssignee(publicId: string, assignee: number | null, workflowVersion: number) {
  const response = await api.patch(`/api/platform/diagnostics/reports/${encodeURIComponent(publicId)}/assignment`, {
    assignee_user_id: assignee, workflow_version: workflowVersion,
  })
  return response.data
}
