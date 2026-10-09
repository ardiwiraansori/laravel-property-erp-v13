# Property ERP v13 — Checkpoint 7.5 / Batch 4B.7

**Status pada 9 Oktober 2026:** implementasi dan pengujian Batch 4B.7 **lulus** berdasarkan output CMD dan verifikasi browser yang dilaporkan. **Final Git review/commit/push belum dilaksanakan.**

**Source dasar:** repository `ardiwiraansori/laravel-property-erp-v13`, branch `main`, commit `f8b163a` (sebelum perubahan lokal Batch 4B.7).

## Tujuan dan lingkup

Menguji perpindahan perusahaan **PT Arvya Labs (A) → ERP Tenant E2E Test (B) → PT Arvya Labs (A)** dengan satu user yang menjadi anggota kedua perusahaan, tetapi mempunyai hak akses yang berbeda. Memastikan `X-Company-ID`, Spatie Teams/RBAC, navigasi, data Property Development, dan authorization backend mengikuti perusahaan aktif.

### Kondisi perusahaan saat pengujian

| Atribut | Perusahaan A | Perusahaan B |
|---|---|---|
| Nama | PT Arvya Labs | ERP Tenant E2E Test |
| Code | `ARVYA` | `E2E-TEST` |
| Company ID lokal | `1` | `2` (hasil auto-increment saat provisioning; jangan hardcode untuk database lain) |
| Membership | User ID 1 | User ID 1 |
| Role Owner | Ada, scoped ke A | Tidak ada |
| Direct permission | Tidak ada | `dashboard.view`, `master-data.view`, `master-data.property-developments.view` |
| Permission Create Property Development | Ada melalui role Owner | Tidak ada |
| Property Development | `TEST-01 — Test Residence` | Kosong |

## File implementasi lokal Batch 4B.7

| File | Status relatif commit dasar | Fungsi |
|---|---|---|
| `app/Console/Commands/ProvisionE2eTestCompany.php` | Baru | Provisioning perusahaan B, default read-only, `--apply` eksplisit |
| `tests/Feature/Api/ScopedE2eViewPermissionTest.php` | Baru | Regression test isolasi A → B → A, termasuk penolakan Create B |
| `frontend/src/components/layout/company-switcher.tsx` | Diubah | Memperbaiki struktur `DropdownMenuGroup` untuk Base UI |
| `docs/batch-4b7-e2e-test-provisioning.md` | Baru | Catatan implementasi, risiko, dan hasil verifikasi (dokumen ini) |

`HOTFIX_4B7_README.txt` adalah petunjuk pemasangan sementara di komputer lokal dan **tidak perlu dimasukkan ke commit**. Jangan hapus tanpa persetujuan pemilik project.

Tidak ada migration, seeder, atau perubahan file authentication/backend tenancy lama pada Batch 4B.7.

## Perlindungan Artisan Command

- Tanpa opsi, command hanya melakukan preview **read-only**.
- Penambahan data memerlukan kedua opsi `--apply --confirm=E2E-TEST`.
- Dibatasi ke `APP_ENV=local`, driver database **`mysql` atau `mariadb`**, dan database `property_erp_demo_v13`.
- Memverifikasi ARVYA (ID 1, nama, kode, status), administrator user ID 1 dan keanggotaannya, serta keberadaan assignment Owner scoped ke ARVYA.
- Memastikan Spatie Teams memakai `company_id`; menolak assignment role/permission unscoped (NULL).
- Menggunakan tiga permission View yang sudah ada; tidak membuat definisi role atau permission baru.
- Saat pertama diterapkan, hanya melakukan INSERT untuk satu perusahaan, satu membership, dan tiga direct permission yang semuanya memiliki `company_id` perusahaan B.
- Tidak membuat Property Development ataupun memberi Owner kepada B.
- INSERT berada dalam database transaction; invariant diverifikasi sebelum commit transaksi. Bila pemeriksaan gagal, transaksi di-rollback.
- Bila `E2E-TEST` sudah ada dan sesuai invariant, command menyelesaikan verifikasi tanpa perubahan; jika berbeda, command menolak memperbaiki atau menghapus secara otomatis.
- Setelah provisioning sukses, cache definisi Spatie Permission di-refresh.

