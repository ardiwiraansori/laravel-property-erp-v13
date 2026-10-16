// Presets are calculated from UTC calendar dates to match the backend
// created_from / created_to inclusive-day contract, not the browser timezone.
export type CreatedDatePreset = 'today' | 'last_7_days' | 'this_month' | 'clear'

export type CreatedDateRange = {
  createdFrom: string
  createdTo: string
}

function utcDay(date: Date): string {
  if (!Number.isFinite(date.getTime())) throw new Error('Invalid date reference')
  return date.toISOString().slice(0, 10)
}

// "Last 7 days" includes the current UTC date and the preceding six dates.
// Passing a reference date makes month/year/leap-boundary tests deterministic.
export function createdDatePresetRange(
  preset: CreatedDatePreset,
  reference: Date = new Date(),
): CreatedDateRange {
  if (preset === 'clear') return { createdFrom: '', createdTo: '' }

  const end = utcDay(reference)
  if (preset === 'today') return { createdFrom: end, createdTo: end }
  if (preset === 'this_month') return { createdFrom: `${end.slice(0, 7)}-01`, createdTo: end }
  if (preset === 'last_7_days') {
    const start = new Date(reference.getTime())
    start.setUTCHours(0, 0, 0, 0)
    start.setUTCDate(start.getUTCDate() - 6)
    return { createdFrom: utcDay(start), createdTo: end }
  }

  throw new Error('Unsupported created-date preset')
}

// The API supplies timestamp values. Convert to a UTC calendar day so the
// displayed date agrees with backend UTC date filtering for all timezones.
export function displayCreatedDateUtc(value: string | null | undefined): string {
  if (!value) return '—'
  const timestamp = Date.parse(value)
  return Number.isFinite(timestamp) ? new Date(timestamp).toISOString().slice(0, 10) : '—'
}
