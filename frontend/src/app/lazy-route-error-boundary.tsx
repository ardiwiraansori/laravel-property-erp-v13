import { Component } from 'react'
import type { ReactNode } from 'react'

type Props = { children: ReactNode }
type State = { failed: boolean }

/**
 * Isolates a failed lazy route without exposing exception details in the UI.
 * A rejected React.lazy() import is cached, so retrying requires a fresh page.
 * A reload is ALWAYS user-initiated; never automatically loop on failure.
 */
export class LazyRouteErrorBoundary extends Component<Props, State> {
  state: State = { failed: false }

  static getDerivedStateFromError(): State {
    return { failed: true }
  }

  render() {
    if (!this.state.failed) {
      return this.props.children
    }

    return (
      <div role="alert" className="flex flex-col items-start gap-3 rounded-lg border p-4">
        <p className="text-sm font-medium">This page could not be displayed.</p>
        <p className="text-sm text-muted-foreground">
          The page may have changed since you opened the application. Reload to try again.
        </p>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="inline-flex h-9 items-center justify-center rounded-md border px-4 text-sm font-medium hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          Reload page
        </button>
      </div>
    )
  }
}
