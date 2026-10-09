Property ERP v13 — Batch 4B.7 Hotfix MariaDB Driver
================================================
SOURCE REFERENCE: main @ f8b163a

PERUBAHAN (HANYA 1 FILE PHP):
REPLACE: app/Console/Commands/ProvisionE2eTestCompany.php

Sumber error:
- Versi sebelumnya hanya menerima driver "mysql".
- Laravel 13 juga menyediakan driver "mariadb".
- Hotfix mengizinkan mysql atau mariadb saja, tetap wajib database
  property_erp_demo_v13 dan APP_ENV=local.
- Semua pemeriksaan membership, company ARVYA, permission, dan
  default read-only masih utuh. Tidak ada migration atau SQL DML
  yang dijalankan saat menyiapkan ZIP ini.

LANGKAH (Windows CMD):
1. Extract file ZIP ke folder root repository, pilih Replace untuk
   app/Console/Commands/ProvisionE2eTestCompany.php saja.
2. Buka CMD:
   cd /d C:\Users\ASUS\Projects\laravel-property-erp-v13
   herd php artisan company:provision-e2e-test
3. Kirimkan output preview CMD. JANGAN gunakan --apply terlebih dulu.

INFO:
- Testing Laravel (herd php artisan test) adalah Step 4 tersendiri.
- Jika preview tetap gagal, pesan error sekarang menampilkan driver
  dan nama database aktual supaya penyebabnya dapat diperiksa.
- File ini tidak mengubah data di database secara otomatis.
