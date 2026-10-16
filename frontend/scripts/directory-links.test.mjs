import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import test from 'node:test'
import ts from 'typescript'

const root = fileURLToPath(new URL('../', import.meta.url))
const file = (relative) => readFileSync(path.join(root, relative), 'utf8')
const source = file('src/features/master-data/property-developments/directory-url.ts')
// Compile the actual TypeScript URL utility and test its runtime behavior.
// This only uses the existing TypeScript dev dependency; no browser or DB.
const compiled = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
  reportDiagnostics: true,
})
assert.deepEqual(compiled.diagnostics?.filter((d) => d.category === ts.DiagnosticCategory.Error) ?? [], [])
const { readDirectoryUrlState, directoryUrlParams, directoryUrlSearch, isValidDirectoryDate } = await import(
  `data:text/javascript;charset=utf-8,${encodeURIComponent(compiled.outputText)}`
)

test('empty query yields canonical defaults', () => {
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams()), { search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''})
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''}), '')
})

test('search status and page round trip and encode Unicode/reserved characters', () => {
  const input = { search: '  Rumah & Vila / 2  ', status: 'inactive', page: 23, perPage: 25, sort: 'name_asc', createdFrom: '', createdTo: ''}
  const encoded = directoryUrlSearch(input)
  assert.ok(encoded.startsWith('?'))
  assert.ok(encoded.includes('status=inactive'))
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(encoded)), {
    search: 'Rumah & Vila / 2', status: 'inactive', page: 23, perPage: 25, sort: 'name_asc',
    createdFrom: '', createdTo: '',
  })
})

test('invalid, oversized or malicious filter values are discarded, not forwarded', () => {
  const params = new URLSearchParams({
    search: 'x'.repeat(101), status: 'deleted', page: '-200', company_id: '999', token: 'SECRET',
  })
  const parsed = readDirectoryUrlState(params)
  assert.deepEqual(parsed, { search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''})
  assert.equal(directoryUrlParams(parsed).toString(), '')
  assert.deepEqual([...directoryUrlParams({ search: 'Valid', status: 'active', page: 2, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''}).keys()],
    ['search', 'status', 'page'])
})

test('page accepts only bounded positive integer syntax', () => {
  for (const raw of ['0', '-1', '1.5', '1e3', '0002', '+2', '1000000', '999999999999999999']) {
    assert.equal(readDirectoryUrlState(new URLSearchParams({ page: raw })).page, 1, raw)
  }
  assert.equal(readDirectoryUrlState(new URLSearchParams('page=999999')).page, 999999)
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1000000, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''}), '')
})

test('non-default filters are canonical and do not forward untrusted keys', () => {
  assert.equal(directoryUrlSearch({ search: '  A  ', status: 'active', page: 1, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''}), '?search=A&status=active')
  assert.equal(directoryUrlSearch({ search: '', status: 'inactive', page: 4, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''}), '?status=inactive&page=4')
})

test('directory uses URL as applied query source and keeps a draft while typing', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /useSearchParams\(\)/)
  assert.match(directory, /readDirectoryUrlState\(urlParams\)/)
  assert.match(directory, /setDraftSearch\(\{ applied: search, value: event.target.value \}\)/)
  assert.match(directory, /directoryUrlParams\(\{ search: searchInput.trim\(\), status, page: 1, perPage, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /directoryUrlParams\(\{ search, status: value, page: 1, perPage, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page, perPage, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /loadedKey !== queryKey/)
  assert.doesNotMatch(directory, /useState<DirectoryStatus>\('all'\)/)
})

test('detail back link retains only allowlisted directory filters', () => {
  const detail = file('src/features/master-data/property-developments/property-development-detail-page.tsx')
  assert.match(detail, /readDirectoryUrlState\(new URLSearchParams\(location.search\)\)/)
  assert.match(detail, /to=\{backToDirectory\}/)
})

test('dashboard links allowlisted drill-down only with existing directory access', () => {
  const card = file('src/components/dashboard/property-development-summary-card.tsx')
  assert.match(card, /\{canOpenDirectory \? \(/)
  assert.match(card, /property-developments\?status=active/)
  assert.match(card, /property-developments\?status=inactive/)
  assert.doesNotMatch(card, /company_id=/)
})


test('page-size allowlist accepts only 10, 25 or 50 and rejects unsafe values', () => {
  for (const invalid of ['0', '1', '24', '51', '100', '025', '-25', '25.0', '2e1', '50&company_id=999']) {
    assert.equal(readDirectoryUrlState(new URLSearchParams({ per_page: invalid })).perPage, 10, invalid)
  }
  assert.equal(readDirectoryUrlState(new URLSearchParams('per_page=25')).perPage, 25)
  assert.equal(readDirectoryUrlState(new URLSearchParams('per_page=50')).perPage, 50)
})

test('page-size URL round trip omits default and retains allowed size in detail deep links', () => {
  const raw = { search: 'Jalan & Rumah', status: 'active', page: 3, perPage: 50, sort: 'name_asc', createdFrom: '', createdTo: ''}
  const url = directoryUrlSearch(raw)
  assert.equal(url, '?search=Jalan+%26+Rumah&status=active&page=3&per_page=50')
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(url)), raw)
  assert.equal(directoryUrlSearch({ ...raw, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: ''}), '?search=Jalan+%26+Rumah&status=active&page=3')
})

test('URL serializer drops untrusted page size without forwarding any tenant identifier', () => {
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1, perPage: 100, sort: 'name_asc', createdFrom: '', createdTo: ''}), '')
  const parsed = readDirectoryUrlState(new URLSearchParams('per_page=25&company_id=42&access=admin'))
  assert.equal(directoryUrlParams(parsed).toString(), 'per_page=25')
})

