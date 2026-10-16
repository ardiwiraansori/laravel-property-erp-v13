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
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams()), { search: '', status: 'all', page: 1 })
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1 }), '')
})

test('search status and page round trip and encode Unicode/reserved characters', () => {
  const input = { search: '  Rumah & Vila / 2  ', status: 'inactive', page: 23 }
  const encoded = directoryUrlSearch(input)
  assert.ok(encoded.startsWith('?'))
  assert.ok(encoded.includes('status=inactive'))
  assert.deepEqual(readDirectoryUrlState(new URLSearchParams(encoded)), {
    search: 'Rumah & Vila / 2', status: 'inactive', page: 23,
  })
})

test('invalid, oversized or malicious filter values are discarded, not forwarded', () => {
  const params = new URLSearchParams({
    search: 'x'.repeat(101), status: 'deleted', page: '-200', company_id: '999', token: 'SECRET',
  })
  const parsed = readDirectoryUrlState(params)
  assert.deepEqual(parsed, { search: '', status: 'all', page: 1 })
  assert.equal(directoryUrlParams(parsed).toString(), '')
  assert.deepEqual([...directoryUrlParams({ search: 'Valid', status: 'active', page: 2 }).keys()],
    ['search', 'status', 'page'])
})

test('page accepts only bounded positive integer syntax', () => {
  for (const raw of ['0', '-1', '1.5', '1e3', '0002', '+2', '1000000', '999999999999999999']) {
    assert.equal(readDirectoryUrlState(new URLSearchParams({ page: raw })).page, 1, raw)
  }
  assert.equal(readDirectoryUrlState(new URLSearchParams('page=999999')).page, 999999)
  assert.equal(directoryUrlSearch({ search: '', status: 'all', page: 1000000 }), '')
})

test('non-default filters are canonical and do not forward untrusted keys', () => {
  assert.equal(directoryUrlSearch({ search: '  A  ', status: 'active', page: 1 }), '?search=A&status=active')
  assert.equal(directoryUrlSearch({ search: '', status: 'inactive', page: 4 }), '?status=inactive&page=4')
})

test('directory uses URL as applied query source and keeps a draft while typing', () => {
  const directory = file('src/features/master-data/property-developments/property-developments-page.tsx')
  assert.match(directory, /useSearchParams\(\)/)
  assert.match(directory, /readDirectoryUrlState\(urlParams\)/)
  assert.match(directory, /setDraftSearch\(\{ applied: search, value: event.target.value \}\)/)
  assert.match(directory, /directoryUrlParams\(\{ search: searchInput.trim\(\), status, page: 1 \}\)/)
  assert.match(directory, /directoryUrlParams\(\{ search, status: value, page: 1 \}\)/)
  assert.match(directory, /directoryUrlSearch\(\{ search, status, page \}\)/)
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
