# Maintenance setelah login

Upload perubahan kode ke hosting. Jika route sebelumnya di-cache, hapus file `bootstrap/cache/routes-*.php` melalui File Manager agar route baru terbaca.

Login, lalu buka URL berikut satu per satu di browser:

1. `/maintenance/migrate` untuk menjalankan `migrate --force`, termasuk migration `tenants.setup_step` yang belum diterapkan.
2. `/maintenance/optimize-clear` untuk menjalankan `optimize:clear`.
3. `/maintenance/storage-link` untuk menjalankan `storage:link`.

Route menggunakan GET dan session login, tanpa token. Setup tidak perlu selesai terlebih dahulu. Hak akses mengikuti `canRunMaintenance()`: Developer, atau Superadmin selama belum ada Developer aktif pada tenant yang sama. Akun nonaktif ditolak.

Respons menampilkan `command`, `exit_code`, dan `output`. Lanjutkan jika `exit_code` bernilai `0`. Jika gagal, periksa output dan `storage/logs/laravel.log`. Setelah selesai, buka kembali `/setup`.

Jika pernah membuat `storage/app/maintenance-token`, file tersebut dapat dihapus karena tidak lagi digunakan.
