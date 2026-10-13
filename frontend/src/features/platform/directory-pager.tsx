import { Button } from '@/components/ui/button'
import type { PaginationMeta } from '@/features/platform/platform-api'

type Props = {
  meta: PaginationMeta | null
  loading: boolean
  onPageChange: (page: number) => void
}

export function DirectoryPager({ meta, loading, onPageChange }: Props) {
  if (!meta) return null
  return <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4 text-xs text-muted-foreground">
    <span>{meta.total} results · Page {meta.current_page} of {meta.last_page}</span>
    <div className="flex gap-2">
      <Button variant="outline" size="sm" disabled={loading || meta.current_page <= 1}
        onClick={() => onPageChange(meta.current_page - 1)}>Previous</Button>
      <Button variant="outline" size="sm" disabled={loading || meta.current_page >= meta.last_page}
        onClick={() => onPageChange(meta.current_page + 1)}>Next</Button>
    </div>
  </div>
}
