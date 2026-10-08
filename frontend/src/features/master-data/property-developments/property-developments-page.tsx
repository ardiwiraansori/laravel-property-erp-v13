import axios from 'axios'
import { Plus } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'

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
import { useCompany } from '@/features/company/company-state'
import {
  hasPermission,
  permissions,
} from '@/features/auth/permissions'
import {
  createPropertyDevelopment,
  getPropertyDevelopments,
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

  const [developments, setDevelopments] = useState<
    PropertyDevelopment[]
  >([])
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [fieldErrors, setFieldErrors] =
    useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const canCreate =
    activeCompany !== null &&
    hasPermission(
      activeCompany.permissions,
      permissions.propertyDevelopmentsCreate,
    )

  const loadDevelopments = useCallback(async () => {
    setIsLoading(true)
    setLoadError(null)

    try {
      const data = await getPropertyDevelopments()
      setDevelopments(data)
    } catch {
      setLoadError(
        'Unable to load property developments. Please try again.',
      )
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true

    void getPropertyDevelopments()
      .then((data) => {
        if (!active) {
          return
        }

        setDevelopments(data)
        setLoadError(null)
      })
      .catch(() => {
        if (!active) {
          return
        }

        setLoadError(
          'Unable to load property developments. Please try again.',
        )
      })
      .finally(() => {
        if (active) {
          setIsLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    setFieldErrors({})
    setFormError(null)
    setIsSubmitting(true)

    try {
      await createPropertyDevelopment({
        code,
        name,
      })

      setCode('')
      setName('')

      await loadDevelopments()
    } catch (error) {
      if (
        axios.isAxiosError<ValidationResponse>(error) &&
        error.response?.status === 422
      ) {
        setFieldErrors({
          code: error.response.data.errors?.code?.[0],
          name: error.response.data.errors?.name?.[0],
        })

        setFormError(
          error.response.data.message ??
            'Please review the submitted data.',
        )
      } else {
        setFormError(
          'Unable to create the property development.',
        )
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          Property Developments
        </h1>

        <p className="mt-1 text-sm text-muted-foreground">
          Manage housing developments used across Property ERP.
        </p>
      </div>

      <div
        className={
          canCreate
            ? 'grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]'
            : 'space-y-6'
        }
      >
        <Card>
          <CardHeader>
            <CardTitle>Developments</CardTitle>

            <CardDescription>
              Core property development master data.
            </CardDescription>
          </CardHeader>

          <CardContent>
            {isLoading ? (
              <p className="text-sm text-muted-foreground">
                Loading property developments...
              </p>
            ) : loadError ? (
              <div className="space-y-3">
                <p className="text-sm text-destructive">
                  {loadError}
                </p>

                <Button
                  type="button"
                  variant="outline"
                  onClick={() => void loadDevelopments()}
                >
                  Try again
                </Button>
              </div>
            ) : developments.length === 0 ? (
              <div className="rounded-lg border border-dashed p-8 text-center">
                <p className="text-sm font-medium">
                  No property developments yet
                </p>

                <p className="mt-1 text-sm text-muted-foreground">
                  Master data will appear here after it is added.
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[520px] text-sm">
                  <thead>
                    <tr className="border-b text-left">
                      <th className="pb-3 pr-4 font-medium">
                        Code
                      </th>
                      <th className="pb-3 pr-4 font-medium">
                        Name
                      </th>
                      <th className="pb-3 font-medium">
                        Status
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    {developments.map((development) => (
                      <tr
                        key={development.id}
                        className="border-b last:border-0"
                      >
                        <td className="py-3 pr-4 font-mono text-xs font-medium">
                          {development.code}
                        </td>

                        <td className="py-3 pr-4">
                          {development.name}
                        </td>

                        <td className="py-3">
                          <span className="inline-flex rounded-full bg-muted px-2 py-1 text-xs font-medium">
                            {development.is_active
                              ? 'Active'
                              : 'Inactive'}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        {canCreate ? (
          <Card>
            <CardHeader>
              <CardTitle>Add Development</CardTitle>

              <CardDescription>
                Create a new property development.
              </CardDescription>
            </CardHeader>

            <CardContent>
              <form
                className="space-y-4"
                onSubmit={handleSubmit}
              >
                <div className="space-y-2">
                  <Label htmlFor="development-code">
                    Code
                  </Label>

                  <Input
                    id="development-code"
                    name="code"
                    value={code}
                    onChange={(event) =>
                      setCode(event.target.value)
                    }
                    placeholder="e.g. GRIYA-01"
                    aria-invalid={Boolean(fieldErrors.code)}
                    disabled={isSubmitting}
                  />

                  {fieldErrors.code ? (
                    <p className="text-xs text-destructive">
                      {fieldErrors.code}
                    </p>
                  ) : null}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="development-name">
                    Name
                  </Label>

                  <Input
                    id="development-name"
                    name="name"
                    value={name}
                    onChange={(event) =>
                      setName(event.target.value)
                    }
                    placeholder="e.g. Griya Residence"
                    aria-invalid={Boolean(fieldErrors.name)}
                    disabled={isSubmitting}
                  />

                  {fieldErrors.name ? (
                    <p className="text-xs text-destructive">
                      {fieldErrors.name}
                    </p>
                  ) : null}
                </div>

                {formError ? (
                  <p className="text-sm text-destructive">
                    {formError}
                  </p>
                ) : null}

                <Button
                  type="submit"
                  className="w-full"
                  disabled={isSubmitting}
                >
                  <Plus />
                  {isSubmitting
                    ? 'Creating...'
                    : 'Add Development'}
                </Button>
              </form>
            </CardContent>
          </Card>
        ) : null}
      </div>
    </div>
  )
}
