# Rencana Pengembangan Bank Mini Laravel

Dokumen ini adalah panduan pengerjaan proyek E-Teller Bank Mini Sekolah
berdasarkan [PRD.md](PRD.md). Gunakan sebagai urutan belajar, checklist
implementasi, dan checklist pengujian.

## 1. Kondisi Awal

### Sudah tersedia

- Laravel 13, PHP 8.3, Sanctum, Vite, dan Pest.
- Migration untuk `users`, `majors`, `customers`, `transactions`, dan
  `accounting_journals`.
- Enum untuk role, status rekening, jenis transaksi, kode akun, dan posisi
  jurnal.
- Seeder beberapa jurusan dan nasabah.
- Hashing password dan PIN mulai digunakan.
- Foreign key transaksi dan jurnal memakai `onDelete('restrict')`.

### Belum tersedia

- Login staf dan nasabah.
- Middleware role.
- CRUD staf dan rekening.
- Transaksi setor dan tarik.
- Jurnal double-entry.
- Laporan harian teller.
- Approval supervisor.
- Pemindahan kas ke brankas.
- API nasabah.
- React frontend.
- Test bisnis.

### Catatan teknis awal

- `Customer` masih berupa `Model` biasa, sehingga perlu disiapkan sebagai model
  yang dapat diautentikasi.
- `Customer` belum menggunakan soft delete.
- Konfigurasi provider customer perlu dipastikan menunjuk ke `Customer::class`.
- `DailyReport` belum memiliki migration.
- Transaksi belum mencatat teller yang menjalankannya.
- Constraint `amount > 0` perlu dibuat eksplisit pada transaksi dan jurnal.
- PRD menyebut Bcrypt sebagai kriptografi asimetris. Secara teknis Bcrypt adalah
  password hashing adaptif; gunakan hashing Laravel dan jangan simpan
  password/PIN asli.

## 2. Aturan Kerja

Untuk setiap fitur, ikuti urutan berikut:

1. Tulis aturan bisnisnya dalam bahasa biasa.
2. Tentukan aktor yang boleh menggunakannya.
3. Tentukan tabel dan relasi yang terlibat.
4. Tulis test untuk perilaku yang diharapkan.
5. Buat atau ubah migration dan model.
6. Buat service atau controller.
7. Hubungkan route.
8. Jalankan test.
9. Buat antarmuka frontend.
10. Dokumentasikan keputusan desain dan kasus khusus.

Jangan mulai dari dashboard. Prioritaskan integritas saldo, keamanan akses,
atomicity transaksi, dan jurnal akuntansi.

## 3. Urutan Milestone

### Milestone 0: Pahami domain

**Tujuan:** memahami istilah dan aturan sebelum membuat fitur.

Tulis jawaban untuk pertanyaan berikut:

- Apa perbedaan `User` dan `Customer`?
- Siapa yang boleh mengubah saldo?
- Apa arti setor dan tarik dalam sistem?
- Apa batas saldo minimum?
- Mengapa satu transaksi harus menghasilkan dua jurnal?
- Mengapa data customer yang lulus tidak langsung dihapus?
- Siapa yang boleh membaca jurnal?
- Apa perbedaan transaksi individual dan laporan harian?

Buat diagram sederhana untuk relasi:

```text
Major 1 --- banyak Customer
Customer 1 --- banyak Transaction
User 1 --- banyak Transaction sebagai Teller
Transaction 1 --- 2 AccountingJournal
Teller 1 --- banyak DailyReport
Supervisor 1 --- banyak approval DailyReport
```

**Selesai jika:** Anda dapat menjelaskan alur setor dari input teller sampai
saldo, transaksi, dan dua jurnal tersimpan.

---

### Milestone 1: Rapikan database dan model

**Tujuan:** membuat fondasi data yang konsisten.

#### Langkah

