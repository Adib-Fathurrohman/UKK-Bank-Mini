Product Requirements Document (PRD): Sistem E-Teller Bank Mini Sekolah
Nama Produk: Aplikasi E-Teller Bank Mini Sekolah (Proyek UKK)
Platform: Web Desktop (Portal Staf) & Mobile Web (Portal Nasabah)
Versi Dokumen: 1.0
1. Ringkasan Produk (Product Overview)
Sistem E-Teller Bank Mini Sekolah adalah platform perbankan digital skala internal yang dirancang untuk mengelola transaksi keuangan siswa (setor dan tarik tunai). Sistem ini dibangun untuk memenuhi kriteria Uji Kompetensi Keahlian (UKK) yang menuntut standar industri tinggi: arsitektur Role-Based Access Control (RBAC) yang ketat, pencatatan jurnal akuntansi entri ganda (double-entry bookkeeping), dan pelestarian jejak audit data (audit trail) secara permanen. Sistem diproyeksikan mampu melayani basis data hingga 1.500+ pengguna (siswa).   
JPG
2. Aktor dan Hak Akses (User Personas & RBAC)
Berdasarkan arsitektur Use Case sistem, terdapat empat aktor utama dengan pemisahan wewenang yang absolut:   
JPG
Administrator: Fokus pada manajemen infrastruktur data (pengguna dan rekening) dan pemantauan jurnal.   
JPG
Supervisor: Fokus pada otorisasi kas fisik, verifikasi laporan, dan pengawasan operasional.   
JPG
Teller: Operator garis depan yang mengeksekusi transaksi uang secara langsung dengan nasabah.   
JPG
Nasabah (Siswa): Pengguna akhir yang hanya dapat melihat saldo dan mutasi akun miliknya sendiri.   
JPG
3. Kebutuhan Fungsional (Functional Requirements)
3.1. Modul Autentikasi Kustom (Wajib)
Sistem menggunakan auth internal (tanpa layanan pihak ketiga) dengan pemisahan guard.   
JPG
FR-Auth-01 (Staf): Administrator, Supervisor, dan Teller wajib masuk menggunakan Username dan Password melalui portal Web Desktop.   
JPG
FR-Auth-02 (Nasabah): Siswa masuk menggunakan Nomor Induk Siswa (NIS) atau Nomor Rekening beserta PIN 6-digit melalui antarmuka Mobile Web.   
JPG
FR-Auth-03 (Sesi): Sistem menerbitkan Token API (Sanctum) yang mengunci hak akses (role) di setiap permintaan HTTP.   
JPG
3.2. Modul Manajemen User (Khusus Administrator)
FR-Usr-01: Administrator dapat menambahkan staf baru dengan menetapkan Role (Teller/Supervisor).   
JPG
FR-Usr-02: Administrator dapat melihat daftar staf aktif (tanpa menampilkan kata sandi).   
JPG
FR-Usr-03: Administrator dapat memperbarui data atau menghapus akses staf (Create, Read, Update, Delete).   
JPG
3.3. Modul Manajemen Rekening
FR-Rek-01 (Admin): Administrator dapat membuat nomor rekening baru, mengaitkannya dengan Jurusan, dan mengatur status awal menjadi ACTIVE (Create, Update).   
JPG
FR-Rek-02 (Supervisi & Admin): Supervisor dan Admin dapat membaca daftar rekening nasabah (Read).   
JPG
FR-Rek-03 (Kelulusan): Administrator menonaktifkan akun siswa yang lulus dengan mengubah status Enum menjadi INACTIVE dan menerapkan Soft Deletes, tanpa menghapus fisik data dari basis data.
3.4. Modul Transaksi & Teller
FR-Trx-01 (Setor/Tarik): Teller mengeksekusi penambahan (Setor Tunai) atau pengurangan (Tarik Tunai) saldo nasabah secara mutlak wajib melalui proses autentikasi.   
JPG
FR-Trx-02 (Validasi Saldo): Sistem memblokir penarikan jika saldo akhir nasabah berada di bawah limit minimum yang ditentukan (misal Rp10.000).
FR-Trx-03 (Cetak Laporan): Teller mencetak rekapitulasi harian transaksi di loketnya (Draf Penutupan Kas) untuk dilaporkan ke Supervisor.   
JPG
3.5. Modul Akuntansi & Jurnal (Sistem Inti Latar Belakang)
FR-Acc-01 (Double-Entry): Setiap eksekusi transaksi oleh Teller wajib menciptakan 1 baris di tabel transactions dan tepat 2 baris berlawanan di tabel accounting_journals (Debet dan Kredit).   
JPG
+ 1
FR-Acc-02 (Kode Akun): Pembuatan jurnal wajib menyertakan klasifikasi AccountCode (contoh: 101 Kas, 201 Tabungan Nasabah).
FR-Acc-03 (Audit R/O): Administrator dan Supervisor dapat melihat (Read-Only) pergerakan Real-time log dan Audit Jurnal Akuntansi ini, tanpa hak untuk memodifikasi.   
JPG
3.6. Modul Otorisasi Supervisor
FR-Spv-01: Supervisor memverifikasi dan menyetujui (Approval) laporan harian yang diserahkan Teller.   
JPG
FR-Spv-02: Supervisor melakukan otorisasi pemindahan kas (penarikan uang fisik) dari laci Teller menuju Brankas Utama bank.   
JPG
3.7. Modul Nasabah (Mobile)
FR-Cus-01: Nasabah yang berhasil masuk (via PIN) dapat melihat Saldo terkini miliknya.   
JPG
FR-Cus-02: Nasabah dapat melihat Mutasi Rekening (histori transaksi masuk dan keluar) secara mandiri.   
JPG
4. Aturan Bisnis & Kebutuhan Non-Fungsional (Non-Functional Requirements)
4.1. Integritas Data & Basis Data (Data Constraints)
NFR-DB-01 (Audit Trail Lock): Tabel referensi utama tidak boleh dihapus jika memiliki relasi transaksi. Skema wajib menerapkan parameter ON DELETE RESTRICT pada kunci tamu (Foreign Key).   
JPG
NFR-DB-02 (Nilai Positif): Tabel transaksi dan jurnal wajib memiliki pelindung Check Constraint di level basis data MySQL/MariaDB (CHECK (amount > 0)) untuk mencegah manipulasi angka negatif/nol.
NFR-DB-03 (Atomicity): Seluruh pencatatan transaksi uang (ubah saldo, catat mutasi, catat 2 jurnal) wajib dibungkus dalam DB::transaction() agar jika salah satu kueri putus, seluruh rangkaian dibatalkan (Rollback otomatis).   
JPG
+ 1
4.2. Keamanan (Security)
Kata sandi staf (Password) dan nasabah (PIN) wajib dienkripsi menggunakan fungsi hashing kriptografi asimetris (Bcrypt) dan tidak boleh diekspos pada respon API (parameter $hidden).   
JPG
Rute-rute API dilindungi oleh Middleware CheckRole untuk memastikan Teller tidak dapat mengeksekusi otorisasi Supervisor, dan sebaliknya.   
JPG
4.3. Spesifikasi Teknologi
Backend: Laravel 13 (PHP 8.3)
Database: MariaDB (RDBMS)
Frontend Web Desktop: React (terintegrasi via Vite)
Frontend Mobile Web: React (Antarmuka adaptif/responsif)
Authentication Engine: Laravel Sanctum (Token-based API)
Testing: Pest (Unit & Feature Testing)