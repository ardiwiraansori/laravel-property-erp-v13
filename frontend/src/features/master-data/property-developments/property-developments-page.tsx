import axios from 'axios'
import { Download, Plus, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'

import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { hasPermission, permissions } from '@/features/auth/permissions'
import { useCompany } from '@/features/company/company-state'
import { directoryUrlParams, directoryUrlSearch, isValidDirectoryDate, readDirectoryUrlState } from '@/features/master-data/property-developments/directory-url'
import type { DirectoryPageSize } from '@/features/master-data/property-developments/directory-url'
import {
  createPropertyDevelopment,
  exportPropertyDevelopmentsCsv,
  getPropertyDevelopmentDirectory,
  updatePropertyDevelopment,
  type DirectoryMeta,
  type DirectoryStatus,
  type DirectorySort,
  type PropertyDevelopment,
} from '@/features/master-data/property-developments/property-development-api'

type ValidationResponse = {
  message?: string
  errors?: {
    code?: string[]
    name?: string[]
  }
}

type FieldErrors = {
  code?: string
  name?: string
}

export function PropertyDevelopmentsPage() {
  const { activeCompany } = useCompany()
  const companyId = activeCompany?.id
  const [urlParams, setUrlParams] = useSearchParams()
  const { search, status, page, perPage, sort, createdFrom, createdTo } = readDirectoryUrlState(urlParams)
  // Keep a local draft so typing does not start a new request until Search.
  // Browser Back/Forward still refreshes the input from the applied URL filter.
  const [draftSearch, setDraftSearch] = useState({ applied: search, value: search })
  const searchInput = draftSearch.applied === search ? draftSearch.value : search
  const appliedDatesKey = JSON.stringify([createdFrom, createdTo])
  const [draftDates, setDraftDates] = useState({ applied: appliedDatesKey, from: createdFrom, to: createdTo })
  const dateInputs = draftDates.applied === appliedDatesKey
    ? draftDates : { applied: appliedDatesKey, from: createdFrom, to: createdTo }
  const [dateError, setDateError] = useState<string | null>(null)

  const [developments, setDevelopments] = useState<PropertyDevelopment[]>([])
  const [meta, setMeta] = useState<DirectoryMeta | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadedKey, setLoadedKey] = useState('')
  const [loadError, setLoadError] = useState<string | null>(null)
  const [revision, setRevision] = useState(0)
  const [isExporting, setIsExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  const queryKey = JSON.stringify([companyId, search, status, page, perPage, sort, createdFrom, createdTo, revision])
  const showingLoading = isLoading || loadedKey !== queryKey

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [formSuccess, setFormSuccess] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const [editing, setEditing] = useState<PropertyDevelopment | null>(null)
  const [editName, setEditName] = useState('')
  const [editActive, setEditActive] = useState(true)
  const [isUpdating, setIsUpdating] = useState(false)
  const [editError, setEditError] = useState<string | null>(null)
  const [editSuccess, setEditSuccess] = useState<string | null>(null)

  const canCreate =
    activeCompany !== null &&
    hasPermission(
      activeCompany.permissions,
      permissions.propertyDevelopmentsCreate,
    )

  const canUpdate =
    activeCompany !== null &&
    hasPermission(activeCompany.permissions, permissions.propertyDevelopmentsUpdate)

  useEffect(() => {
    let current = true

    // Every query is associated with the currently resolved tenant. When it
    // changes, the old response must not update another tenant's directory.
    void getPropertyDevelopmentDirectory({ page, perPage, search, status, sort, createdFrom, createdTo })
      .then((response) => {
        if (!current) return
        // Records may have been removed or filters may be shared with a stale
        // page. Redirect to a valid last page instead of displaying a false
        // 'No matching' empty state. replace avoids polluting browser history.
        if (page > Math.max(1, response.meta.last_page)) {
          setEditing(null)
          setUrlParams(directoryUrlParams({ search, status, page: Math.max(1, response.meta.last_page), perPage, sort, createdFrom, createdTo }), { replace: true })
          return
        }
        setDevelopments(response.data)
        setMeta(response.meta)
        setLoadError(null)
      })
      .catch(() => {
        if (!current) return
        setDevelopments([])
        setMeta(null)
        setLoadError('Unable to load property developments. Please try again.')
      })
      .finally(() => {
        if (current) {
          setLoadedKey(queryKey)
          setIsLoading(false)
        }
      })

    return () => {
      current = false
    }
  }, [companyId, page, perPage, revision, search, status, sort, createdFrom, createdTo, queryKey, setUrlParams])

  function beginEdit(development: PropertyDevelopment) {
    if (!canUpdate || isUpdating) return
    setEditing(development)
    setEditName(development.name)
    setEditActive(development.is_active)
    setEditError(null)
    setEditSuccess(null)
  }

  async function saveEdit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing || !canUpdate || isUpdating) return
    setIsUpdating(true)
    setEditError(null)
    setEditSuccess(null)

    try {
      await updatePropertyDevelopment(editing.id, {
        name: editName,
        is_active: editActive,
        expected_name: editing.name,
        expected_is_active: editing.is_active,
      })
      setEditing(null)
      setEditSuccess('Development updated successfully.')
      setIsLoading(true)
      setRevision((value) => value + 1)
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 409) {
        setEditing(null)
        setEditError('This development was changed by another user. Directory refreshed; please review and edit again.')
        setIsLoading(true)
        setRevision((value) => value + 1)
      } else if (axios.isAxiosError(error) && error.response?.status === 422) {
        setEditError('Please provide a valid name (1–150 characters) and status.')
      } else {
        setEditError('Unable to update the development. Please try again.')
      }
    } finally {
      setIsUpdating(false)
    }
  }

  async function handleExport() {
    if (isExporting || showingLoading || !activeCompany) return
    setIsExporting(true)
    setExportError(null)
    try {
      // The server exports the entire filtered result (up to 1,000), not just this page.
      const csv = await exportPropertyDevelopmentsCsv({ search, status, sort, createdFrom, createdTo })
      const url = URL.createObjectURL(csv)
      try {
        const link = document.createElement('a')
        link.href = url
        link.download = 'property-developments.csv'
        document.body.appendChild(link)
        link.click()
        link.remove()
      } finally {
        window.setTimeout(() => URL.revokeObjectURL(url), 1000)
      }
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        setExportError('Too many matching records (maximum 1,000), or invalid filters. Narrow your search and retry.')
      } else {
        setExportError('Unable to export property developments. Please try again.')
      }
    } finally {
      setIsExporting(false)
    }
  }

  function applySearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setEditing(null)
    setEditError(null)
    setIsLoading(true)
    setUrlParams(directoryUrlParams({ search: searchInput.trim(), status, page: 1, perPage, sort, createdFrom, createdTo }))
    setRevision((value) => value + 1)
  }

  function changeStatus(value: 'all' | 'active' | 'inactive') {
    setEditing(null)
    setEditError(null)
    setIsLoading(true)
    setUrlParams(directoryUrlParams({ search, status: value, page: 1, perPage, sort, createdFrom, createdTo }))
  }

  function changePageSize(value: DirectoryPageSize) {
    setEditing(null)
    setEditError(null)
    setIsLoading(true)
    // Switching page size resets the cursor, but keeps the applied filters.
    setUrlParams(directoryUrlParams({ search, status, page: 1, perPage: value, sort, createdFrom, createdTo }))
  }

  function changeSort(value: DirectorySort) {
    setEditing(null)
    setEditError(null)
    setIsLoading(true)
    // Sorting applies to the whole filtered directory, not a single page.
    setUrlParams(directoryUrlParams({ search, status, page: 1, perPage, sort: value, createdFrom, createdTo }))
  }

  function applyDates(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const from = dateInputs.from
    const to = dateInputs.to
    if ((from !== '' && !isValidDirectoryDate(from)) ||
        (to !== '' && !isValidDirectoryDate(to)) ||
        (from !== '' && to !== '' && from > to)) {
      setDateError('Enter valid dates with Created From not after Created To (UTC).')
      return
    }
    setDateError(null)
    setEditing(null)
    setEditError(null)
    setIsLoading(true)
    setUrlParams(directoryUrlParams({ search, status, page: 1, perPage, sort, createdFrom: from, createdTo: to }))
  }

  function resetFilters() {
    setEditing(null)
    setEditError(null)
    setIsLoading(true)
    setDraftSearch({ applied: '', value: '' })
    setDraftDates({ applied: JSON.stringify(['', '']), from: '', to: '' })
    setDateError(null)
    setUrlParams(new URLSearchParams())
    setRevision((value) => value + 1)
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFieldErrors({})
    setFormError(null)
    setFormSuccess(null)
    setIsSubmitting(true)

    try {
      await createPropertyDevelopment({ code, name })
      setCode('')
      setName('')
      setFormSuccess('Development created. The directory has been refreshed.')
      resetFilters()
    } catch (error) {
      if (axios.isAxiosError<ValidationResponse>(error) && error.response?.status === 422) {
        setFieldErrors({
          code: error.response.data.errors?.code?.[0],
          name: error.response.data.errors?.name?.[0],
        })
        setFormError(error.response.data.message ?? 'Please review the submitted data.')
      } else {
        setFormError('Unable to create the property development.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const firstItem = meta && meta.total > 0
    ? (meta.current_page - 1) * meta.per_page + 1
    : 0
  const lastItem = meta
    ? Math.min(meta.current_page * meta.per_page, meta.total)
    : 0

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Property Developments</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage housing developments used across Property ERP.
        </p>
      </div>

      <div className={canCreate ? 'grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]' : 'space-y-6'}>
        <Card>
          <CardHeader>
            <CardTitle>Developments</CardTitle>
            <CardDescription>Search and filter core property master data in your active company.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <form onSubmit={applySearch} className="flex min-w-0 flex-1 items-end gap-2">
                <div className="min-w-0 flex-1 space-y-1">
                  <Label htmlFor="development-search">Search by code or name</Label>
                  <Input
                    id="development-search"
                    name="search"
                    value={searchInput}
                    maxLength={100}
                    onChange={(event) => setDraftSearch({ applied: search, value: event.target.value })}
                    placeholder="Search developments"
                  />
                </div>
                <Button type="submit" variant="outline" disabled={showingLoading}>
                  <Search /> Search
                </Button>
              </form>
              <div className="space-y-1">
                <Label htmlFor="development-status">Status</Label>
                <select
                  id="development-status"
                  value={status}
                  onChange={(event) => changeStatus(event.target.value as DirectoryStatus)}
                  className="flex h-9 w-full min-w-32 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                  <option value="all">All</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div className="space-y-1">
                <Label htmlFor="development-sort">Sort by</Label>
                <select
                  id="development-sort"
                  value={sort}
                  disabled={showingLoading}
                  onChange={(event) => changeSort(event.target.value as DirectorySort)}
                  className="flex h-9 w-full min-w-36 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                  <option value="name_asc">Name A–Z</option>
                  <option value="name_desc">Name Z–A</option>
                  <option value="code_asc">Code A–Z</option>
                  <option value="code_desc">Code Z–A</option>
                  <option value="created_desc">Newest first</option>
                  <option value="created_asc">Oldest first</option>
                </select>
              </div>
              <Button type="button" variant="ghost" onClick={resetFilters} disabled={showingLoading}>
                Clear
              </Button>
              <Button type="button" variant="outline" disabled={showingLoading || isExporting}
                onClick={() => void handleExport()}>
                <Download /> {isExporting ? 'Exporting...' : 'Export CSV'}
              </Button>
            </div>
            <form onSubmit={applyDates} className="flex flex-wrap items-end gap-3" aria-label="Created date filters (UTC)">
              <div className="space-y-1">
                <Label htmlFor="development-created-from">Created From (UTC)</Label>
                <Input id="development-created-from" type="date" value={dateInputs.from}
                  onChange={(event) => {
                    setDateError(null)
                    setDraftDates({ applied: appliedDatesKey, from: event.target.value, to: dateInputs.to })
                  }} />
              </div>
              <div className="space-y-1">
                <Label htmlFor="development-created-to">Created To (UTC)</Label>
                <Input id="development-created-to" type="date" value={dateInputs.to}
                  onChange={(event) => {
                    setDateError(null)
                    setDraftDates({ applied: appliedDatesKey, from: dateInputs.from, to: event.target.value })
                  }} />
              </div>
              <Button type="submit" variant="outline" disabled={showingLoading}>Apply dates</Button>
              <p className="text-xs text-muted-foreground">Inclusive UTC calendar dates; leave either field blank for an open-ended range.</p>
            </form>
            {dateError ? <p role="alert" className="text-sm text-destructive">{dateError}</p> : null}
            <p className="text-xs text-muted-foreground">CSV exports all matching records (up to 1,000), not only the current page.</p>
            {exportError ? <p className="text-sm text-destructive" role="alert">{exportError}</p> : null}

            {showingLoading ? (
              <p className="text-sm text-muted-foreground" role="status">Loading property developments...</p>
            ) : loadError ? (
              <div className="space-y-3" role="alert">
                <p className="text-sm text-destructive">{loadError}</p>
                <Button type="button" variant="outline" onClick={() => {
                  setIsLoading(true)
                  setRevision((value) => value + 1)
                }}>Try again</Button>
              </div>
            ) : developments.length === 0 ? (
              <div className="rounded-lg border border-dashed p-8 text-center">
                <p className="text-sm font-medium">No matching property developments</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  Try a different code, name or status filter.
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[520px] text-sm">
                  <thead>
                    <tr className="border-b text-left">
                      <th scope="col" className="pb-3 pr-4 font-medium">Code</th>
                      <th scope="col" className="pb-3 pr-4 font-medium">Name</th>
                      <th scope="col" className="pb-3 font-medium">Status</th>
                      {canUpdate ? <th scope="col" className="pb-3 pl-3 font-medium">Actions</th> : null}
                    </tr>
                  </thead>
                  <tbody>
                    {developments.map((development) => (
                      <tr key={development.id} className="border-b last:border-0">
                        <td className="py-3 pr-4 font-mono text-xs font-medium">{development.code}</td>
                        <td className="py-3 pr-4">
                          <Link to={`/master-data/property-developments/${development.id}${directoryUrlSearch({ search, status, page, perPage, sort, createdFrom, createdTo })}`}
                            className="font-medium text-primary underline-offset-4 hover:underline focus-visible:underline">
                            {development.name}
                          </Link>
                        </td>
                        <td className="py-3">
                          <span className="inline-flex rounded-full bg-muted px-2 py-1 text-xs font-medium">
                            {development.is_active ? 'Active' : 'Inactive'}
                          </span>
                        </td>
                        {canUpdate ? (
                          <td className="py-3 pl-3">
                            <Button type="button" size="sm" variant="outline"
                              disabled={isUpdating} onClick={() => beginEdit(development)}
                              aria-label={`Edit ${development.code}`}>
                              Edit
                            </Button>
                          </td>
                        ) : null}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {editError ? <p role="alert" className="text-sm text-destructive">{editError}</p> : null}
            {editSuccess ? <p role="status" className="text-sm">{editSuccess}</p> : null}

            {canUpdate && editing && !showingLoading && developments.some((item) => item.id === editing.id) ? (
              <form onSubmit={(event) => void saveEdit(event)}
                className="space-y-3 rounded-lg border p-4" aria-label={`Edit ${editing.code}`}>
                <div>
                  <p className="text-sm font-semibold">Edit {editing.code}</p>
                  <p className="text-xs text-muted-foreground">The development code and company cannot be changed.</p>
                </div>
                <div className="space-y-1">
                  <Label htmlFor="edit-development-name">Name</Label>
                  <Input id="edit-development-name" value={editName} maxLength={150}
                    onChange={(event) => setEditName(event.target.value)} disabled={isUpdating} required />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="edit-development-status">Status</Label>
                  <select id="edit-development-status" value={editActive ? 'active' : 'inactive'}
                    onChange={(event) => setEditActive(event.target.value === 'active')}
                    disabled={isUpdating}
                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                  {!editActive ? (
                    <p className="text-xs text-muted-foreground">Inactive developments remain stored and visible when using the All or Inactive filter.</p>
                  ) : null}
                </div>
                <div className="flex gap-2">
                  <Button type="submit" disabled={isUpdating || !editName.trim()}>
                    {isUpdating ? 'Saving...' : 'Save changes'}
                  </Button>
                  <Button type="button" variant="outline" disabled={isUpdating}
                    onClick={() => { setEditing(null); setEditError(null) }}>Cancel</Button>
                </div>
              </form>
            ) : null}

            {!showingLoading && !loadError && meta ? (
              <div className="flex flex-col gap-2 border-t pt-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                <p className="text-muted-foreground" role="status">
                  Showing {firstItem}–{lastItem} of {meta.total} developments
                </p>
                <div className="flex flex-wrap items-center gap-2">
                  <Label htmlFor="development-page-size" className="text-sm">Rows per page</Label>
                  <select
                    id="development-page-size"
                    aria-label="Rows per page"
                    value={perPage}
                    disabled={showingLoading}
                    onChange={(event) => changePageSize(Number(event.target.value) as DirectoryPageSize)}
                    className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                  >
                    <option value={10}>10</option>
                    <option value={25}>25</option>
                    <option value={50}>50</option>
                  </select>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => {
                      setEditing(null)
                      setIsLoading(true)
                      setUrlParams(directoryUrlParams({ search, status, page: Math.max(1, page - 1), perPage, sort, createdFrom, createdTo }))
                    }}
                  >Previous</Button>
                  <span className="text-muted-foreground">Page {meta.current_page} of {meta.last_page}</span>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={page >= meta.last_page}
                    onClick={() => {
                      setEditing(null)
                      setIsLoading(true)
                      setUrlParams(directoryUrlParams({ search, status, page: page + 1, perPage, sort, createdFrom, createdTo }))
                    }}
                  >Next</Button>
                </div>
              </div>
            ) : null}
          </CardContent>
        </Card>

        {canCreate ? (
          <Card>
            <CardHeader>
              <CardTitle>Add Development</CardTitle>
              <CardDescription>Create a new property development.</CardDescription>
            </CardHeader>
            <CardContent>
              <form className="space-y-4" onSubmit={handleSubmit}>
                <div className="space-y-2">
                  <Label htmlFor="development-code">Code</Label>
                  <Input
                    id="development-code"
                    name="code"
                    value={code}
                    onChange={(event) => setCode(event.target.value)}
                    placeholder="e.g. GRIYA-01"
                    aria-invalid={Boolean(fieldErrors.code)}
                    disabled={isSubmitting}
                  />
                  {fieldErrors.code ? <p className="text-xs text-destructive">{fieldErrors.code}</p> : null}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="development-name">Name</Label>
                  <Input
                    id="development-name"
                    name="name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder="e.g. Griya Residence"
                    aria-invalid={Boolean(fieldErrors.name)}
                    disabled={isSubmitting}
                  />
                  {fieldErrors.name ? <p className="text-xs text-destructive">{fieldErrors.name}</p> : null}
                </div>
                {formError ? <p className="text-sm text-destructive" role="alert">{formError}</p> : null}
                {formSuccess ? <p className="text-sm" role="status">{formSuccess}</p> : null}
                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  <Plus /> {isSubmitting ? 'Creating...' : 'Add Development'}
                </Button>
              </form>
            </CardContent>
          </Card>
        ) : null}
      </div>
    </div>
  )
}