1. Pastikan urutan migration sesuai dengan foreign key.
2. Lengkapi field dan index yang diperlukan.
3. Tambahkan soft delete pada customer.
4. Tambahkan relasi Eloquent pada semua model.
5. Tambahkan casts untuk enum, status, amount, dan tanggal.
6. Sembunyikan password dan PIN dari serialization.
7. Tambahkan foreign key teller pada transaksi.
8. Buat migration laporan harian.
9. Tentukan struktur tabel pemindahan kas.
10. Tambahkan constraint `amount > 0` pada database.

#### Yang perlu dipelajari

- Migration dan rollback.
- Foreign key `restrict`.
- Soft delete.
- Eloquent relationship.
- `$fillable`, `$hidden`, dan `$casts`.
- Check constraint.

#### Checkpoint

- Migration dapat dijalankan dari database kosong.
- Seeder dapat dijalankan ulang tanpa data duplikat.
- Customer terhubung ke major.
- Transaction terhubung ke customer dan teller.
- Journal terhubung ke transaction.
- Data PIN dan password tidak muncul saat model diubah menjadi JSON.

---

### Milestone 2: Autentikasi staf

**Tujuan:** Administrator, Supervisor, dan Teller dapat masuk melalui API
Sanctum.

#### Langkah

1. Buat endpoint login staf.
2. Validasi username dan password.
3. Cari user berdasarkan username.
4. Verifikasi password menggunakan hashing Laravel.
5. Buat token Sanctum.
6. Buat endpoint user yang sedang login.
7. Buat endpoint logout.
8. Pastikan password dan token rahasia tidak dikembalikan sembarangan.

#### Test minimum

- Login berhasil dengan credential benar.
- Login gagal dengan username tidak dikenal.
- Login gagal dengan password salah.
- Token dapat digunakan untuk route terlindungi.
- Logout mencabut token.
- Password tidak muncul dalam response.

**Selesai jika:** route terlindungi tidak dapat diakses tanpa token dan user
yang sedang login dapat dikenali dengan benar.

---

### Milestone 3: Autentikasi nasabah

**Tujuan:** siswa dapat login menggunakan NIS atau nomor rekening dan PIN enam
digit.

#### Langkah

1. Jadikan `Customer` model yang dapat diautentikasi.
2. Benarkan provider dan guard customer.
3. Cari customer berdasarkan NIS atau nomor rekening.
4. Verifikasi PIN dengan `Hash::check`.
5. Tolak customer berstatus `inactive`.
6. Buat token Sanctum.
7. Buat logout customer.
8. Buat endpoint profil customer sendiri.

#### Aturan keamanan

- Customer hanya boleh mengakses data dari token miliknya.
- Jangan percaya `customer_id` dari request untuk menentukan pemilik data.
- PIN harus di-hash saat dibuat atau diubah.
- PIN tidak boleh muncul pada response API.

#### Test minimum

- Login memakai NIS.
- Login memakai nomor rekening.
- Login gagal dengan PIN salah.
- Customer inactive ditolak.
- Customer A tidak dapat membaca saldo Customer B.

---

### Milestone 4: Middleware role

**Tujuan:** membatasi endpoint berdasarkan role backend.

Buat matriks akses sebelum menulis route:

| Fitur            |          Admin | Supervisor | Teller | Customer |
| ---------------- | -------------: | ---------: | -----: | -------: |
| Kelola staf      |             Ya |      Tidak |  Tidak |    Tidak |
| Kelola rekening  |             Ya |       Baca |  Tidak |    Tidak |
| Setor dan tarik  | Tidak langsung |      Tidak |     Ya |    Tidak |
| Baca jurnal      |             Ya |         Ya |  Tidak |    Tidak |
| Approval laporan |          Tidak |         Ya |  Tidak |    Tidak |
| Saldo sendiri    |          Tidak |      Tidak |  Tidak |       Ya |
| Mutasi sendiri   |          Tidak |      Tidak |  Tidak |       Ya |

