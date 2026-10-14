import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useCompany } from '@/features/company/company-state'
import {
  browserFamily, categories, errors, getMyDiagnosticProgress, getMyDiagnosticReports, impacts, modules,
  submitDiagnosticReport, viewportClass,
} from '@/features/diagnostics/diagnostic-api'
import type {
  DiagnosticCategory, DiagnosticErrorKind, DiagnosticImpact, DiagnosticModule,
  DiagnosticPage, DiagnosticReport, ReportReceipt, TenantDiagnosticProgress,
} from '@/features/diagnostics/diagnostic-api'

const selectClass = 'h-9 w-full rounded-lg border border-input bg-background px-3 text-sm'

function statusLabel(value: string): string {
  return value.replaceAll('_', ' ')
}

function displayDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('id-ID') : '—'
}

export function ReportProblemPage() {
  const { activeCompany } = useCompany()
  const companyKey = String(activeCompany?.id ?? '')
  const [category, setCategory] = useState<DiagnosticCategory>('page_error')
  const [module, setModule] = useState<DiagnosticModule>('dashboard')
  const [impact, setImpact] = useState<DiagnosticImpact>('partial')
  const [errorKind, setErrorKind] = useState<DiagnosticErrorKind>('unknown')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [receipt, setReceipt] = useState<ReportReceipt | null>(null)
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [listing, setListing] = useState<DiagnosticPage<DiagnosticReport> | null>(null)
  const [listingCompany, setListingCompany] = useState('')
  const [listError, setListError] = useState(false)
  const [listLoading, setListLoading] = useState(true)
  const [selection, setSelection] = useState<{ publicId: string; company: string } | null>(null)
  const [progress, setProgress] = useState<TenantDiagnosticProgress | null>(null)
  const [progressPage, setProgressPage] = useState(1)
  const [progressLoading, setProgressLoading] = useState(false)
  const [progressError, setProgressError] = useState(false)
  const [progressRevision, setProgressRevision] = useState(0)

  const refresh = useCallback(() => setRevision(value => value + 1), [])
  const selectedId = selection?.company === companyKey ? selection.publicId : null
  const visibleListing = listingCompany === companyKey ? listing : null
  const visibleListLoading = listLoading || listingCompany !== companyKey

  useEffect(() => {
    let active = true
    void getMyDiagnosticReports(page).then(data => {
      if (active) { setListing(data); setListingCompany(companyKey); setListError(false); setListLoading(false) }
    }).catch(() => { if (active) { setListingCompany(companyKey); setListError(true); setListLoading(false) } })
    return () => { active = false }
  }, [page, revision, companyKey])

  useEffect(() => {
    if (!selectedId) return
    let active = true
    void getMyDiagnosticProgress(selectedId, progressPage).then(data => {
      if (active) { setProgress(data); setProgressError(false); setProgressLoading(false) }
    }).catch(() => { if (active) { setProgressError(true); setProgressLoading(false) } })
    return () => { active = false }
  }, [selectedId, progressPage, progressRevision])

  function inspect(publicId: string) {
    if (selectedId === publicId) {
      setSelection(null)
      setProgress(null)
      return
    }
    setSelection({ publicId, company: companyKey })
    setProgress(null)
    setProgressPage(1)
    setProgressError(false)
    setProgressLoading(true)
    setProgressRevision(value => value + 1)
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (loading) return
    setLoading(true)
    setError(null)
    setReceipt(null)
    try {
      const result = await submitDiagnosticReport({
        category, module, impact, error_kind: errorKind,
        browser_family: browserFamily(), viewport: viewportClass(),
      })
      setReceipt(result)
      setPage(1)
      refresh()
    } catch {
      setError('Laporan belum berhasil dikirim. Periksa koneksi dan coba lagi.')
    } finally {
      setLoading(false)
    }
  }

  return <div className="mx-auto w-full max-w-4xl space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <div><h1 className="text-2xl font-semibold tracking-tight">Laporkan Masalah</h1>
        <p className="mt-1 text-sm text-muted-foreground">Perusahaan aktif: {activeCompany?.name ?? '—'}</p>
      </div>
      <Button variant="outline" render={<Link to="/dashboard" />}>Kembali</Button>
    </div>
    <Card>
      <CardHeader>
        <CardTitle>Buat laporan teknis</CardTitle>
        <CardDescription>Pilih kategori dan modul yang bermasalah. Sistem hanya mengirim pilihan berikut dan klasifikasi browser/layar, tanpa screenshot, URL, isi formulir, atau data pelanggan.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={event => void submit(event)} className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="space-y-1.5 text-sm font-medium">Kategori masalah
              <select className={selectClass} value={category} onChange={event => setCategory(event.target.value as DiagnosticCategory)}>
                {categories.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label className="space-y-1.5 text-sm font-medium">Modul
              <select className={selectClass} value={module} onChange={event => setModule(event.target.value as DiagnosticModule)}>
                {modules.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label className="space-y-1.5 text-sm font-medium">Dampak
              <select className={selectClass} value={impact} onChange={event => setImpact(event.target.value as DiagnosticImpact)}>
                {impacts.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label className="space-y-1.5 text-sm font-medium">Jenis error yang terlihat
              <select className={selectClass} value={errorKind} onChange={event => setErrorKind(event.target.value as DiagnosticErrorKind)}>
                {errors.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
          </div>
          <p className="text-xs text-muted-foreground">Privasi: jangan kirim password, token, atau data bisnis melalui saluran lain. Laporan ini tidak menyediakan kolom teks bebas atau lampiran.</p>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          {receipt && <p role="status" className="rounded-lg border p-3 text-sm">Laporan terkirim. ID pelacakan: <code className="font-mono">{receipt.public_id}</code></p>}
          <Button type="submit" disabled={loading}>{loading ? 'Mengirim...' : 'Kirim laporan'}</Button>
        </form>
      </CardContent>
    </Card>
    <Card>
      <CardHeader><CardTitle>Laporan saya</CardTitle><CardDescription>Hanya laporan Anda di perusahaan yang sedang aktif. Klik Lihat progres untuk melihat perubahan status.</CardDescription></CardHeader>
      <CardContent className="space-y-3">
        {visibleListLoading && !visibleListing ? <p role="status" className="text-sm text-muted-foreground">Memuat laporan...</p> :
          listError ? <p role="alert" className="text-sm text-destructive">Gagal memuat daftar. <Button variant="outline" size="sm" onClick={() => { setListLoading(true); refresh() }}>Coba lagi</Button></p> :
          visibleListing?.data.length === 0 ? <p className="text-sm text-muted-foreground">Belum ada laporan.</p> :
          <div className="overflow-x-auto"><table className="w-full min-w-[620px] text-left text-sm">
            <thead><tr className="border-b"><th className="py-2">ID Laporan</th><th>Kategori</th><th>Modul</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>{visibleListing?.data.map(item => <tr className="border-b last:border-0" key={item.public_id}>
              <td className="py-2 font-mono text-xs">{item.public_id}</td><td>{item.category}</td><td>{item.module}</td><td>{statusLabel(item.status)}</td>
              <td><Button variant="outline" size="sm" aria-expanded={selectedId === item.public_id}
                onClick={() => inspect(item.public_id)}>{selectedId === item.public_id ? 'Tutup detail' : 'Lihat progres'}</Button></td>
            </tr>)}</tbody>
          </table></div>}
        <div className="flex items-center justify-end gap-2 text-xs text-muted-foreground">
          <span>Halaman {visibleListing?.meta.current_page ?? 1} / {visibleListing?.meta.last_page ?? 1}</span>
          <Button variant="outline" size="sm" disabled={visibleListLoading || page <= 1}
            onClick={() => { setListLoading(true); setPage(v => v - 1) }}>Sebelumnya</Button>
          <Button variant="outline" size="sm" disabled={visibleListLoading || page >= (visibleListing?.meta.last_page ?? 1)}
            onClick={() => { setListLoading(true); setPage(v => v + 1) }}>Berikutnya</Button>
        </div>
      </CardContent>
    </Card>
    {selectedId && <Card>
      <CardHeader><CardTitle>Progres laporan</CardTitle>
        <CardDescription>Riwayat status laporan Anda saja. Waktu perubahan status bukan bukti komunikasi langsung atau janji SLA.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {progressLoading && <p role="status" className="text-sm text-muted-foreground">Memuat progres...</p>}
        {progressError && <p role="alert" className="text-sm text-destructive">Gagal memuat progres. <Button size="sm" variant="outline"
          onClick={() => { setProgressLoading(true); setProgressError(false); setProgressRevision(v => v + 1) }}>Coba lagi</Button></p>}
        {progress?.data.public_id === selectedId && !progressError && !progressLoading && <>
          <p className="break-all font-mono text-xs">{progress.data.public_id}</p>
          <p className="text-sm">Status saat ini: <span className="font-semibold">{statusLabel(progress.data.status)}</span></p>
          <p className="text-xs text-muted-foreground">Dibuat: {displayDate(progress.data.created_at)} · Diperbarui: {displayDate(progress.data.updated_at)}</p>
          <h3 className="text-sm font-semibold">Riwayat perubahan status</h3>
          {progress.history.length === 0 ? <p className="text-sm text-muted-foreground">Belum ada perubahan status pada laporan ini.</p> :
            <ol className="space-y-2">{progress.history.map((entry, index) => <li key={`${entry.created_at}-${index}`} className="rounded-lg border p-3 text-sm">
              <p className="font-medium">{statusLabel(entry.from_status)} → {statusLabel(entry.to_status)}</p>
              <p className="text-xs text-muted-foreground">{displayDate(entry.created_at)}</p>
            </li>)}</ol>}
          <div className="flex items-center justify-end gap-2 text-xs text-muted-foreground">
            <span>Riwayat {progress.meta.current_page} / {progress.meta.last_page} ({progress.meta.total} perubahan)</span>
            <Button variant="outline" size="sm" disabled={progressLoading || progressPage <= 1}
              onClick={() => { setProgressLoading(true); setProgressPage(v => v - 1) }}>Sebelumnya</Button>
            <Button variant="outline" size="sm" disabled={progressLoading || progressPage >= progress.meta.last_page}
              onClick={() => { setProgressLoading(true); setProgressPage(v => v + 1) }}>Berikutnya</Button>
          </div>
        </>}
      </CardContent>
    </Card>}
  </div>
}