test('directory uses server-supported per-page and updates only page size on user selection', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /getPropertyDevelopmentDirectory\(\{ page, perPage, search, status, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /function changePageSize\(value: DirectoryPageSize\)/)
  assert.match(directory, /directoryUrlParams\(\{ search, status, page: 1, perPage: value, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /id=\"development-page-size\"/)
  assert.match(directory, /<option value=\{10\}>10<\/option>/)
  assert.match(directory, /<option value=\{25\}>25<\/option>/)
  assert.match(directory, /<option value=\{50\}>50<\/option>/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page, perPage, sort, createdFrom, createdTo \}\)/)
  assert.doesNotMatch(directory, /perPage: PAGE_SIZE/)
})

test('stale out-of-range page redirects to valid last page without phantom empty result', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /if \(page > Math.max\(1, response.meta.last_page\)\)/)
  assert.match(directory, /setUrlParams\(directoryUrlParams\(\{ search, status, page: Math.max\(1, response.meta.last_page\), perPage, sort, createdFrom, createdTo \}\), \{ replace: true \}\)/)
  assert.match(directory, /const showingLoading = isLoading \|\| loadedKey !== queryKey/)
  assert.match(directory, /\[companyId, search, status, page, perPage, sort, createdFrom, createdTo, revision\]/)
})


test('sort allowlist defaults safely and rejects SQL identifiers, arrays and tenant data', () => {
  for (const value of ['name_asc', 'name_desc', 'code_asc', 'code_desc', 'created_desc', 'created_asc']) {
    assert.equal(readDirectoryUrlState(new URLSearchParams({ sort: value })).sort, value)
  }
  for (const invalid of ['', 'name', 'created_at', 'name ASC', 'name_asc;DROP', 'NAME_DESC', 'id_desc', 'company_id']) {
    assert.equal(readDirectoryUrlState(new URLSearchParams({ sort: invalid })).sort, 'name_asc', invalid)
  }
  assert.equal(readDirectoryUrlState(new URLSearchParams('sort[]=code_asc')).sort, 'name_asc')
})

test('sorting round trip is canonical and preserves all existing directory filters', () => {
  const state = { search: 'Rumah & Villa', status: 'inactive', page: 3, perPage: 25, sort: 'created_desc', createdFrom: '', createdTo: ''}
  const query = directoryUrlSearch(state)
  assert.equal(query, '?search=Rumah+%26+Villa&status=inactive&page=3&per_page=25&sort=created_desc')
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(query)), state)
  assert.equal(directoryUrlSearch({ ...state, sort: 'name_asc', createdFrom: '', createdTo: ''}), '?search=Rumah+%26+Villa&status=inactive&page=3&per_page=25')
})

test('unrecognized URL keys can never be forwarded by the sort serializer', () => {
  const input = new URLSearchParams('sort=code_desc&company_id=100&role=Owner&direction=DROP')
  assert.equal(directoryUrlParams(readDirectoryUrlState(input)).toString(), 'sort=code_desc')
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1, perPage: 10, sort: 'DROP TABLE users' }), '')
})

test('directory sort selection resets page while preserving filters and page size', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /id="development-sort"/)
  assert.match(directory, /function changeSort\(value: DirectorySort\)/)
  assert.match(directory, /directoryUrlParams\(\{ search, status, page: 1, perPage, sort: value, createdFrom, createdTo \}\)/)
  assert.match(directory, /getPropertyDevelopmentDirectory\(\{ page, perPage, search, status, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /exportPropertyDevelopmentsCsv\(\{ search, status, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page, perPage, sort, createdFrom, createdTo \}\)/)
  assert.match(directory, /\[companyId, search, status, page, perPage, sort, createdFrom, createdTo, revision\]/)
})