#### Test minimum

- Role yang diizinkan mendapat response sukses.
- Role yang salah mendapat `403`.
- User tanpa token mendapat `401`.
- Teller tidak dapat melakukan approval supervisor.
- Customer tidak dapat mengakses endpoint staf.

**Catatan:** menyembunyikan menu pada frontend bukan pengamanan. Pembatasan
wajib ada di backend.

---

### Milestone 5: CRUD staf dan rekening

#### Manajemen staf

Administrator harus dapat:

- Membuat staf.
- Melihat daftar staf aktif.
- Mengubah nama, username, dan role.
- Menonaktifkan atau menghapus akses staf.
- Menetapkan role Teller atau Supervisor.

Pertimbangkan status aktif/nonaktif agar akses dapat dihentikan tanpa menghapus
jejak data.

#### Manajemen rekening

Administrator harus dapat:

- Membuat rekening customer.
- Menghubungkan customer dengan major.
- Membuat nomor rekening unik.
- Membuat NIS unik.
- Menyimpan PIN sebagai hash.
- Mengubah status rekening.
- Menonaktifkan customer yang lulus.

Supervisor dan Administrator memiliki akses baca daftar rekening.

#### Validasi

- Username unik.
- Nomor rekening unik.
- NIS unik.
- PIN tepat enam digit.
- Major tersedia.
- Role dan status berasal dari nilai enum yang valid.
- Password/PIN tidak dikembalikan dalam JSON.

---

### Milestone 6: Transaksi setor dan tarik

**Tujuan:** membuat inti sistem bank secara atomik dan aman.

Pisahkan tanggung jawab:

- Controller menerima request dan mengembalikan response.
- Form Request menangani validasi input.
- Service menangani aturan saldo dan pencatatan transaksi.
- Model menangani relasi dan casts.

#### Alur setor

1. Pastikan teller sudah login.
2. Pastikan customer aktif.
3. Pastikan amount lebih besar dari nol.
4. Kunci baris customer saat proses berlangsung.
5. Tambahkan amount ke saldo.
6. Buat satu transaksi setor.
7. Buat dua jurnal berlawanan.
8. Commit hanya jika semua langkah berhasil.

#### Alur tarik

1. Pastikan teller sudah login.
2. Pastikan customer aktif.
3. Pastikan amount lebih besar dari nol.
4. Kunci baris customer.
5. Pastikan saldo setelah penarikan minimal Rp10.000.
6. Kurangi saldo.
7. Buat satu transaksi tarik.
8. Buat dua jurnal berlawanan.
9. Commit hanya jika semua langkah berhasil.

Seluruh alur harus berada dalam `DB::transaction()` dan menggunakan row lock
untuk mencegah dua transaksi membaca saldo lama secara bersamaan.

#### Test minimum

- Setor menambah saldo.
- Tarik mengurangi saldo.
- Tarik di bawah saldo minimum ditolak.
- Amount nol ditolak.
- Amount negatif ditolak oleh aplikasi dan database.
- Customer inactive tidak dapat bertransaksi.
- Teller tidak dapat bertransaksi tanpa autentikasi.
- Jika pembuatan jurnal gagal, saldo dan transaksi ikut rollback.
- Transaksi menyimpan teller yang menjalankannya.

---

### Milestone 7: Jurnal double-entry

Tuliskan aturan jurnal di dokumen sebelum implementasi.

#### Setor tunai

- Kas bertambah.
- Kewajiban tabungan customer bertambah.

#### Tarik tunai

- Kewajiban tabungan customer berkurang.
- Kas berkurang.

Untuk setiap transaksi, invariant yang wajib benar:

```text
jumlah jurnal untuk satu transaksi = 2
amount jurnal pertama = amount transaksi
amount jurnal kedua = amount transaksi
position jurnal pertama != position jurnal kedua
kode akun setiap jurnal valid
```