**Risiko operasional:** command ini khusus database **development lokal**, bukan untuk produksi. Database transaction tidak menggantikan backup. Jangan mengulang mode `--apply`, menghapus perusahaan B, atau mengubah data ARVYA tanpa rencana dan persetujuan terpisah.

## Riwayat pelaksanaan

1. Preview read-only berhasil setelah hotfix deteksi driver MariaDB.
2. Pengguna menjalankan `herd php artisan company:provision-e2e-test --apply --confirm=E2E-TEST` atas persetujuan eksplisit. Output: `SUCCESS: E2E-TEST provisioned and verified.` Company ID lokal yang diberikan database: **2**.
3. Base UI Company Switcher sempat menghasilkan `MenuGroupContext is missing`. Diperbaiki dengan membungkus label dan menu item di `DropdownMenuGroup`. Dropdown dan switching lalu berhasil diuji di browser.
4. Pengujian telah dijalankan terpisah setelah implementasi dan hotfix.

## Hasil pengujian yang dilaporkan

| Pengujian | Hasil |
|---|---|
| `herd php artisan test` | **20 passed, 79 assertions** |
| `frontend/npm run lint` | **Lulus**, tidak ada error/warning ESLint pada output |
| `frontend/npm run build` | **Lulus** (`tsc -b && vite build`) |
| Warning Vite bundle sekitar 598 KB | Non-blocking; optimasi ditunda |
| Company Switcher | Berhasil menampilkan A dan B dan melakukan switch |
| Navigasi tenant | Menu perusahaan B hanya mengikuti tiga permission View |
| Property Development A | `TEST-01 — Test Residence` muncul; form Add Development tersedia |
| Property Development B | List kosong; form Add Development tidak muncul |
| HTTP GET B | `X-Company-ID: 2`, response JSON `data: []` |
| HTTP GET A setelah kembali | `X-Company-ID: 1`, response berisi `TEST-01` |
| HTTP POST Create B | **403 Forbidden**, diuji langsung dari browser |
| Audit RBAC | 2 companies, 2 memberships, 1 role definition, 8 permission definitions, 1 role assignment, 3 direct permission assignments |
| Query `model_has_roles` | Assignment Owner user 1 memiliki `company_id = 1` |
| Query `model_has_permissions` | Tiga direct permission user 1 memiliki `company_id = 2` |
| Assignment tanpa `company_id` | `0` untuk role dan `0` untuk direct permission |
| `git diff HEAD --check` sebelum staging | Tidak melaporkan masalah whitespace pada file tracked |

**Catatan audit:** keluaran `company:rbac-audit` menampilkan `member_companies: 1,2` untuk assignment milik user 1 karena yang ditampilkan adalah seluruh membership user, **bukan scope assignment**. Scope sebenarnya telah dicek langsung melalui query `model_has_roles.company_id` dan `model_has_permissions.company_id`.

**Cakupan kesimpulan:** hasil di atas membuktikan pengujian yang dilakukan; tidak menyatakan semua kemungkinan kebocoran antar-tenant telah diuji. E2E dan regression test sudah mencakup skenario utama Checkpoint 7.5.

## Finalisasi Git (belum dijalankan)

- Review perubahan `company-switcher.tsx` telah dilakukan: perubahan fungsional hanya membungkus menu dalam `DropdownMenuGroup`; selebihnya perapian format.
- Command provisioning, regression test, dan dokumen ini sudah dibaca untuk final source review.
- File `HOTFIX_4B7_README.txt` sebaiknya tidak di-stage.
- Sebelum commit, stage **hanya** empat file implementasi di atas, kemudian jalankan pemeriksaan staged diff (`git diff --cached --check` dan `git diff --cached --stat`).
- **Tidak melakukan `git commit` atau `git push` tanpa izin eksplisit dari pemilik project.**
- Checkpoint 8 (Property Units) belum dimulai dan tetap menunggu penutupan resmi Checkpoint 7.5.
