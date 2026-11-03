# E-Teller Bank Mini Sekolah

Backend API untuk sistem perbankan internal sekolah (proyek UKK Paket 1 RPL-2026):
setor dan tarik tunai siswa di loket, jurnal akuntansi entri ganda, shift teller
dengan penutupan kas, approval supervisor yang memindahkan kas ke brankas, dan
portal nasabah. Spesifikasi ada di [PRD.md](PRD.md); urutan pengerjaan di
[RENCANA_PENGEMBANGAN.md](RENCANA_PENGEMBANGAN.md); dokumentasi API lengkap di
[docs/API.md](docs/API.md).

**Teknologi:** Laravel 13 (PHP 8.3+), MariaDB (InnoDB), Laravel Sanctum (token
API), Firebase Cloud Messaging lewat Firebase Admin SDK `kreait/firebase-php` (notifikasi
push ke aplikasi nasabah), DomPDF (laporan PDF), OpenSpout (impor spreadsheet), Pest.

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
BANK_WITHDRAWAL_AUTH_PENDING_SECONDS=120   # batas waktu nasabah menyetujui penarikan di aplikasi
BANK_WITHDRAWAL_AUTH_APPROVED_SECONDS=120  # batas waktu teller menyelesaikan setelah disetujui
SANCTUM_EXPIRATION=480          # masa berlaku token staf dalam menit
CUSTOMER_SESSION_MINUTES=43200  # masa berlaku sesi aplikasi nasabah (30 hari)
```

Nasabah login dengan NIS/nomor rekening + PIN. Firebase hanya dipakai untuk notifikasi
push (FCM); tanpa kredensial Firebase semua fitur lain tetap jalan. Backend tidak mengirim
email, jadi tidak perlu SMTP. Isi juga (jangan commit kredensial asli):

```dotenv
FIREBASE_PROJECT_ID=bank-mini-rumput
FIREBASE_CREDENTIALS=storage/app/private/firebase-credentials.json   # service account, hanya di server
```

Detail login nasabah, PIN sementara, notifikasi push, dan langkah migrasi data lama ada
di [docs/CUSTOMER_AUTH.md](docs/CUSTOMER_AUTH.md); panduan frontend di
[docs/FRONTEND_NIS_PIN_FCM.md](docs/FRONTEND_NIS_PIN_FCM.md).

Jalankan migration, seeder, dan server:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Scheduler (opsional tetapi disarankan) membersihkan token kedaluwarsa dan menandai
permintaan penarikan yang terbengkalai sebagai kedaluwarsa setiap menit:

```bash
php artisan schedule:work
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
| Nasabah | NIS `20260001`–`20260003` atau rekening `RPL-2026-001`, `DKV-2026-001`, `RPL-2026-002` | PIN sementara `123456` (wajib diganti saat login pertama) |

Semua aktor dapat login lewat satu endpoint, `POST /api/login`: staf dengan username dan
password, nasabah dengan NIS/nomor rekening dan PIN. Nasabah demo memakai PIN sementara
`123456` dan wajib menggantinya saat login pertama; sebelum diganti, sesi hanya bisa
mengganti PIN dan penarikan ditolak. Ganti semua password demo sebelum dipakai di luar
lingkungan ujian.

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
| Teller | Buka shift (hitung saldo awal per pecahan) → scan QR kartu nasabah atau ketik nomor rekening/NIS → setor, atau tarik: kirim permintaan ke aplikasi nasabah, tunggu disetujui, lalu selesaikan (atau, jika admin mengizinkan, nasabah mengetik PIN di loket) → cetak struk → koreksi jika salah ketik (otorisasi supervisor) → tutup shift (hitung uang fisik, harus sama dengan saldo sistem) → cetak laporan harian |
| Supervisor | Periksa laporan satu per satu → setujui (kas otomatis pindah ke brankas), tahan dengan catatan, atau tolak (shift dibuka kembali untuk koreksi) → telusuri log audit dan jurnal |
| Administrator | Kelola staf (buat, impor spreadsheet, blokir, hapus yang belum pernah dipakai), buka rekening (nomor otomatis `KODE-TAHUN-URUT`, PIN sementara ditampilkan sekali, email opsional), cetak kartu QR, reset PIN (PIN sementara baru, semua sesi nasabah berakhir), nonaktifkan lulusan, atur saldo minimum, mode pemindai, dan mode verifikasi penarikan, unduh laporan PDF |
| Nasabah | Login dengan NIS + PIN → ganti PIN sementara saat login pertama → buka aplikasi dengan PIN → lihat saldo, mutasi, kartu digital dengan QR, unduh mutasi PDF, ganti PIN, terima notifikasi push saat teller membuat permintaan penarikan, setujui (dengan PIN) atau tolak |

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
- **PIN penarikan:** setiap penarikan wajib PIN 6 digit nasabah. Rekening baru (dan
  reset PIN oleh admin) mendapat PIN sementara acak dari sistem yang hanya ditampilkan
  sekali ke admin; nasabah wajib menggantinya saat login pertama, dan PIN sementara tidak
  pernah bisa dipakai untuk penarikan. Bawaannya
  (`withdrawal_verification = mobile`) nasabah menyetujui di aplikasinya sendiri,
  sehingga teller tidak pernah melihat atau mengirim PIN; nasabah tidak dapat
  memulai penarikan. Admin dapat mengizinkan PIN diketik di loket (`terminal` atau
  `both`). Detail: [docs/PIN_VERIFICATION.md](docs/PIN_VERIFICATION.md).
- **Blokir PIN:** 3 kali PIN salah berturut-turut (login, buka aplikasi, persetujuan,
  penarikan di loket, atau ganti PIN) memblokir PIN selama 15 menit di semua
  tempat.
- **Login nasabah:** NIS atau nomor rekening + PIN (email tidak wajib). Permintaan
  penarikan baru dikirim sebagai notifikasi push (FCM) ke HP nasabah; aplikasi tetap
  melakukan polling. Detail: [docs/CUSTOMER_AUTH.md](docs/CUSTOMER_AUTH.md).
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