Administrator dan Supervisor hanya memiliki akses baca terhadap jurnal.

#### Test minimum

- Satu transaksi membuat tepat dua journal.
- Dua journal memiliki amount yang sama.
- Posisi journal berlawanan.
- Kode akun valid.
- Journal tidak dapat diubah atau dihapus melalui API biasa.
- Data journal dapat difilter berdasarkan tanggal atau transaksi.

---

### Milestone 8: Laporan harian teller

Buat struktur laporan yang menyimpan minimal:

- Teller.
- Tanggal laporan.
- Jumlah transaksi.
- Total setor.
- Total tarik.
- Status: `draft`, `submitted`, `approved`, atau `rejected`.
- Supervisor yang melakukan approval.
- Waktu approval.
- Catatan supervisor.

#### Alur

1. Teller melihat transaksi miliknya pada hari tersebut.
2. Teller membuat atau mengirim draft laporan.
3. Supervisor melihat laporan yang dikirim.
4. Supervisor menyetujui atau menolak.
5. Sistem menyimpan identitas dan waktu approval.
6. Laporan approved tidak boleh diedit sembarangan.

Pisahkan data transaksi sebagai sumber laporan dari record laporan sebagai
proses approval.

---

### Milestone 9: Pemindahan kas ke brankas

PRD menyebut laci teller dan brankas utama, sehingga perlu entitas tambahan,
misalnya `cash_transfers`.

Tentukan dan dokumentasikan:

- Siapa yang mengajukan transfer.
- Siapa yang menyetujui.
- Sumber kas.
- Tujuan kas.
- Amount.
- Status transfer.
- Catatan.
- Waktu pengajuan dan persetujuan.

#### Alur

1. Teller mengajukan pemindahan kas.
2. Supervisor memeriksa jumlah dan laporan.
3. Supervisor menyetujui atau menolak.
4. Sistem mencatat audit.
5. Data yang sudah disetujui tidak dapat diubah tanpa jejak perubahan.

---

### Milestone 10: API customer

Endpoint customer yang diperlukan:

- Login.
- Logout.
- Profil sendiri.
- Saldo terkini.
- Daftar mutasi.
- Detail transaksi.

Gunakan pagination untuk histori transaksi.

Data mutasi minimal memuat:

- Tanggal.
- Jenis transaksi.
- Nominal.
- Identitas transaksi.
- Saldo setelah transaksi jika memang tersedia dan konsisten.

Customer tidak boleh:

- Membuat transaksi sendiri.
- Mengubah saldo.
- Melihat jurnal internal.
- Melihat data customer lain.

---

### Milestone 11: Frontend React

Buat frontend setelah API backend stabil.

#### Portal staf

- Login staf.
- Dashboard sesuai role.
- Manajemen staf.
- Manajemen rekening.
- Form setor dan tarik.
- Daftar transaksi teller.
- Laporan harian.
- Approval supervisor.
- Jurnal read-only.

#### Portal customer

- Login NIS/nomor rekening dan PIN.
- Ringkasan saldo.
- Mutasi rekening.
- Profil.
- Logout.

Bangun dengan urutan:

1. State login.
2. Request API.
3. Penyimpanan dan pengiriman token.
4. Loading state.
5. Error state.
6. Empty state.
7. Pembatasan tampilan berdasarkan role.
8. Layout desktop dan mobile.

Pembatasan frontend tidak menggantikan middleware backend.

---

### Milestone 12: Testing dan hardening

#### Unit test

Gunakan untuk:

- Enum.
- Perhitungan saldo.
- Validasi saldo minimum.
- Penentuan pasangan jurnal.
- Perhitungan total laporan.

#### Feature test

Gunakan untuk:

- Login staf.
- Login customer.
- Logout.
- Middleware role.
- CRUD staf.
- CRUD rekening.
- Setor.
- Tarik.
- Rollback transaksi.
- Tepat dua journal.
- Saldo minimum.
- Isolasi data customer.
- Approval supervisor.
- Penolakan role yang salah.

