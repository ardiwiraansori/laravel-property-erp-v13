export function PlatformWriteNotice({ enabled, loading, error }: {
  enabled: boolean; loading: boolean; error: boolean
}) {
  return <p role="status" className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
    {error ? 'Cannot verify platform write permission. All changes remain locked.' :
      loading ? 'Checking write authorization...' : enabled ?
        'Platform write authorization is active. Every change is audited.' :
        'Management controls are staged, but changes are locked until platform MFA is enabled. No data will be changed.'}
  </p>
}