test('directory API and CSV export pass only the selected allowlisted sort', () => {
  const api = file('src/features/master-data/property-developments/property-development-api.ts')
  assert.match(api, /export type DirectorySort =/)
  assert.match(api, /sort: query.sort/)
  assert.match(api, /sort: filters.sort/)
  assert.doesNotMatch(api, /company_id:/)
})


test('created dates accept only real ISO calendar dates and allow one-sided ranges', () => {
  const dates = ['2024-02-29', '2026-02-28', '2026-12-31']
  for (const date of dates) {
    assert.equal(isValidDirectoryDate(date), true)
  }
  for (const date of ['2026-02-30', '2025-02-29', '2026-13-01', '2026-00-01',
    '2026-01-00', '2026-01-32', '2026-2-01', '2026-02-01T00:00Z', 'DROP']) {
    assert.equal(isValidDirectoryDate(date), false, date)
  }
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams('created_to=2026-10-16')),
    { search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc', createdFrom: '', createdTo: '2026-10-16' })
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams('created_from=2024-02-29')),
    { search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc', createdFrom: '2024-02-29', createdTo: '' })
})

test('created dates round trip with search status sort page and page size', () => {
  const raw = { search: 'Villa & Rumah', status: 'active', page: 3, perPage: 25,
    sort: 'created_desc', createdFrom: '2026-02-01', createdTo: '2026-02-28' }
  const query = directoryUrlSearch(raw)
  assert.equal(query, '?search=Villa+%26+Rumah&status=active&page=3&per_page=25&sort=created_desc&created_from=2026-02-01&created_to=2026-02-28')
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(query)), raw)
})

test('reversed and malformed date ranges are never forwarded to backend', () => {
  for (const query of ['created_from=2026-03-01&created_to=2026-02-01',
    'created_from=2026-02-30&created_to=2026-02-31',
    'created_from[]=2026-01-01&created_to[]=2026-02-01']) {
    assert.equal(directoryUrlSearch(readDirectoryUrlState(new URLSearchParams(query))), '')
  }
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1, perPage: 10,
    sort: 'name_asc', createdFrom: '2026-10-16', createdTo: '2026-01-01' }), '')
  assert.equal(directoryUrlSearch(readDirectoryUrlState(new URLSearchParams(
    'created_from=2026-03-01&created_to=2026-02-01&status=active&company_id=999'))), '?status=active')
})

test('bad date in one bound is removed without discarding valid opposite bound', () => {
  assert.equal(directoryUrlSearch(readDirectoryUrlState(new URLSearchParams(
    'created_from=2026-02-30&created_to=2026-02-28&sort=code_desc'))),
  '?sort=code_desc&created_to=2026-02-28')
  assert.equal(directoryUrlSearch(readDirectoryUrlState(new URLSearchParams(
    'created_from=2026-01-01&created_to=not-a-date'))), '?created_from=2026-01-01')
})

test('date controls apply both dates together, retain them in all directory navigation', () => {
  const page = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(page, /id="development-created-from" type="date"/)
  assert.match(page, /id="development-created-to" type="date"/)
  assert.match(page, /function applyDates\(event: FormEvent<HTMLFormElement>\)/)
  assert.match(page, /from !== '' && to !== '' && from > to/)
  assert.match(page, /page: 1, perPage, sort, createdFrom: from, createdTo: to/)
  assert.match(page, /getPropertyDevelopmentDirectory\(\{ page, perPage, search, status, sort, createdFrom, createdTo \}\)/)
  assert.match(page, /exportPropertyDevelopmentsCsv\(\{ search, status, sort, createdFrom, createdTo \}\)/)
  assert.match(page, /directoryUrlSearch\(\{ search, status, page, perPage, sort, createdFrom, createdTo \}\)/)
  assert.match(page, /queryKey = JSON.stringify\(\[companyId, search, status, page, perPage, sort, createdFrom, createdTo, revision\]\)/)
})

test('date API keys are allowlisted and exported without page or tenant identifier', () => {
  const api = file('src/features/master-data/property-developments/property-development-api.ts')
  assert.match(api, /created_from: query.createdFrom \|\| undefined/)
  assert.match(api, /created_to: query.createdTo \|\| undefined/)
  assert.match(api, /created_from: filters.createdFrom \|\| undefined/)
  assert.match(api, /created_to: filters.createdTo \|\| undefined/)
  assert.doesNotMatch(api, /company_id:/)
  const detail = file('src/features/master-data/property-developments/property-development-detail-page.tsx')
  assert.match(detail, /directoryUrlSearch\(readDirectoryUrlState\(new URLSearchParams\(location.search\)\)\)/)
})