Aktifkan `RefreshDatabase` untuk feature test agar setiap test menggunakan
database bersih.

#### Hardening

- Pastikan semua input tervalidasi.
- Pastikan amount tervalidasi di aplikasi dan database.
- Pastikan password dan PIN tidak masuk response.
- Pastikan endpoint sensitif memakai middleware.
- Pastikan transaksi memakai database transaction dan row lock.
- Pastikan data audit tidak dapat dihapus sembarangan.
- Pastikan error response konsisten.
- Pastikan pagination digunakan pada daftar besar.

## 4. Checklist Requirement PRD

### Authentication

- [x] Staf login dengan username dan password.
- [x] Customer login dengan NIS atau nomor rekening dan PIN.
- [x] Token Sanctum dibuat dan divalidasi.
- [x] Logout mencabut token.
- [x] Password dan PIN tidak pernah dikirim dalam response.

### User management

- [x] Admin membuat staf.
- [x] Admin membaca staf aktif.
- [x] Admin mengubah staf.
- [x] Admin menonaktifkan akses staf.

### Account management

- [x] Admin membuat rekening.
- [x] Rekening terhubung ke major.
- [x] Admin dan Supervisor membaca rekening.
- [x] Customer lulus berstatus inactive.
- [x] Soft delete menjaga data historis.

### Transactions

- [x] Teller dapat setor.
- [x] Teller dapat tarik.
- [x] Penarikan di bawah Rp10.000 ditolak.
- [x] Transaksi hanya dapat dilakukan setelah autentikasi.
- [x] Teller dapat membuat laporan harian.

### Accounting

- [x] Satu transaksi menghasilkan tepat dua journal.
- [x] Journal memiliki posisi debit dan kredit.
- [x] Journal memiliki kode akun.
- [x] Admin dan Supervisor dapat membaca journal.
- [x] Journal tidak dapat diedit melalui endpoint biasa.

### Supervisor

- [x] Supervisor dapat melihat laporan teller.
- [x] Supervisor dapat approve/reject laporan.
- [x] Supervisor dapat mengotorisasi pemindahan kas.
- [x] Approval menyimpan audit actor dan waktu.

### Customer

- [x] Customer melihat saldo sendiri.
- [x] Customer melihat mutasi sendiri.
- [x] Customer tidak dapat melihat customer lain.

### Database and security

- [x] Foreign key historis memakai `restrict`.
- [x] Amount memiliki constraint positif.
- [x] Proses uang memakai database transaction.
- [x] Password dan PIN menggunakan hashing.
- [x] Middleware role melindungi route.
- [x] Test unit dan feature tersedia.

## 5. Definisi Selesai

Proyek dianggap siap untuk demo UKK jika:

- Semua checklist requirement selesai.
- `php artisan test` lulus.
- Database dapat dibuat dari awal menggunakan migration dan seeder.
- Login setiap aktor dapat diuji.
- Teller dapat melakukan setor dan tarik dengan aman.
- Saldo, transaksi, dan jurnal tetap konsisten jika terjadi error.
- Supervisor dapat melakukan approval.
- Customer hanya dapat melihat datanya sendiri.
- Frontend memiliki loading, error, dan empty state.
- README menjelaskan instalasi, konfigurasi database, akun demo, dan cara
  menjalankan aplikasi.

## 6. Urutan Singkat Pengerjaan

```text
Pahami domain
-> Rapikan database dan model
-> Autentikasi staf
-> Autentikasi customer
-> Middleware role
-> CRUD staf dan rekening
-> Service transaksi
-> Jurnal double-entry
-> Laporan dan approval
-> Pemindahan kas
-> API customer
-> Frontend React
-> Testing dan hardening
-> Dokumentasi demo
```
