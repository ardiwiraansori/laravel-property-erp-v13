import assert from 'node:assert/strict'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import test from 'node:test'

const frontendRoot = fileURLToPath(new URL('../', import.meta.url))
const router = readFileSync(path.join(frontendRoot, 'src/app/router.tsx'), 'utf8')
const deferredPages = readFileSync(path.join(frontendRoot, 'src/app/deferred-pages.tsx'), 'utf8')
const routeErrorBoundary = readFileSync(path.join(frontendRoot, 'src/app/lazy-route-error-boundary.tsx'), 'utf8')
const assetsDirectory = path.join(frontendRoot, 'dist/assets')

// These tests inspect actual Vite output, not an assumed bundle-size reduction.
// Run only after npm run build; they use Node's built-in test runner.
const routes = [
  ['DashboardPage', 'dashboard-page'],
  ['ReportProblemPage', 'report-problem-page'],
  ['PropertyDevelopmentsPage', 'property-developments-page'],
  ['PropertyDevelopmentDetailPage', 'property-development-detail-page'],
  ['PlatformOverviewPage', 'platform-overview-page'],
  ['PlatformCompaniesPage', 'platform-companies-page'],
  ['PlatformDiagnosticsPage', 'platform-diagnostics-page'],
  ['PlatformUsersPage', 'platform-users-page'],
]

const output = readdirSync(assetsDirectory).filter((file) => file.endsWith('.js'))

for (const [component, chunkName] of routes) {
  test(`${component} is deferred into a separate nonempty production chunk`, () => {
    assert.match(deferredPages, new RegExp(`export const ${component} = lazy\\(\\(\\) => import\\(`))
    const chunk = output.find((file) => file.startsWith(`${chunkName}-`))
    assert.ok(chunk, `Vite did not emit a ${chunkName} chunk`)
    assert.ok(statSync(path.join(assetsDirectory, chunk)).size > 0)
  })
}

test('platform and tenant authorization boundaries remain eager in route definitions', () => {
  assert.match(router, /element: <PlatformRoute \/>/)
  assert.match(router, /element: <CompanyProvider><ProtectedRoute \/><\/CompanyProvider>/)
  assert.match(router, /element: <PermissionRoute permission=\{permissions\.propertyDevelopmentsView\}>/)
  assert.match(router, /element: <PermissionRoute permission=\{permissions\.masterDataView\}>/)
  assert.match(router, /path: '\/platform\/mfa', element: <PlatformMfaPage \/>/)
  assert.match(router, /<DeferredPage><PlatformDiagnosticsPage \/><\/DeferredPage>/)
})

test('Fast Refresh boundaries remain isolated from router instance exports', () => {
  assert.match(router, /from '@\/app\/deferred-pages'/)
  assert.doesNotMatch(router, /\blazy\s*\(/)
  assert.doesNotMatch(router, /\bfunction DeferredPage\b/)
  assert.match(deferredPages, /export function DeferredPage\(/)
  assert.doesNotMatch(deferredPages, /export const router\b/)
})

test('development detail deep link remains nested under tenant view permission', () => {
  assert.match(router, /path: 'property-developments\/:developmentId'/)
  assert.match(router, /<DeferredPage><PropertyDevelopmentDetailPage \/><\/DeferredPage>/)
  assert.match(router, /element: <PermissionRoute permission=\{permissions\.propertyDevelopmentsView\}>/)
  assert.match(deferredPages, /export const PropertyDevelopmentDetailPage = lazy\(/)
})

test('entry file and route chunks are separate assets', () => {
  assert.ok(output.some((file) => /^index-.*\.js$/.test(file)), 'Vite entry JS not found')
  assert.ok(output.length >= routes.length + 1, 'Expected at least eight feature chunks plus entry JS')
})

test('lazy route failures are contained with a safe manual recovery action', () => {
  assert.match(deferredPages, /<LazyRouteErrorBoundary key=\{pathname\}>/)
  assert.match(routeErrorBoundary, /static getDerivedStateFromError\(\)/)
  assert.match(routeErrorBoundary, /role="alert"/)
  assert.match(routeErrorBoundary, /onClick=\{\(\) => window\.location\.reload\(\)\}/)
  assert.doesNotMatch(routeErrorBoundary, /console\.|componentDidCatch|\berror\.message\b/)
})

test('existing suspense fallback is nested inside the route failure boundary', () => {
  assert.match(deferredPages, /<LazyRouteErrorBoundary key=\{pathname\}>\s*<Suspense fallback=/)
  assert.match(deferredPages, /useLocation\(\)/)
  assert.match(deferredPages, /<\/Suspense>\s*<\/LazyRouteErrorBoundary>/)
})
