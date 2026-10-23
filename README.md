# E-Teller Bank Mini Sekolah

Backend API untuk sistem perbankan internal sekolah (proyek UKK): setor dan tarik
tunai siswa, jurnal akuntansi entri ganda, laporan harian teller dengan approval
supervisor, dan pemindahan kas ke brankas. Spesifikasi lengkap ada di
[PRD.md](PRD.md); urutan pengerjaan di [RENCANA_PENGEMBANGAN.md](RENCANA_PENGEMBANGAN.md).

**Teknologi:** Laravel 13 (PHP 8.3+), MariaDB, Laravel Sanctum (token API), Pest.

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Buat database dan user MariaDB, lalu sesuaikan `.env`:

```sql
CREATE DATABASE ukk_laravel;
CREATE USER 'bank-db-user'@'localhost' IDENTIFIED BY 'ganti-password';
GRANT ALL PRIVILEGES ON ukk_laravel.* TO 'bank-db-user'@'localhost';
```

```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ukk_laravel
DB_USERNAME=bank-db-user
DB_PASSWORD=ganti-password

APP_TIMEZONE=Asia/Jakarta
BANK_MIN_BALANCE=10000     # saldo minimum setelah penarikan (Rupiah)
SANCTUM_EXPIRATION=480     # masa berlaku token dalam menit
```

Jalankan migration, seeder, dan server:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Seeder aman dijalankan ulang. Saldo awal nasabah demo dibuat lewat transaksi
setor sungguhan, sehingga saldo, mutasi, dan jurnal selalu konsisten.

## Akun demo

| Aktor | Login | Password / PIN |
| --- | --- | --- |
| Administrator | `admin` | `password` |
| Supervisor | `supervisor` | `password` |
| Teller | `teller` | `password` |
| Nasabah | NIS `20260001`–`20260003` atau rekening `1000000001`–`1000000003` | PIN `123456` |

Ganti semua password dan PIN demo sebelum dipakai di luar lingkungan ujian.

## Pengujian

```bash
php artisan test
```

Test memakai SQLite in-memory (lihat `phpunit.xml`). Untuk memastikan constraint
`CHECK` dan row lock juga berjalan di MariaDB, buat database `ukk_laravel_test`
lalu jalankan:

```bash
DB_CONNECTION=mariadb DB_DATABASE=ukk_laravel_test php artisan test
```

## Autentikasi

Semua endpoint (kecuali login) membutuhkan header `Authorization: Bearer <token>`
dan `Accept: application/json`.

```http
POST /api/staff/login      { "username": "teller", "password": "password" }
POST /api/customer/login   { "identifier": "20260001", "pin": "123456" }
```

Respons login berisi `data.token`. Login dibatasi 5 percobaan per menit per
akun dan IP.

## Endpoint

| Aktor | Method & URL | Keterangan |
| --- | --- | --- |
| Staf | `GET /api/staff/me`, `POST /api/staff/logout` | Profil dan logout |
| Staf | `GET /api/majors` | Daftar jurusan |
| Admin | `GET/POST /api/admin/staff`, `GET/PATCH/DELETE /api/admin/staff/{id}` | Kelola teller & supervisor. `DELETE` menonaktifkan akun |
| Admin | `POST /api/admin/customers`, `PATCH /api/admin/customers/{id}` | Buka rekening (nomor dibuat sistem), ubah data/status/PIN |
| Admin | `POST /api/admin/customers/{id}/deactivate` | Kelulusan: status `inactive` + soft delete |
| Admin, Supervisor | `GET /api/customers`, `GET /api/customers/{id}` | Daftar rekening (`search`, `major_id`, `class`, `status`, `trashed=with\|only`) |
| Admin, Supervisor | `GET /api/audit/transactions`, `GET /api/audit/transactions/{id}` | Log transaksi beserta jurnal (read-only) |
| Admin, Supervisor | `GET /api/audit/journals`, `GET /api/audit/journals/summary` | Jurnal akuntansi dan neraca saldo (read-only) |
| Teller | `GET /api/teller/customers/lookup?identifier=` | Cari nasabah via nomor rekening/NIS |
| Teller | `POST /api/teller/deposits`, `POST /api/teller/withdrawals` | `{ "account_number", "amount" }` |
| Teller | `GET /api/teller/transactions?date=` | Transaksi milik teller per hari |
| Teller | `GET /api/teller/daily-reports/preview?date=` | Draf penutupan kas (untuk dicetak) |
| Teller | `GET/POST /api/teller/daily-reports`, `GET /api/teller/daily-reports/{id}` | Kirim dan lihat laporan harian |
| Teller | `GET /api/teller/drawer`, `GET/POST /api/teller/cash-transfers` | Kas di laci dan pengajuan pemindahan ke brankas |
| Supervisor | `GET /api/supervisor/daily-reports`, `GET .../{id}`, `POST .../{id}/approve\|reject` | Verifikasi laporan harian |
| Supervisor | `GET /api/supervisor/cash-transfers`, `GET .../{id}`, `POST .../{id}/approve\|reject` | Otorisasi pemindahan kas |
| Nasabah | `GET /api/customer/me`, `GET /api/customer/balance` | Profil dan saldo |
| Nasabah | `GET /api/customer/transactions`, `GET /api/customer/transactions/{id}` | Mutasi rekening (`from`, `to`, `type`) |

Daftar menggunakan pagination (`?page=`, `?per_page=` maksimal 100). Role yang
salah mendapat `403`, tanpa token `401`, pelanggaran aturan bisnis `422` dengan
`message`.

## Aturan bisnis utama

- **Atomik:** ubah saldo, catat transaksi, dan dua baris jurnal berada dalam satu
  `DB::transaction()` dengan row lock pada nasabah. Jika satu langkah gagal,
  semuanya dibatalkan.
- **Jurnal entri ganda:** setor = Debit 101 Kas / Kredit 201 Tabungan Nasabah;
  tarik = Debit 201 Tabungan Nasabah / Kredit 101 Kas.
- **Saldo minimum:** penarikan ditolak jika sisa saldo di bawah `BANK_MIN_BALANCE`.
- **Status rekening:** `active` dapat bertransaksi; `suspended` hanya dapat login
  dan melihat saldo; `inactive` (lulus) tidak dapat login dan di-soft delete.
- **Penutupan loket:** setelah teller mengirim laporan harian, teller tersebut
  tidak dapat bertransaksi lagi pada tanggal itu kecuali laporan ditolak supervisor.
- **Pemindahan kas:** jumlah tidak boleh melebihi kas di laci teller (setor −
  tarik − pemindahan yang disetujui). Pemindahan kas tidak membuat jurnal karena
  kas tetap berada di bank.
- **Audit trail:** transaksi, jurnal, laporan yang disetujui, dan pemindahan kas
  yang sudah diproses tidak dapat diubah atau dihapus. Foreign key memakai
  `ON DELETE RESTRICT`, dan nominal dilindungi `CHECK (amount > 0)` di database.
- Password staf dan PIN nasabah di-hash (bcrypt) dan tidak pernah muncul di respons API.
