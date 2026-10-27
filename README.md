# E-Teller Bank Mini Sekolah

Backend API untuk sistem perbankan internal sekolah (proyek UKK Paket 1 RPL-2026):
setor dan tarik tunai siswa di loket, jurnal akuntansi entri ganda, shift teller
dengan penutupan kas, approval supervisor yang memindahkan kas ke brankas, dan
portal nasabah. Spesifikasi ada di [PRD.md](PRD.md); urutan pengerjaan di
[RENCANA_PENGEMBANGAN.md](RENCANA_PENGEMBANGAN.md); dokumentasi API lengkap di
[docs/API.md](docs/API.md).

**Teknologi:** Laravel 13 (PHP 8.3+), MariaDB (InnoDB), Laravel Sanctum (token
API), DomPDF (laporan PDF), OpenSpout (impor spreadsheet), Pest.

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
BANK_NAME="Bank Mini Sekolah"   # dicetak di struk dan laporan
BANK_SCHOOL="SMK"
BANK_MIN_BALANCE=10000          # nilai awal saldo minimum (admin dapat mengubahnya)
BANK_PIN_MAX_ATTEMPTS=3         # PIN salah berturut-turut sebelum diblokir
BANK_PIN_LOCK_MINUTES=15        # lama blokir PIN
SANCTUM_EXPIRATION=480          # masa berlaku token dalam menit
```

Jalankan migration, seeder, dan server:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Agar aplikasi mobile di HP dapat mengakses API, jalankan server di semua
antarmuka jaringan dan gunakan IP laptop di jaringan yang sama:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Seeder aman dijalankan ulang. Saldo awal nasabah demo dibuat lewat transaksi setor
sungguhan di dalam shift teller tanggal kemarin yang sudah ditutup dan disetujui,
sehingga saldo, mutasi, jurnal, dan brankas konsisten, dan teller dapat membuka
shift baru hari ini.

## Akun demo

| Aktor | Login | Password / PIN |
| --- | --- | --- |
| Administrator | `admin` | `password` |
| Supervisor | `supervisor` | `password` |
| Teller | `teller` | `password` |
| Nasabah | NIS `20260001`–`20260003` atau rekening `RPL-2026-001`, `DKV-2026-001`, `RPL-2026-002` | PIN `123456` |

Semua aktor login lewat satu endpoint, `POST /api/login`. Ganti semua password
dan PIN demo sebelum dipakai di luar lingkungan ujian.

## Pengujian

```bash
php artisan test
```

Test memakai SQLite in-memory (lihat `phpunit.xml`). Untuk memastikan constraint
`CHECK` dan row lock juga berjalan di MariaDB, jalankan test pada database
terpisah (jangan `ukk_laravel`, karena isinya dihapus setiap test). Instalasi
MariaDB bawaan mengizinkan setiap user membuat database berawalan `test_`:

```bash
mariadb -u bank-db-user -p -e "CREATE DATABASE IF NOT EXISTS test_bank_mini"
```

```bash
DB_CONNECTION=mariadb DB_DATABASE=test_bank_mini php artisan test
```

Jika user database tidak memiliki izin tersebut, berikan dulu sebagai root:
`GRANT ALL PRIVILEGES ON test_bank_mini.* TO 'bank-db-user'@'localhost';`

## Alur utama

| Aktor | Alur |
| --- | --- |
| Teller | Buka shift (hitung saldo awal per pecahan) → scan QR kartu nasabah atau ketik nomor rekening/NIS → setor, atau tarik dengan PIN yang diketik nasabah → cetak struk → koreksi jika salah ketik (otorisasi supervisor) → tutup shift (hitung uang fisik, harus sama dengan saldo sistem) → cetak laporan harian |
| Supervisor | Periksa laporan satu per satu → setujui (kas otomatis pindah ke brankas), tahan dengan catatan, atau tolak (shift dibuka kembali untuk koreksi) → telusuri log audit dan jurnal |
| Administrator | Kelola staf (buat, impor spreadsheet, blokir, hapus yang belum pernah dipakai), buka rekening (nomor otomatis `KODE-TAHUN-URUT`), cetak kartu QR, reset PIN, nonaktifkan lulusan, atur saldo minimum dan mode pemindai, unduh laporan PDF |
| Nasabah | Lihat saldo, mutasi, kartu digital dengan QR, unduh mutasi PDF, ganti PIN |

Daftar endpoint, parameter, dan contoh respons ada di [docs/API.md](docs/API.md).

## Aturan bisnis utama

- **Atomik:** ubah saldo, catat transaksi, dan dua baris jurnal berada dalam satu
  `DB::transaction()` dengan row lock (teller → shift → nasabah). Jika satu langkah
  gagal, semuanya dibatalkan.
- **Jurnal entri ganda:** setor = Debit 101 Kas / Kredit 201 Tabungan Nasabah;
  tarik = kebalikannya. Transaksi koreksi dibukukan berlawanan dengan transaksi
  yang dibatalkan.
- **Saldo minimum:** penarikan ditolak jika sisa saldo di bawah saldo minimum
  (pengaturan admin, awal Rp10.000).
- **PIN penarikan:** setiap penarikan wajib PIN 6 digit nasabah; 3 kali salah
  memblokir penarikan selama 15 menit.
- **Shift dan penutupan kas:** transaksi hanya dapat dicatat dalam shift yang
  dibuka hari ini. Shift hanya dapat ditutup (dan laporannya dicetak) jika uang
  fisik = saldo awal + setoran − penarikan.
- **Approval:** supervisor menyetujui laporan satu per satu; persetujuan otomatis
  mencatat pemindahan kas dari laci teller ke brankas utama.
- **Koreksi, bukan edit:** transaksi tersimpan tidak dapat diubah. Salah ketik
  dibatalkan dengan transaksi koreksi (sekali per transaksi, dalam shift yang
  sama, dengan username dan password supervisor).
- **Idempotency:** header `Idempotency-Key` mencegah transaksi tercatat dua kali
  saat koneksi terputus dan permintaan diulang.
- **Status rekening:** `active` dapat bertransaksi; `suspended` hanya dapat login
  dan melihat saldo; `inactive` (lulus) tidak dapat login dan di-soft delete.
- **Audit trail:** transaksi, jurnal, laporan yang disetujui, dan pemindahan kas
  tidak dapat diubah atau dihapus. Foreign key memakai `ON DELETE RESTRICT`, dan
  database memakai `CHECK` untuk nominal > 0, saldo ≥ 0, pecahan uang, dan
  keterkaitan transaksi koreksi.
- **Keamanan:** password staf dan PIN nasabah di-hash (bcrypt) dan tidak pernah
  muncul di respons API. Setiap endpoint memeriksa token dan role sebelum query
  dijalankan; role yang salah selalu mendapat `403`.
