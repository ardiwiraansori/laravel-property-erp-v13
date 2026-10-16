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
const { readDirectoryUrlState, directoryUrlParams, directoryUrlSearch } = await import(
  `data:text/javascript;charset=utf-8,${encodeURIComponent(compiled.outputText)}`
)

test('empty query yields canonical defaults', () => {
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams()), { search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc' })
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc' }), '')
})

test('search status and page round trip and encode Unicode/reserved characters', () => {
  const input = { search: '  Rumah & Vila / 2  ', status: 'inactive', page: 23, perPage: 25, sort: 'name_asc' }
  const encoded = directoryUrlSearch(input)
  assert.ok(encoded.startsWith('?'))
  assert.ok(encoded.includes('status=inactive'))
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(encoded)), {
    search: 'Rumah & Vila / 2', status: 'inactive', page: 23, perPage: 25, sort: 'name_asc',
  })
})

test('invalid, oversized or malicious filter values are discarded, not forwarded', () => {
  const params = new URLSearchParams({
    search: 'x'.repeat(101), status: 'deleted', page: '-200', company_id: '999', token: 'SECRET',
  })
  const parsed = readDirectoryUrlState(params)
  assert.deepEqual(parsed, { search: '', status: 'all', page: 1, perPage: 10, sort: 'name_asc' })
  assert.equal(directoryUrlParams(parsed).toString(), '')
  assert.deepEqual([...directoryUrlParams({ search: 'Valid', status: 'active', page: 2, perPage: 10, sort: 'name_asc' }).keys()],
    ['search', 'status', 'page'])
})

test('page accepts only bounded positive integer syntax', () => {
  for (const raw of ['0', '-1', '1.5', '1e3', '0002', '+2', '1000000', '999999999999999999']) {
    assert.equal(readDirectoryUrlState(new URLSearchParams({ page: raw })).page, 1, raw)
  }
  assert.equal(readDirectoryUrlState(new URLSearchParams('page=999999')).page, 999999)
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1000000, perPage: 10, sort: 'name_asc' }), '')
})

test('non-default filters are canonical and do not forward untrusted keys', () => {
  assert.equal(directoryUrlSearch({ search: '  A  ', status: 'active', page: 1, perPage: 10, sort: 'name_asc' }), '?search=A&status=active')
  assert.equal(directoryUrlSearch({ search: '', status: 'inactive', page: 4, perPage: 10, sort: 'name_asc' }), '?status=inactive&page=4')
})

test('directory uses URL as applied query source and keeps a draft while typing', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /useSearchParams\(\)/)
  assert.match(directory, /readDirectoryUrlState\(urlParams\)/)
  assert.match(directory, /setDraftSearch\(\{ applied: search, value: event.target.value \}\)/)
  assert.match(directory, /directoryUrlParams\(\{ search: searchInput.trim\(\), status, page: 1, perPage, sort \}\)/)
  assert.match(directory, /directoryUrlParams\(\{ search, status: value, page: 1, perPage, sort \}\)/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page, perPage, sort \}\)/)
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
  const raw = { search: 'Jalan & Rumah', status: 'active', page: 3, perPage: 50, sort: 'name_asc' }
  const url = directoryUrlSearch(raw)
  assert.equal(url, '?search=Jalan+%26+Rumah&status=active&page=3&per_page=50')
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(url)), raw)
  assert.equal(directoryUrlSearch({ ...raw, perPage: 10, sort: 'name_asc' }), '?search=Jalan+%26+Rumah&status=active&page=3')
})

test('URL serializer drops untrusted page size without forwarding any tenant identifier', () => {
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1, perPage: 100, sort: 'name_asc' }), '')
  const parsed = readDirectoryUrlState(new URLSearchParams('per_page=25&company_id=42&access=admin'))
  assert.equal(directoryUrlParams(parsed).toString(), 'per_page=25')
})

test('directory uses server-supported per-page and updates only page size on user selection', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /getPropertyDevelopmentDirectory\(\{ page, perPage, search, status, sort \}\)/)
  assert.match(directory, /function changePageSize\(value: DirectoryPageSize\)/)
  assert.match(directory, /directoryUrlParams\(\{ search, status, page: 1, perPage: value, sort \}\)/)
  assert.match(directory, /id=\"development-page-size\"/)
  assert.match(directory, /<option value=\{10\}>10<\/option>/)
  assert.match(directory, /<option value=\{25\}>25<\/option>/)
  assert.match(directory, /<option value=\{50\}>50<\/option>/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page, perPage, sort \}\)/)
  assert.doesNotMatch(directory, /perPage: PAGE_SIZE/)
})

test('stale out-of-range page redirects to valid last page without phantom empty result', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /if \(page > Math.max\(1, response.meta.last_page\)\)/)
  assert.match(directory, /setUrlParams\(directoryUrlParams\(\{ search, status, page: Math.max\(1, response.meta.last_page\), perPage, sort \}\), \{ replace: true \}\)/)
  assert.match(directory, /const showingLoading = isLoading \|\| loadedKey !== queryKey/)
  assert.match(directory, /\[companyId, search, status, page, perPage, sort, revision\]/)
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
  const state = { search: 'Rumah & Villa', status: 'inactive', page: 3, perPage: 25, sort: 'created_desc' }
  const query = directoryUrlSearch(state)
  assert.equal(query, '?search=Rumah+%26+Villa&status=inactive&page=3&per_page=25&sort=created_desc')
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(query)), state)
  assert.equal(directoryUrlSearch({ ...state, sort: 'name_asc' }), '?search=Rumah+%26+Villa&status=inactive&page=3&per_page=25')
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
  assert.match(directory, /directoryUrlParams\(\{ search, status, page: 1, perPage, sort: value \}\)/)
  assert.match(directory, /getPropertyDevelopmentDirectory\(\{ page, perPage, search, status, sort \}\)/)
  assert.match(directory, /exportPropertyDevelopmentsCsv\(\{ search, status, sort \}\)/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page, perPage, sort \}\)/)
  assert.match(directory, /\[companyId, search, status, page, perPage, sort, revision\]/)
})

test('directory API and CSV export pass only the selected allowlisted sort', () => {
  const api = file('src/features/master-data/property-developments/property-development-api.ts')
  assert.match(api, /export type DirectorySort =/)
  assert.match(api, /sort: query.sort/)
  assert.match(api, /sort: filters.sort/)
  assert.doesNotMatch(api, /company_id:/)
})
