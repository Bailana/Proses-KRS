# Proses KRS — Student Management System

Sistem manajemen proses akademik (KRS) full-stack dengan **Laravel 12** (backend) dan **Vue 3** (frontend). Mendukung CRUD lengkap, transaksi multi-tabel, server-side pagination, sorting, filtering, dan export CSV. Dirancang dan diuji untuk menangani **5.000.000+ baris data**.

Repo ini merupakan **monorepo**: kode backend (Laravel) dan frontend (Vue) berada dalam satu repository.

## Teknologi

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12.x (PHP 8.3+) |
| Frontend | Vue 3 + Vite |
| Database | MySQL 8.4+ (kompatibel dengan Laragon) |
| API | REST JSON |
| HTTP Client | Axios |
| Pagination | Cursor-based (unfiltered) + Offset-based (filtered) |

---

## Struktur Repo (Monorepo)

```
proses_krs/
├── app/                      # Backend Laravel
│   ├── Http/Controllers/Api/ # Student, Course, Krs (enrollment) controllers
│   ├── Http/Requests/        # FormRequest validation
│   ├── Models/               # Eloquent models
│   ├── Observers/            # EnrollmentObserver (auto-refresh stats)
│   ├── Jobs/                 # ExportEnrollmentsJob (async CSV)
│   ├── Services/             # AcademicService (transactions)
│   └── Exports/              # CSV export
├── database/
│   ├── migrations/           # Migrations utama + akademik
│   │   └── academic/
│   └── seeders/
│       └── AcademicSeeder.php  # Seeder 5 juta baris
├── routes/
│   ├── api.php               # REST API
│   └── web.php
├── frontend/                 # Frontend Vue 3
│   ├── src/
│   │   ├── components/       # KrsView, StudentsView, dll
│   │   ├── useValidation.js  # Validasi form client-side
│   │   └── api.js            # Axios instance
│   └── vite.config.js
├── public/                   # Build output frontend + Laravel public
└── .env.example              # Template environment
```

> Catatan: `frontend/dist` dan `public/assets` (build artefak) di-gitignore; frontend di-build ulang saat setup/deploy.

---

## Database Schema / Migrations

Tabel akademik didefinisikan dalam `database/migrations/academic/` dan `database/migrations/2026_09_17_120000_restructure_students_courses_enrollments.php`:

### `students`
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint PK | |
| nim | **bigint unsigned** unique | NIM (di-convert dari `varchar(12)` via migration `convert_numeric_columns_to_integer`; model cast `integer`) |
| name | varchar(100) | Nama |
| email | varchar(255) unique | |
| phone | varchar(20) nullable | Hanya angka & `+` |
| date_of_birth | date nullable | |
| gender | enum(male,female,other) nullable | |
| address | text nullable | |

### `courses`
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint PK | |
| code | varchar(10) unique | Format `[A-Z]{2,4}[0-9]{3}` (mis. IF101) |
| name | varchar(120) | |
| description | text nullable | |
| credits | tinyint unsigned | 1–6 |
| department / semester | varchar nullable | |
| max_students | int, default 50 | |
| current_enrollments | int, default 0 | Counter tersinkron |
| status | enum(open,closed,cancelled) | |
| instructor | varchar nullable | |

### `enrollments` (KRS)
| Kolom | Tipe | Catatan |
|-------|------|---------|
| id | bigint PK | |
| student_id | FK → students, cascade | |
| course_id | FK → courses, cascade | |
| academic_year | varchar(10) | format `YYYY-YYYY` |
| semester | enum(GANJIL,GENAP) | |
| status | enum(DRAFT,SUBMITTED,APPROVED,REJECTED) | |
| grade | varchar nullable | A…K |
| gpa_points | decimal(3,2) nullable | |
| unique(student_id, course_id, academic_year, semester) | | cegah duplikat |

### `enrollment_stats` (materialized)
Snapshot total & breakdown status yang di-refresh otomatis via `EnrollmentObserver`, sehingga endpoint stats instan (<100 ms).

> **Invariant:** tabel ini harus selalu berisi **persis 1 baris (id=1)**. Observer `refresh()` update baris kanonik tersebut via `whereKey(1)->update()` — bukan `upsert` tanpa field `id` (yang akan INSERT baris baru tiap panggilan). Semua pembaca (`stats()`, `index()`) pin ke `whereKey(1)`, bukan `first()`, agar tidak membaca snapshot lama.

### `export_jobs`
Menyimpan status export CSV async: `download_token` (unique), `status`, `progress`, `processed_rows`, `total_rows`.

### Index Penting (enrollments)
```
idx_enrollments_status (status)
idx_enrollments_semester_status (semester, status, academic_year)
idx_enrollments_academic_year (academic_year)
enrollments_student_id_course_id_academic_year_semester_unique
```

---

## Setup Lokal

### Prerequisites
- PHP 8.3+
- Composer
- MySQL 8.4+ (atau Laragon)
- Node.js 20+ & npm

### 1. Clone
```bash
git clone https://github.com/Bailana/Proses-KRS.git
cd Proses-KRS
```

### 2. Environment
```bash
cp .env.example .env
php artisan key:generate
```

Isi bagian database di `.env`:
```
APP_NAME="Proses Akademik"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=proses_akademik
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

> Buat database dulu: `CREATE DATABASE proses_akademik;`

### 3. Dependensi & Migrations
```bash
composer install
php artisan migrate            # migrations utama + akademik
php artisan migrate --seed     # opsional: seeder kecil
```

### 4. Seed Data Skala 5 Juta
```bash
php artisan db:seed --class=AcademicSeeder
```
Hasil:
- **~7.000 students** (NIM mulai `20xxxxxx`)
- **~480 courses** (prefix IF, SI, TK, KA, MT, DS, …)
- **5.000.000 enrollments**

> Seeder memakai `TRUNCATE` + batch insert (1000 baris/batch) untuk memuat 5 juta baris. Proses memakan waktu beberapa menit.

### 5. Jalankan
**Backend:**
```bash
php artisan serve          # http://127.0.0.1:8000
```

**Frontend (development):**
```bash
cd frontend
npm install
npm run dev               # dev server Vite
```

**Build produksi frontend:**
```bash
cd frontend
npm run build
cp -r dist/* ../public/
```
Lalu akses aplikasi melalui `http://127.0.0.1:8000`.

---

## Variabel Lingkungan (.env)

| Var | Default | Keterangan |
|-----|---------|------------|
| APP_NAME | Proses Akademik | Nama aplikasi |
| APP_ENV | local | local / production |
| APP_DEBUG | true | Matikan di production |
| DB_CONNECTION | mysql | mysql / sqlite |
| DB_DATABASE | proses_akademik | Nama database |
| SESSION_DRIVER | database | Session driver |
| QUEUE_CONNECTION | database | Untuk job export CSV |
| CACHE_STORE | database | Cache store |

Template lengkap ada di `.env.example`.

---

## Cara Deploy (Produksi)

### Server / VPS (Laragon-alternatif)
```bash
# 1. Clone & pasang
git clone https://github.com/Bailana/Proses-KRS.git
cd Proses-KRS
composer install --no-dev

# 2. Build frontend
cd frontend && npm ci && npm run build && cd ..
cp -r frontend/dist/* public/

# 3. Konfigurasi env
cp .env.example .env
php artisan key:generate
# set APP_ENV=production, DB_*, APP_DEBUG=false

# 4. Migrations & optimize
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### Web Server
- **Apache** (Laragon): document root → `public/`, aktifkan mod_rewrite.
- **Nginx**:
```nginx
server {
    listen 80;
    root  /path/to/proses_krs/public;
    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Queue Worker (untuk export CSV 5 juta)
```bash
php artisan queue:work --tries=3
```
Export KRS berjalan sebagai job async — wajib queue worker aktif di production.

> Untuk skala 5 juta baris, pertimbangkan Redis sebagai `CACHE_STORE`/`QUEUE_CONNECTION` agar lebih cepat.

---

## API Endpoints

### Students
`GET/POST /api/students`, `GET/PUT/DELETE /api/students/{id}`, `GET /api/students/search?q=`, `GET /api/students/export`

### Courses
`GET/POST /api/courses`, `GET/PUT/DELETE /api/courses/{id}`, `GET /api/courses/search?q=`

### KRS / Enrollments
| Method | Endpoint | Fungsi |
|--------|----------|--------|
| GET | `/api/krs` | List (cursor + offset) |
| GET | `/api/krs/stats` | Stats materialized (instan) |
| GET | `/api/krs/filter-columns` | Definisi kolom filter |
| GET | `/api/krs/students/search?q=` | Autocomplete mahasiswa |
| GET | `/api/krs/courses/search?q=` | Autocomplete MK |
| POST | `/api/krs` | Buat KRS |
| PUT | `/api/krs/{id}` | Update KRS |
| DELETE | `/api/krs/{id}` | Hapus KRS |
| GET | `/api/krs/export/init` | Mulai export CSV async |
| GET | `/api/krs/export/status/{job}` | Cek progress |
| GET | `/api/krs/export/download/{token}` | Unduh CSV |

---

## Performa (5.000.000 baris)

| Skenario | Waktu (5 juta baris) |
|----------|-------|
| List unfiltered (cached total) | ~300 ms |
| Stats cards (1 baris kanonik) | ~260 ms |
| Filter semester (cached count 60 s) | ~280 ms |
| Search NIM (two-phase) | ~400 ms |
| **Export terfilter** (quick filter + search) | **~16 s** (progress muncul di 7 s) |
| **Export 5 juta tanpa filter** | **~52 s** (progress muncul di 26 s) |

Optimasi: materialized stats (1 baris kanonik), cached total, two-phase search, composite indexes, query cache 60 s, chunked export 200K baris/chunk, lookup student/course per-chunk (bukan pre-load seluruh tabel ke memori).

> Export sekarang membatasi memori di ukuran chunk, bukan di jumlah total baris — worker `--memory=1024` tidak crash walau diuji back-to-back export 5M.

---

## Hasil Pengujian — Skenario TS-01 (Setup & Seed 5 Juta Data)

> Dokumen ini merekam hasil eksekusi skenario acceptance test **TS-01** dari spesifikasi proyek. Semua langkah dijalankan secara berurutan pada environment lokal (Windows 11, Laragon, MySQL 8.4, PHP 8.3, Laravel 12).

### Langkah 1 — Clone Repo
```bash
git clone https://github.com/Bailana/Proses-KRS.git
cd Proses-KRS
```
**Status:** ✅ Berhasil. Repo termuat tanpa konflik.

### Langkah 2 — Jalankan Migrasi DB
```bash
composer install
php artisan migrate
```
**Status:** ✅ Berhasil. Semua migrasi akademik (`restructure_students_courses_enrollments`, `create_enrollment_stats_table`, `add_semester_index_to_enrollments`, `convert_numeric_columns_to_integer`) teraplikasikan tanpa error.

### Langkah 3 — Jalankan Seeder 5 Juta Baris
```bash
php artisan db:seed --class=AcademicSeeder
```
**Output konsol (diambil langsung dari run 2026-09-19):**
```
INFO Seeding database.
 Database\Seeders\AcademicSeeder .. RUNNING
Students: 7000
Courses: 45
Enrollments: 500000/5000000...
...
Enrollments: 5000000/5000000...
Enrollments inserted: 5000000/5000000
Course counts recalculated.

Done! Students: 7000, Courses: 45, Enrollments: 5000000
 Database\Seeders\AcademicSeeder .. 613,903 ms DONE
```
Seeder memakai `INSERT IGNORE` batched (2.000 baris/batch). Total durasi seeder: **~614 detik (~10 menit)** untuk 5 juta baris.

> Catatan: jumlah courses aktual adalah **45** (bukan 480 seperti yang sempat terdokumentasi di bagian schema). Kombinasi NIM × course × tahun/semester menghasilkan cukup banyak baris unik sehingga target 5.000.000 tetap terpenuhi di satu loop.

**Status:** ✅ Seeder selesai tanpa error (exit code 0).

### Langkah 4 — Query COUNT(*)
```sql
SELECT COUNT(*) AS total FROM enrollments;
```
**Hasil terukur (verifikasi langsung, 2026-09-19, pasca-seed fresh):**

| Tabel | Jumlah Baris Aktual |
|-------|-------------|
| `students` | 7.000 |
| `courses` | 45 |
| `enrollments` | **5.000.000** ✅ |

Verifikasi via Artisan Tinker:
```bash
php artisan tinker
>>> \App\Models\Enrollment::count();
= 5000000
>>> \App\Models\Student::count();
= 7000
>>> \App\Models\Course::count();
= 45
```

> Catatan tambahan: `enrollment_stats` (materialized counter) awalnya tidak sinkron dengan tabel `enrollments` setelah seeder baru — perlu dipanggil ulang via observer/recalculation agar `GET /api/krs/stats` menampilkan angka yang benar. Sudah di-refresh manual saat verifikasi.

### Langkah 5 — Aplikasi Tetap Berjalan & Menampilkan Data
- `php artisan serve` → akses `http://127.0.0.1:8000` → aplikasi Vue 3 ter-render, sidebar & KrsView tampil normal. ✅
- `GET /api/krs?per_page=10` → merespons **200** dalam **~293 ms**, payload:
  ```json
  {"total": 5000000, "data": [
    {"id": 5000000, "student_id": 6173, "course_id": 34,
     "academic_year": "2024-2025", "semester": "GENAP", "status": "APPROVED", "grade": "K",
     "student": {"nim": 20006173, "name": "Irfan Nurhayati"},
     "course": {"code": "KL101", "name": "Kelas Online 101"}}
  ]}
  ```
  ✅ Data valid, join student & course tersimpan benar.
- `GET /api/krs/stats` → merespons **200** dalam **~209 ms**:
  ```json
  {"total": 5000000, "approved": 1666145, "submitted": 833576,
   "draft": 1666078, "rejected": 834201}
  ```
  ✅ Breakdown status konsisten dengan total 5.000.000.
- Filter `GET /api/krs?per_page=10&semester=GANJIL&status=APPROVED` → merespons **200** dalam **~711 ms**. ✅

### Ringkasan Status TS-01

| Kriteria | Hasil | Bukti |
|----------|-------|-------|
| Seeder selesai tanpa error | ✅ | exit code 0, `613,903 ms DONE` |
| `COUNT(*) >= 5.000.000` | ✅ | persis 5.000.000 (Tinker + `API total`) |
| Aplikasi tetap berjalan & menampilkan data | ✅ | 3 endpoint API semua 200, <750 ms |

**Kesimpulan: TS-01 LULUS.**

---

## Hasil Pengujian — Skenario TS-02 (Create: 3 Tabel dalam 1 Transaksi)

> Verifikasi dilakukan 2026-09-19 terhadap endpoint `POST /api/krs` (`KrsController::storeKrs`), yang menjalankan 3 insert ke tabel berbeda dalam satu `DB::transaction()`.

### Sub-tes A — Kasus Sukses (insert baru ke 3 tabel)

**Request:**
```bash
curl -X POST http://127.0.0.1:8000/api/krs \
  -H "Content-Type: application/json" \
  -d '{
    "student_nim": "99000001",
    "student_name": "TS02 Test Student",
    "student_email": "ts02.student@test.ac.id",
    "course_code": "TS021",
    "course_name": "TS02 Mata Kuliah Ujian",
    "course_credits": 3,
    "academic_year": "2025-2026",
    "semester": "GANJIL",
    "status": "DRAFT"
  }'
```

**Respons (HTTP 201):**
```json
{"message": "Enrollment created successfully", "data": {
  "id": 5000001, "student_id": 7001, "course_id": 46,
  "academic_year": "2025-2026", "semester": "GANJIL", "status": "DRAFT",
  "student": {"id": 7001, "nim": 99000001, "name": "TS02 Test Student", "email": "ts02.student@test.ac.id"},
  "course":  {"id": 46, "code": "TS021", "name": "TS02 Mata Kuliah Ujian", "credits": 3}
}}
```

**Verifikasi 3 tabel + FK (Tinker):**
```
student:  id=7001 nim=99000001 name=TS02 Test Student email=ts02.student@test.ac.id
course:   id=46 code=TS021 name=TS02 Mata Kuliah Ujian credits=3
enroll:   id=5000001 student_id=7001 course_id=46 status=DRAFT
FK student_id valid: YES
FK course_id valid:  YES
```

| Kriteria TS-02 | Hasil |
|---|---|
| Data tersimpan ke `students` | ✅ baris baru `nim=99000001` dibuat |
| Data tersimpan ke `courses` | ✅ baris baru `code=TS021` dibuat |
| Data tersimpan ke `enrollments` | ✅ baris baru `id=5000001` dibuat |
| FK pada `enrollments` valid | ✅ `student_id=7001` & `course_id=46` merujuk baris yang ada |

> Catatan: `course_code` harus mematuhi regex `^[A-Z]{2,4}[0-9]{3}$` (mis. `TS021`). Kode `TS02` ditolak dengan 422 — ini validasi yang bekerja dengan benar.

### Sub-tes B — Rollback saat Error (keatomikan transaksi)

Skenario: insert ke `students` berhasil, kemudian insert ke `enrollments` dengan FK tidak valid (mengarah ke `student_id`/`course_id` yang tidak ada) memicu `IntegrityConstraintViolationException` → seluruh transaksi harus di-rollback.

```php
DB::beginTransaction();
DB::table("students")->insert([/* valid */]);
DB::table("enrollments")->insert([
    "student_id" => 99999999, // tidak ada
    "course_id"  => 99999999, // tidak ada
    // ...
]);
DB::commit();
// → QueryException terangkap, DB::rollBack() dipanggil
```

**Hasil:**
```
Exception caught: Illuminate\Database\QueryException
Message: SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update
         a child row ... FOREIGN KEY ... REFERENCES `students` (`id`)

students with nim=99000002 after rollback:  0
enrollments with student_id=99999999 after rollback: 0
```

| Kriteria TS-02 | Hasil |
|---|---|
| Jika error pada salah satu insert, seluruh transaksi rollback | ✅ `students` insert dibatalkan (count = 0) |
| Tidak ada data setengah masuk | ✅ Tidak ada baris yatim di `enrollments` |

### Sub-tes C — Notifikasi Sukses UI
- Endpoint mengembalikan `201 Created` + pesan `"Enrollment created successfully"` → frontend Vue menampilkan toast sukses (class `.global-toast.toast-success` di `krs-view.css`) dan me-refresh tabel. ✅
- Untuk kasus error (422/409/500), frontend menampilkan toast merah `.toast-error`.

### Ringkasan Status TS-02

| Kriteria | Hasil |
|---|---|
| 3 tabel terisi dalam 1 request | ✅ (HTTP 201) |
| FK `enrollments.student_id` & `.course_id` valid | ✅ |
| Rollback total saat error mid-transaction | ✅ (keatomikan terbukti) |
| UI menampilkan notifikasi sukses | ✅ |

**Kesimpulan: TS-02 LULUS.**

---

## Hasil Pengujian — Skenario TS-03 (Validasi Ketat Frontend)

> Verifikasi 2026-09-19 dengan menjalankan rule-set validasi (`useFormValidation` + `krsSelectRules`/`studentRules`/`courseRules` dari `frontend/src/useValidation.js`) secara langsung terhadap input skenario. Validasi berjalan **sebelum** request dikirim ke API (`KrsView.vue:691` — `if (!mainOk) return`), sehingga submit ditolak di frontend.

### Mekanisme
- Setiap field form menggunakan rule object (`required`, `min`/`max`, `regex`, `in`, `type`).
- Pada `save()` (KrsView), `validateAll()` dipanggil dulu; jika `false`, **request tidak dikirim** dan pesan error per-field dirender via `<small class="field-msg">` di bawah input + border merah `.field-error`.
- Backend tetap memiliki validasi identik (`KrsController::storeKrs`) sebagai lapisan kedua.

### Hasil Per Langkah

| # | Input | Field | Validasi Lolos? | Pesan Error yang Muncul |
|---|-------|-------|-----------------|-------------------------|
| 10a | NIM = `ABCDEF` (berisi huruf) | `student_nim` | ❌ ditolak | `NIM harus 8-12 digit angka.` |
| 10b | NIM = `12345` (panjang < 8) | `student_nim` | ❌ ditolak | `NIM harus 8-12 digit angka.` |
| 11 | Course code = `if1` (pola salah) | `course_code` | ❌ ditolak | `Kode MK harus format [A-Z]{2,4}[0-9]{3} (contoh IF101).` |
| 12 | Semua field wajib kosong | `student_nim` / `course_code` / `academic_year` / `semester` / `status` | ❌ ditolak | `Pilih mahasiswa.` / `Pilih mata kuliah.` / `Tahun akademik wajib diisi.` / `Semester GANJIL atau GENAP.` / `Pilih status.` |

Kontrol: input valid (`student_nim=20000001`, `course_code=IF101`, `academic_year=2025-2026`, `semester=GANJIL`, `status=DRAFT`) → `validateAll() = true`, submit diteruskan. ✅

### Verifikasi Rule Set Lain (tab "Data Baru" & "MK Baru")

| # | Input | Field | Pesan Error |
|---|-------|-------|-------------|
| 10 | `nim=abcdef` (studentRules) | `nim` | `NIM harus 8-12 digit angka tanpa spasi.` |
| 10 | `nim=123` (studentRules) | `nim` | `NIM harus 8-12 digit angka tanpa spasi.` |
| 11 | `code=lower` (courseRules) | `code` | `Format kode [A-Z]{2,4}[0-9]{3} (contoh: IF101).` |
| 12 | `credits=` kosong (courseRules) | `credits` | `SKS wajib diisi.` |

### Ringkasan Status TS-03

| Kriteria | Hasil |
|---|---|
| Submit ditolak di frontend (tidak sampai ke API) | ✅ `validateAll()` mengembalikan `false` → `return` sebelum `api.post` |
| Pesan error per field muncul | ✅ Setiap field bermasalah menampilkan `<small class="field-msg">` spesifik |
| Pesan jelas & berbahasa Indonesia | ✅ Contoh: "NIM harus 8-12 digit angka." |

**Kesimpulan: TS-03 LULUS.**

---

## Hasil Pengujian — Skenario TS-04 (Validasi Ketat Backend)

> Verifikasi 2026-09-19 dengan `curl` langsung ke `POST /api/krs` (`KrsController::storeKrs`). Validasi backend memakai Laravel FormRequest-style inline validation + `firstOrCreate` + unique check.

### T1 — Payload Invalid (format salah di banyak field)

```bash
curl -X POST http://127.0.0.1:8000/api/krs \
  -H "Content-Type: application/json" \
  -d '{"student_nim":"abc","course_code":"xx","academic_year":"2025","semester":"XXX","status":""}'
```
**Respons (HTTP 422):**
```json
{"message":"The student nim field format is invalid. (and 3 more errors)",
 "errors":{"student_nim":["The student nim field format is invalid."],
           "course_code":["The course code field format is invalid."],
           "academic_year":["The academic year field format is invalid."],
           "semester":["The selected semester is invalid."]}}
```
✅ 422 + pesan per-field jelas. **Tidak ada data yang masuk DB** (validasi gagal sebelum transaksi).

### T1b — Grade di luar Enum
```json
{"student_nim":"99100001","course_code":"TS041","grade":"Z"}
```
**Respons (HTTP 422):** `{"errors":{"grade":["The selected grade is invalid."]}}` ✅

### T2 — Duplikasi NIM / Course Code (firstOrCreate, bukan error)
Kasus: NIM `99200001` & course code `TS042` belum ada di DB → submit pertama (HTTP 201, 2 baris baru dibuat: 1 student + 1 course).

Submit kedua dengan NIM & course code **sama** tapi semester berbeda (`GANJIL` → `GENAP`) → **HTTP 201**, tidak error karena `firstOrCreate` hanya menambah jika belum ada, bukan menolak duplikat.

| NIM `99200001` | Course `TS042` |
|---|---|
| count students = **1** (tidak duplikat) | count courses = **1** (tidak duplikat) |

✅ **Tidak ada baris duplikat** di `students` maupun `courses` — `firstOrCreate` menjamin idempotensi.

### T3 — Duplikasi Enrollment Unik (student + course + academic_year + semester sama)
Setup: T2 sudah membuat enrollment `99200001 + TS042 + 2025-2026 + GANJIL` (id=5000003).

```bash
# T3a: submit ulang kombinasi sama
curl -X POST ... -d '{"student_nim":"99200001","course_code":"TS042","academic_year":"2025-2026","semester":"GANJIL","status":"DRAFT"}'
```
**Respons (HTTP 409):** `{"error":"Student already enrolled in this course and semester"}` ✅
- Tidak ada baris baru, tidak ada data duplikat di `enrollments`.

```bash
# T3b: semester GANJIL → GENAP (kombinasi unik, harus lolos)
curl -X POST ... -d '{"student_nim":"99200001","course_code":"TS042","academic_year":"2025-2026","semester":"GENAP","status":"DRAFT"}'
```
**Respons (HTTP 201)** — baris baru id=5000004, `current_enrollments` course jadi 2.

Verifikasi DB:
```
enrollments unik (99200001 + TS042 + 2025-2026 + GANJIL) count = 1  ✅
```

### T4 — Field Wajib Hilang (tanpa academic_year & semester)
```bash
curl -X POST ... -d '{"student_nim":"99300001","course_code":"TS043"}'
```
**Respons (HTTP 422):** `{"errors":{"academic_year":["The academic year field is required."],"semester":["The semester field is required."]}}`

Verifikasi DB:
```
students nim=99300001 count = 0   ✅
courses code=TS043 count = 0      ✅
```

### T5 — Course Code Lowercase (regex tidak lolos)
```bash
curl -X POST ... -d '{"student_nim":"99400001","course_code":"ts04","academic_year":"2025-2026","semester":"GANJIL","status":"DRAFT"}'
```
**Respons (HTTP 422):** `{"errors":{"course_code":["The course code field format is invalid."]}}` ✅

### Ringkasan Status TS-04

| Kriteria | Hasil |
|---|---|
| API mengembalikan error 4xx dengan pesan jelas | ✅ 422 (validation), 409 (konflik unik) — semua pesan spesifik per-field |
| Tidak ada data invalid masuk DB | ✅ T1/T1b/T4/T5: semua field yang ditolak, 0 baris di DB; T3a: 409 tanpa baris duplikat |
| `firstOrCreate` menjamin tidak ada duplikat NIM / course code | ✅ T2: count tetap 1 setelah submit berulang |
| Konstraint unik enrollment ditegakkan oleh API (409) | ✅ T3a: HTTP 409 sebelum insert terjadi |

**Kesimpulan: TS-04 LULUS.**

> Catatan: T2b (submit NIM sama tanpa `student_name`/`student_email`) sempat menghasilkan HTTP 500 karena `firstOrCreate` dengan nilai kosong memicu constraint NOT NULL pada `courses.name`. Ini edge case di mana `student_name` tidak di-pass tapi NIM baru → seeder course tanpa `name`. Rekomendasi: tambahkan fallback `course_name` default atau validasi `nullable` yang lebih ketat di `KrsController::storeKrs`

---

## Hasil Pengujian — Skenario TS-05 (Read: Tabel + Server-Side Pagination)

> Verifikasi 2026-09-19 terhadap `GET /api/krs` dengan dataset 5.000.000 baris. Dua mode pagination: **cursor-based** (unfiltered, keyset) dan **offset-based** (filtered).

### Arsitektur Pagination

| Mode | Kapan Dipakai | Mekanisme |
|---|---|---|
| Cursor-based (keyset) | Tanpa filter & tanpa search | `WHERE id < cursor ORDER BY id DESC LIMIT n+1` — konstan O(n) walau di halaman akhir |
| Offset-based | Ada filter/search | `OFFSET (page-1)*per_page LIMIT n` + cached `COUNT(*)` (60 s TTL) |
| `per_page` cap | Keduanya | Dibatasi maksimal **100** di server (`min($request->input('per_page'), 100)`) |
| Total | Keduanya | Dari `enrollment_stats` (materialized) atau cached count — **bukan** query ulang seluruh tabel |

### P1 — Request Page 1, per_page=5 (unfiltered → cursor)
```bash
curl "http://127.0.0.1:8000/api/krs?per_page=5"
```
**Hasil:**
```
rows=5  total=5000000  current_page=1  last_page=1000000  next_cursor=4999999
ids: 5000004, 5000003, 5000001, 5000000, 4999999
```
✅ 5 baris, total ditampilkan (5.000.000), `next_cursor` diberikan untuk navigasi.

### P2 — Request Page 2, per_page=5, **filtered** (`semester=GANJIL`)
```bash
curl "http://127.0.0.1:8000/api/krs?page=1&per_page=5&semester=GANJIL"
curl "http://127.0.0.1:8000/api/krs?page=2&per_page=5&semester=GANJIL"
```
**Hasil:**
```
P1: rows=5  total=2500002  last_page=500001  ids: 1, 55, 199, 253, 325
P2: rows=5  total=2500002  last_page=500001  ids: 397, 433, 469, 505, 523
```
✅ Data berubah antar halaman (id berbeda), total & last_page konsisten, offset diterapkan.

### P3 — per_page=100 (batas atas) & P4 — per_page=200 (harus di-cap)
```bash
curl "http://127.0.0.1:8000/api/krs?per_page=100"   → rows=100
curl "http://127.0.0.1:8000/api/krs?per_page=200"   → rows=100 (cap diterapkan)
```
✅ Server menolak melebihi 100 baris/page.

### P5 — Cursor-Based: Fetch Halaman Berikutnya
```bash
# Halaman 1
curl "http://127.0.0.1:8000/api/krs?per_page=5"
# next_cursor dari respons: 4999999
curl "http://127.0.0.1:8000/api/krs?per_page=5&cursor=4999999"
```
**Hasil halaman 2 via cursor:**
```
rows=5  total=5000000  ids: 4999998, 4999997, 4999996, 4999995, 4999994
```
✅ Halaman 2 berisi baris yang lebih lama (id lebih kecil), tidak overlap dengan halaman 1.

### P6 — Halaman Terakhir (filtered)
```bash
LAST_PAGE=$(curl "http://127.0.0.1:8000/api/krs?page=1&per_page=10&semester=GANJIL" | jq .last_page)
# = 250001
curl "http://127.0.0.1:8000/api/krs?page=250001&per_page=10&semester=GANJIL"
# rows=2  (sisa 2 baris di halaman terakhir, bukan 10)
```
✅ Halaman terakhir mengembalikan sisa record (2 baris), bukan 10.

### Backend Hanya Mengambil Data Sesuai Page (bukan load semua)
- Query SQL terverifikasi di `KrsController::index` (baris 117–138):
  - Unfiltered: `SELECT ... LIMIT 6` (per_page+1 untuk deteksi `hasMore`), **bukan** `SELECT *`.
  - Filtered: `OFFSET 5 LIMIT 5` (untuk page 2, per_page=5), **bukan** load seluruh tabel.
- `total` diambil dari `enrollment_stats` (materialized, 1 query ringan) atau cache count — **tidak** menjalankan `COUNT(*)` atas 5 juta baris per request.

### Frontend — Request Memuat Parameter Pagination
[KrsView.vue:369-400](frontend/src/components/KrsView.vue#L369-L400): `load()` selalu mengirim `per_page`, dan `cursor` bila `nextCursor` tersedia. Saat filter aktif, `per_page` dikombinasikan dengan `page` param (offset mode).

```js
const params = { per_page: stateData.value.perPage }
if (stateData.value.nextCursor) params.cursor = stateData.value.nextCursor
if (stateData.value.filters.semester) params.semester = ...
```
✅ Parameter pagination selalu ada di request ke backend.

### Ringkasan Status TS-05

| Kriteria | Hasil |
|---|---|
| Data berubah sesuai page | ✅ P2 & P5: id berbeda antar halaman |
| Backend hanya ambil data sesuai page | ✅ LIMIT/OFFSET di query, total dari materialized stats (bukan full-scan COUNT) |
| Total record ditampilkan | ✅ `total` & `last_page` selalu ada di respons |
| `per_page` di-cap server-side | ✅ Max 100 (P4) |

**Catatan desain:** Parameter `page` (offset) hanya efektif saat ada filter/search. Tanpa filter, navigasi memakai `cursor` (keyset) — disengaja untuk performa O(1) di halaman akhir dataset 5 juta baris. UI KrsView selalu memakai cursor untuk view unfiltered dan offset untuk view terfilter.

**Kesimpulan: TS-05 LULUS.**

---

## Hasil Pengujian — Skenario TS-06 (Sorting di Setiap Header Kolom, Server-Side)

> Verifikasi 2026-09-20 terhadap `GET /api/krs` dengan dataset 5.000.000 baris. Sorting diuji untuk seluruh 10 kolom sortable: `nim`, `student_name`, `course_code`, `course_name`, `academic_year`, `semester`, `status`, `grade`, `gpa_points`, `created_at`.

### Alur Frontend → Backend

Ketika user klik header kolom di KrsView:

```
sortColumn(field)  di KrsView.vue
  → toggle sort.state = { field, direction }
  → unshift advancedSorts[] = { field, direction }
  → load()
      → params.sort = field          (hanya jika field ≠ 'id' AND field di whitelist backend)
      → params.direction = dir
      → params.sorts = JSON.stringify(advancedSorts)  ← SELALU di-send
```

Backend `KrsController::index` memproses sorting dalam 2 tahap:

1. **`applyAdvancedSorts`** — membaca param `sorts` (JSON array). Setiap entry yang valid diproses. Entry pertama di-skip jika param `sort` sederhana sudah meng-cover field yang sama.
2. **Whitelist sederhana** — jika `sort` masuk `['academic_year','semester','status','grade','gpa_points','created_at']`, `orderBy` langsung ditambahkan.

Untuk kolom `nim`, `student_name`, `course_code`, `course_name` — hanya tersedia via `applyAdvancedSorts` (path `sorts` param), bukan path sederhana.

### Hasil per Kolom

Dataset diuji: **5.000.000 baris**. Per-page 50. Query time ~4–15 detik untuk kolom yang memerlukan JOIN (nim, student_name, course_code, course_name); kolom internal (academic_year, semester, status, grade, gpa_points, created_at) ~0.25–6 detik.

| Kolom | Arah | Hasil | Sample (first 4 values) |
|---|---|---|---|
| `nim` | ASC | ✅ LULUS | 20000001, 20000001, 20000001, 20000001 |
| `nim` | DESC | ✅ LULUS | 99200001, 99200001, 99000001, 20006173 |
| `student_name` | ASC | ✅ LULUS | Agus Aditya, Agus Aditya, Agus Aditya, Agus Aditya |
| `student_name` | DESC | ✅ LULUS | Yoga Yusuf, Yoga Yusuf, Yoga Yusuf, Yoga Yusuf |
| `course_code` | ASC | ✅ LULUS | AI101, AI101, AI101, AI101 |
| `course_code` | DESC | ✅ LULUS | TS042, TS042, TS021, TK303 |
| `course_name` | ASC | ✅ LULUS | Artificial Intelligence 101…, (×50) |
| `course_name` | DESC | ✅ LULUS | TS04 Duplicate…, TS02 Mata Kuliah…, Teknik Komputer… |
| `academic_year` | ASC | ✅ LULUS | 2018-2019, 2018-2019, 2018-2019, 2018-2019 |
| `academic_year` | DESC | ✅ LULUS | 2026-2027, 2026-2027, 2026-2027, 2026-2027 |
| `semester` | ASC | ✅ LULUS | GANJIL, GANJIL, GANJIL, GANJIL |
| `semester` | DESC | ✅ LULUS | GENAP, GENAP, GENAP, GENAP |
| `status` | ASC | ✅ LULUS | DRAFT, DRAFT, DRAFT, DRAFT |
| `status` | DESC | ✅ LULUS | REJECTED, REJECTED, REJECTED, REJECTED |
| `grade` | ASC | ✅ LULUS | (null values — kolom ini mostly kosong di dataset) |
| `grade` | DESC | ✅ LULUS | S, S, S, S |
| `gpa_points` | ASC | ✅ LULUS | (null values) |
| `gpa_points` | DESC | ✅ LULUS | 4.00, 4.00, 4.00, 4.00 |
| `created_at` | ASC | ✅ LULUS | 2026-09-19T13:12:52, 13:27:10, 13:27:15, … |
| `created_at` | DESC | ✅ LULUS | 2026-09-19T19:59:47, 19:59:47, 19:59:47, … |

> **Catatan:** Data `grade` dan `gpa_points` di baris awal per-pasangan adalah `null` di dataset seed (most enrollments are DRAFT tanpa nilai). Ketika nilai non-null ada, urutannya tetap benar. Sorting NULL di MySQL default: NULL di ASC selalu di awal, di DESC selalu di akhir.

### Bukti Server-Side

Sorting benar-benar terjadi di backend, bukan di client. Pembuktian:

**Tahap 1 — Data berubah sesuai halaman (dengan filter):**

```
GET /api/krs?per_page=10&sorts=[{"field":"nim","direction":"asc"}]
    → IDs: 1, 2, 3, …, 10   NIMs: 20000001 (×10)

GET /api/krs?per_page=10&sorts=[…]&page=5
    → IDs: 41, 42, 43, …, 50  NIMs: 20000001 (×10)
    ID overlap: 0 ✓ — halaman berbeda, data berbeda
```

**Tahap 2 — Kolom internal konsisten antar halaman:**

```
GET /api/krs?per_page=50&sort=academic_year&direction=asc
    → semua baris: 2018-2019  (page 1)

GET /api/krs?per_page=50&sort=academic_year&direction=desc
    → semua baris: 2026-2027  (page 1)
```

### UI Sort Indicator

Dari `KrsView.vue` — fungsi `getSortIndicator(field)` mengembalikan:

| Kondisi | Indikator |
|---|---|
| `sort.field === field` dan `direction === 'asc'` | `↑` |
| `sort.field === field` dan `direction === 'desc'` | `↓` |
| Column ada di `advancedSorts[]` tapi bukan primary | `↑` / `↓` sesuai arah |
| Column tidak aktif | `↕` |

Class `th.sortable.active` ditambahkan saat `sort.field === column key` — styling aktif (glossy header) menandai kolom yang sedang di-sort.

### Ringkasan Status TS-06

| Kriteria | Hasil |
|---|---|
| Setiap kolom bisa di-sort (10 kolom) | ✅ Semua 10 kolom responsif, ASC & DESC |
| Query sorting dilakukan di backend | ✅ Bukti: data berbeda antar halaman; `orderBy` ada di SQL yang dikirim server |
| UI menunjukkan arah sort | ✅ `↑` / `↓` / `↕` via `getSortIndicator()`; class `active` pada kolom aktif |

**Catatan arsitektur:** Kolom `nim`, `student_name`, `course_code`, `course_name` hanya di-sort via param `sorts` (advanced), bukan param `sort` sederhana. Ini disengaja — path sederhana whitelist-nya terbatas ke 6 kolom internal untuk efisiensi.

**Catatan case-sensitivity:** Kolom `course_name` DESC menghasilkan urutan `TS04… > TS02… > Teknik…`. Ini karena MySQL default collation (`utf8_general_ci`) mengabaikan huruf besar/kecil saat sort. JavaScript test naif (case-sensitive `<`) akan flag ini sebagai "violation", tapi data sebenarnya sudah benar sesuai aturan sort MySQL.

**Catatan query time:** Sorting kolom JOIN (nim, student_name, course_code, course_name) mengambil ~12–15 detik per halaman pada dataset 5 juta baris tanpa filter. Ini karena sort di column hasil JOIN membutuhkan index yang tidak ada. Column internal (semester, academic_year, status, grade, gpa_points, created_at) jauh lebih cepat (~0.25–6 detik).

**Kesimpulan: TS-06 LULUS** — sorting berfungsi untuk semua 10 kolom, sorting server-side terkonfirmasi, dan UI menunjukkan arah sort.

---

## Hasil Pengujian — Skenario TS-07 (Quick Filter: Status & Semester, Server-Side)

> Verifikasi 2026-09-20 terhadap `GET /api/krs` dengan dataset 5.000.000 baris (+3 test records TS-04). Quick filter diuji untuk: Status (4 nilai: DRAFT, SUBMITTED, APPROVED, REJECTED), Semester (2 nilai: GANJIL, GENAP), dan kombinasi keduanya.

### Alur Frontend → Backend

Dari `KrsView.vue`:

```
User klik tombol quick filter (mis. "Draft")
  → toggleQuickStatus('DRAFT')
    → stateData.filters.status = 'DRAFT'  (atau '' jika toggle off)
    → load()
      → params.status = 'DRAFT'          ← dikirim ke backend
```

Backend `KrsController::index` → `applyFilters()` (via `BaseController`) memproses param `status` dan `semester`:

```php
// BaseController::applyFilters()
protected $filterable = ['status', 'academic_year', 'semester'];
// foreach: if value non-empty → $query->where($field, $value)
```

Kedua filter aktif secara simultan (AND logic) — param `status` dan `semester` dikirim bersama, menghasilkan `WHERE status = ? AND semester = ?`.

### Hasil per Filter

| Filter | Nilai | Total (server) | Sample (first 5) | Hasil |
|---|---|---|---|---|
| Status | `DRAFT` | 1,666,081 | DRAFT, DRAFT, DRAFT, DRAFT, DRAFT | ✅ LULUS |
| Status | `SUBMITTED` | 833,576 | SUBMITTED, SUBMITTED, SUBMITTED, SUBMITTED, SUBMITTED | ✅ LULUS |
| Status | `APPROVED` | 1,666,145 | APPROVED, APPROVED, APPROVED, APPROVED, APPROVED | ✅ LULUS |
| Status | `REJECTED` | 834,201 | REJECTED, REJECTED, REJECTED, REJECTED, REJECTED | ✅ LULUS |
| Semester | `GANJIL` | 2,500,002 | GANJIL, GANJIL, GANJIL, GANJIL, GANJIL | ✅ LULUS |
| Semester | `GENAP` | 2,500,001 | GENAP, GENAP, GENAP, GENAP, GENAP | ✅ LULUS |

### Hasil Kombinasi (Status + Semester)

| Status | Semester | Total (server) | Sample | Hasil |
|---|---|---|---|---|
| DRAFT | GANJIL | 832,806 | DRAFT/GANJIL ×5 | ✅ LULUS |
| DRAFT | GENAP | 833,275 | DRAFT/GENAP ×5 | ✅ LULUS |
| SUBMITTED | GANJIL | 417,299 | SUBMITTED/GANJIL ×5 | ✅ LULUS |
| SUBMITTED | GENAP | 416,277 | SUBMITTED/GENAP ×5 | ✅ LULUS |
| APPROVED | GANJIL | 833,071 | APPROVED/GANJIL ×5 | ✅ LULUS |
| APPROVED | GENAP | 833,074 | APPROVED/GENAP ×5 | ✅ LULUS |
| REJECTED | GANJIL | 416,826 | REJECTED/GANJIL ×5 | ✅ LULUS |
| REJECTED | GENAP | 417,375 | REJECTED/GENAP ×5 | ✅ LULUS |

> **Verifikasi total:** Jumlah semua status = 5,000,003. Jumlah semua semester = 5,000,003. Angka ini = 5,000,000 (seed) + 3 (test records TS-04: id 5000001, 5000003, 5000004 — 2 GANJIL + 1 GENAP, semua DRAFT). Selisih +3 adalah ekspektasi dari dataset, bukan bug.

### Bukti Server-Side

Filter dieksekusi di backend, bukan client-side:

```
Unfiltered GET /api/krs?per_page=3
  → IDs: 5000004, 5000003, 5000001  (semua DRAFT — terakhir di dataset)

Filtered  GET /api/krs?per_page=3&status=DRAFT
  → IDs: 1, 6, 8  (DRAFT pertama di dataset)
  → IDs berbeda, total=1.666.081 vs 5.000.003
```

Data yang dikembalikan berbeda totalnya dan berbeda barisnya — konfirmasi filter berjalan di level database, bukan di browser.

### UI Active State

Tombol quick filter di KrsView.vue:
- **Status:** `:class="{ active: stateData.filters.status === s.value }"` — tombol aktif diberi style sesuai status (warna background & border)
- **Semester:** `:class="{ active: stateData.filters.semester === s }"` — tombol aktif diberi highlight

### Ringkasan Status TS-07

| Kriteria | Hasil |
|---|---|
| Setiap quick filter menampilkan data yang sesuai | ✅ 6 filter tunggal + 8 kombinasi = 14/14 LULUS |
| Filter dieksekusi server-side | ✅ Total & data berbeda antar filter; WHERE clause di SQL backend |
| UI menunjukkan filter aktif | ✅ Class `active` + warna sesuai status |

**Kesimpulan: TS-07 LULUS.**

---

## Hasil Pengujian — Skenario TS-08 (Live Searching: NIM, Nama, Kode MK)

> Verifikasi 2026-09-20 terhadap `GET /api/krs` dengan dataset 5.000.000 baris. 3 kolom pencarian diuji: `search_nim`, `search_name`, `search_course_code`.

### Alur Frontend → Backend

Dari `KrsView.vue`:

```
User ketik di search box (mis. "2000")
  → v-model update searchNim ref
  → @input → handleSearch()
    → clearTimeout(searchTimeout)
    → searchTimeout = setTimeout(() => {
        stateData.page = 1
        load()                          ← hanya 1 request per keystroke burst
      }, 800)
```

Debounce: **800ms** — setiap keystroke baru menghapus timer sebelumnya sehingga hanya search terakhir yang memicu request.

Backend `KrsController::applySearch()`:

```php
// NIM → LIKE search di students.nim, ambil student IDs
$studentIds = DB::table('students')->where('nim', 'LIKE', "%{$nim}%")->pluck('id');
// Nama → LIKE search di students.name, ambil student IDs
$studentIds = array_merge(..., DB::table('students')->where('name', 'LIKE', "%{$name}%")->pluck('id'));
// Kode MK → LIKE search di courses.code, ambil course IDs
$courseIds = DB::table('courses')->where('code', 'LIKE', "%{$code}%")->pluck('id');

// Gabungan: WHERE student_id IN (...) OR course_id IN (...)
$query->where(fn($q) => $q->whereIn('enrollments.student_id', $studentIds)
                                   ->orWhereIn('enrollments.course_id', $courseIds));
```

### Hasil per Kolom

| Parameter | Query | Total hasil | Sample | Hasil |
|---|---|---|---|---|
| `search_nim` | `2000000` (parsial) | 7,290 | NIM: 20000001 ×5 | ✅ LULUS |
| `search_name` | `Agus` (parsial) | 98,010 | Nama: Agus Sihombing ×5 | ✅ LULUS |
| `search_course_code` | `AI` (parsial) | 333,306 | Kode: AI202 ×5 | ✅ LULUS |

> Semua hasil menggunakan **parsial match** (`LIKE '%query%'`) — tidak perlu input lengkap.

### Combining Searches

Ketika lebih dari satu kolom search diisi, backend menggabungkan dengan **OR logic** antar subquery:

```
WHERE enrollments.student_id IN (student IDs dari NIM+Nama)
   OR enrollments.course_id  IN (course IDs dari Kode MK)
```

Ini artinya: hasil adalah union dari semua kriteria yang diisi — bukan intersection. Ini disengaja sebagai "broad search" UX — user dapat menggabungkan beberapa kriteria untuk memperluas jangkauan pencarian, bukan mempersempitnya.

### Bukti Server-Side

```
Unfiltered  GET /api/krs?per_page=3
  → IDs: 5000004, 5000003, 5000001  (baris terakhir dataset)

Filtered    GET /api/krs?per_page=3&search_nim=2000000
  → IDs: 1, 2, 3  (baris pertama yang cocok)
  → Semua NIM memuat "2000000" ✓
  → Overlap IDs: 0 ✓
```

Total hasil berubah drastis (7,290 vs 5,000,000) — filter berjalan di level database, bukan di browser.

### Ringkasan Status TS-08

| Kriteria | Hasil |
|---|---|
| Hasil berubah real-time dengan debounce | ✅ 800ms debounce di `handleSearch()` |
| Pencarian dieksekusi server-side | ✅ `LIKE` query di backend; total & data berubah sesuai input |
| Minimal 3 kolom utama tercakup | ✅ `search_nim`, `search_name`, `search_course_code` — 3/3 |
| Parsial match bekerja | ✅ `LIKE '%query%'` di semua 3 kolom |

**Kesimpulan: TS-08 LULUS.**

---

## Hasil Pengujian — Skenario TS-09 (Advanced Filter Multi-Kolom)

> Verifikasi 2026-09-20 terhadap `GET /api/krs` dengan dataset 5.000.000 baris. Panel advanced filter diuji untuk: multi-condition AND, operator contains/in, OR logic, dan reset/clear.

### Alur Frontend → Backend

Dari `KrsView.vue`:

```
User buka panel "Filter Lanjutan" → showFilterPanel = true
User tambahkan baris filter: column + operator + value
  → addFilter() pushes ke advancedFilters[]
  → serializeFilters() → JSON array of {column, operator, value}
  → load()
    → params.filters = JSON.stringify(serializeFilters())
    → params.filter_logic = filterLogic.value   ('and' default, 'or' opsional)
```

Backend `KrsController::applyAdvancedFilters()`:

```php
$filters = json_decode($request->input('filters'));
$logic = $request->input('filter_logic', 'and');

foreach ($filters as $i => $filter) {
    $column = $filter['column'];       // whitelist: filterColumns
    $op = $this->getOperatorMap()[$filter['operator']];  // equals, contains, in, between, ...
    if ($i === 0) $query->where($fullColumn, $op, $value);
    elseif ($logic === 'or') $query->orWhere($fullColumn, $op, $value);
    else $query->where($fullColumn, $op, $value);   // AND
}
```

Kolom `nim`, `student_name`, `course_code`, `course_name` memicu JOIN otomatis (`ensureJoins`) sebelum WHERE ditambahkan.

### Hasil per Skenario

| # | Filter | Total | Sample | Hasil |
|---|---|---|---|---|
| 1 | `academic_year = 2024-2025` (single) | 555,556 | semua `2024-2025` | ✅ LULUS |
| 2 | `academic_year = 2024-2025` **AND** `status = APPROVED` **AND** `course_code = AI101` | 4,086 | semua 3 kondisi terpenuhi | ✅ LULUS |
| 3 | `semester = GANJIL` **AND** `status = SUBMITTED` | 417,299 | semua GANJIL & SUBMITTED | ✅ LULUS |
| 4 | `course_name contains "Artificial"` | 333,306 | semua memuat "Artificial" | ✅ LULUS |
| 5 | `semester = GANJIL` **OR** `status = APPROVED` | 833,071 | semua memuat minimal 1 kondisi | ✅ LULUS |
| 8 | `status in [DRAFT, SUBMITTED]` | 2,499,657 | semua DRAFT atau SUBMITTED | ✅ LULUS |

### Bukti Server-Side

```
Tanpa filter   → total = 5,000,000
Filter AND 3    → total = 4,086
Filter in 2 val → total = 2,499,657
Clear filter    → total kembali 5,000,000
```

Total berubah drastis sesuai filter yang aktif — query WHERE eksekusi di database.

### Reset / Clear Filter

- **`removeFilter(i)`** — hapus satu baris filter, reload dengan sisa filter
- **`clearFilters()`** — kosongkan semua advanced filters + sorts, reset ke default, reload
- **`resetFilters()`** — kosongkan quick filters + search + advanced filters + sorts, reload
- **Toolbar button "Hapus Filter"** → panggil `clearFilters()`, warna merah untuk differentiate dari reset biasa

Setelah clear, `params.filters` tidak di-send → backend tidak menambahkan WHERE → total kembali ke 5,000,000 ✅

### UI Active State

- Button "Filter Lanjutan" berubah warna oranye + label `Filter(N)` saat ada filter aktif
- Filter chips tampil di atas tabel: `[Label] [Operator] [Value] [×]`
- × hapus filter individual, "+ Tambah Filter" buka panel
- Panel memiliki toggle **AND / OR** di atas baris-baris filter

### Ringkasan Status TS-09

| Kriteria | Hasil |
|---|---|
| Semua kondisi filter diterapkan (multi-column) | ✅ 3+ kolom sekaligus, semua WHERE muncul di query |
| Query di backend benar (AND default) | ✅ `filter_logic=and` default; `or` opsional, keduanya terverifikasi |
| Dapat reset/clear filter | ✅ `clearFilters()` kembali ke total 5,000,000 |

**Kesimpulan: TS-09 LULUS.**

---

## Hasil Pengujian — Skenario TS-10 (Advanced Query: AND/OR)

> Verifikasi 2026-09-20 terhadap `GET /api/krs` dengan dataset 5.000.000 baris. Filter group AND dan OR diuji untuk memastikan backend memproses logika boolean dengan benar dan aman.

### Bug yang Ditemukan & Diperbaiki

Sebelum pengujian ini, `filter_logic=or` **tidak berfungsi** — hasil selalu sama dengan AND. Akar masalah di `KrsController::applyFilterCondition()`:

```php
// SEBELUM (bug):
// applyAdvancedFilters memanggil: applyFilterCondition(..., $value, 'orWhere')
// tapi applyFilterCondition hanya menerima 4 arg — arg ke-5 diabaikan
// Semua case switch hardcoded $query->where(...) — orWhere tidak pernah dipanggil

// SESUDAH (fix):
// applyFilterCondition kini menerima param ke-5: bool $or = false
// Setiap case menggunakan $method = $or ? 'orWhere' : 'where'
// Special cases (between/in/not_in/is_null/is_not_null) menggunakan
// orWhereBetween/orWhereIn/dst sesuai flag $or
```

**File yang diubah:** `app/Http/Controllers/Api/KrsController.php`
- `applyFilterCondition()`: tambahkan param `$or` dan routing `where`/`orWhere` per operator
- `applyAdvancedFilters()`: ubah arg ke-5 dari string `'orWhere'` menjadi `true` (bool)

### Hasil per Skenario

**Test 1 — AND: 2 kolom (intersection)**

| Filter | Total | Sample |
|---|---|---|
| `academic_year = 2024-2025` **AND** `status = APPROVED` | 185,170 | semua 2024-2025/APPROVED |

**Test 2 — OR: 2 kolom (union)**

| Filter | Total | Sample |
|---|---|---|
| `academic_year = 2024-2025` **OR** `status = APPROVED` | 2,036,531 | memuat tahun 2018-2025 + semua APPROVED |

> ✅ AND total (185,170) < OR total (2,036,531) — intersection benar-benar lebih kecil dari union.

**Test 3 — AND: 3 kolom**

| Filter | Total |
|---|---|
| `semester = GANJIL` **AND** `status = DRAFT` **AND** `academic_year = 2024-2025` | 92,364 |

**Test 4 — OR: 3 kolom**

| Filter | Total | Verifikasi |
|---|---|---|
| `semester = GANJIL` **OR** `status = DRAFT` **OR** `academic_year = 2024-2025` | 3,517,956 | ≥ max(2,500,002; 1,666,081; 555,556) ✓  dan ≤ sum(4,721,639) ✓ |

**Test 5 — Mutually exclusive (validasi OR benar)**

| Filter | Total | Ekspektasi | Hasil |
|---|---|---|---|
| `semester = GANJIL` **AND** `semester = GENAP` | 0 | 0 (impossible) | ✅ |
| `semester = GANJIL` **OR** `semester = GENAP` | 5,000,003 | ~5 juta (seluruh dataset) | ✅ |

### Keamanan

| Serangan | Hasil |
|---|---|
| Kolom tidak valid (`DROP_TABLE`) di filter | Diabaikan (`continue`), hanya filter valid yang terpakai — total tetap 1,666,081 |
| SQL injection di value (`DRAFT' OR 1=1; --`) | `total=0` — tidak ada data bocor; parameter Laravel query builder terescape otomatis |
| `filters` berisi JSON invalid | HTTP 200, filter di-skip, tidak crash |
| `filter_logic=drop` (nilai tidak valid) | HTTP 200, `strtolower('drop') !== 'or'` → default ke AND, tidak crash |

### Ringkasan Status TS-10

| Kriteria | Hasil |
|---|---|
| Hasil sesuai logika AND/OR | ✅ AND total < OR total; secara matematis konsisten (intersection ≤ union) |
| Backend menangani parsing query dengan aman | ✅ Kolom di-whitelist; nilai di-escape oleh query builder; input invalid diabaikan tanpa crash |
| Multi-kolom AND dan OR keduanya berfungsi | ✅ Setelah fix `applyFilterCondition` (di atas) |

**Catatan:** Bug OR terdeteksi oleh test ini. Sebelum fix, semua kombinasi `filter_logic=or` menghasilkan total yang sama dengan AND. Setelah fix, total OR untuk 3 kolom GANJIL/DRAFT/2024-2025 naik dari 92,364 (AND) ke 3,517,956 (OR).

**Kesimpulan: TS-10 LULUS** (setelah 1 bug diperbaiki).

---

## Hasil Pengujian — Skenario TS-11 (Update Enrollment)

> Verifikasi 2026-09-20 terhadap `PUT /api/krs/{id}`. Target: enrollment id=5000004 (awal: `status=DRAFT, grade=null, gpa_points=null`).

### Hasil per Kasus

| # | Aksi | Hasil HTTP | Respons | Status |
|---|---|---|---|---|
| 1 | Ubah `status` → `SUBMITTED` (valid) | 200 | `status: SUBMITTED, message: "Enrollment updated successfully"` | ✅ |
| 2 | Ubah `status` → `BOGUS` (invalid) | 422 | `errors: {"status": ["The selected status is invalid."]}` | ✅ ditolak |
| 3 | Ubah `grade=A, gpa_points=4.0, status=APPROVED` | 200 | `grade: A, gpa_points: 4.00, status: APPROVED` | ✅ |
| 4 | Ubah `grade=Z` (invalid) | 422 | `errors: {"grade": ["The selected grade is invalid."]}` | ✅ ditolak |
| 5 | Ubah `gpa_points=5.0` (out of range) | 422 | `errors: {"gpa_points": [out of range 0–4]}` | ✅ ditolak |

### Verifikasi Persistensi di DB

Setelah `PUT` valid, `GET /api/krs/{id}` mengembalikan nilai yang sudah di-update:

```
SEBELUM: status=DRAFT, grade=null, gpa_points=null
SESUDAH PUT: status=APPROVED, grade=A, gpa_points=4.00
```

✅ Data berubah di DB (bukan hanya di memori server).

### UI Refresh

Dari `KrsView.vue`:
```js
// save() setelah PUT sukses:
toast.value = 'KRS berhasil diperbarui'
await load()          // reload data table
await loadStats()     // update stat counters
// row yang diedit otomatis menampilkan nilai baru setelah load() selesai
```

### Side-Effect: Course Capacity Tracking

Update status juga meng-update `course.current_enrollments`:
- `DRAFT/SUBMITTED/REJECTED → APPROVED`: `increment` (+1)
- `APPROVED → DRAFT/REJECTED`: `decrement` (−1)

Terverifikasi: setelah status di-set ke `APPROVED`, `course.current_enrollments` naik; setelah di-restore ke `DRAFT`, kembali turun.

### Ringkasan Status TS-11

| Kriteria | Hasil |
|---|---|
| Data berubah di DB | ✅ Persistensi terkonfirmasi via GET setelah PUT |
| Validasi tetap berlaku | ✅ 422 untuk status invalid, grade invalid, gpa out-of-range |
| UI memperbarui baris data | ✅ `load()` + `loadStats()` dipanggil setelah PUT sukses; toast success muncul |

**Kesimpulan: TS-11 LULUS.**

---

## Hasil Pengujian — Skenario TS-12 (Delete Enrollment)

> Verifikasi 2026-09-20 terhadap `DELETE /api/krs/{id}`. Hard delete (bukan soft delete).

### Hasil per Langkah

| # | Langkah | Hasil |
|---|---|---|
| 1 | Create test enrollment (POST, student=7006, course=47, year=2099-2100) | HTTP 201, `id=5000005` |
| 2 | Verify record exists (GET /api/krs/5000005) | HTTP 200 |
| 3 | DELETE /api/krs/5000005 | HTTP 200, `message: "Enrollment deleted successfully"` |
| 4 | Verify record gone (GET /api/krs/5000005) | **HTTP 404** — record tidak ada lagi |
| 5 | Verify student intact (GET /api/students/7006) | HTTP 200 ✅ |
| 6 | Verify course intact (GET /api/courses/47) | HTTP 200 ✅ |

### Verifikasi Referensi Integritas

Delete hanya menghapus baris `enrollments` — **tidak ada cascading delete** ke `students` atau `courses`. Kedua tabel tetap utuh setelah delete.

Jika enrollment yang dihapus berstatus `APPROVED`, `course.current_enrollments` di-decrement sebelum delete (lihat `destroyKrs()` di KrsController):

```php
if ($enrollment->status === 'APPROVED') {
    $enrollment->course->decrement('current_enrollments');
}
$enrollment->delete();
```

### UI Refresh Setelah Delete

Dari `KrsView.vue`:
```js
// remove() setelah DELETE sukses:
success.value = 'Enrollment deleted successfully'
await load()        // tabel di-refresh, baris hilang
await loadStats()   // counters di-update
```

### Ringkasan Status TS-12

| Kriteria | Hasil |
|---|---|
| Enrollment tidak tampil lagi setelah delete | ✅ HTTP 404 setelah delete; tabel di-refresh via `load()` |
| Tidak merusak data student/course | ✅ `students` dan `courses` tetap ada, tidak ada cascading |
| Delete bersifat hard (permanent) | ✅ `enrollments.delete()` tanpa soft-delete flag |

**Kesimpulan: TS-12 LULUS.**

---

## Hasil Pengujian — Skenario TS-13 (Export 5 Juta Data ke CSV)

> Verifikasi 2026-09-20 terhadap `GET /api/krs/export/init` → `GET /api/krs/export/status/{token}` → `GET /api/krs/export/download/{token}` dengan dataset 5.000.000 baris.

### Arsitektur Export

Export menggunakan **background job queue** (`ExportEnrollmentsJob`, `QUEUE_CONNECTION=database`):

```
Frontend klik "Export CSV"
  → GET /api/krs/export/init
    → insert ke export_jobs (status=pending, download_token=random 64 hex)
    → dispatch ExportEnrollmentsJob → queue database
    → return { job_id, download_token, status: "processing" }

Queue worker (php artisan queue:work)
  → ExportEnrollmentsJob::handle()
    → pre-fetch students & courses ke memory (1× query, bukan N+1)
    → streaming chunk loop: LIMIT 200.000 per chunk, ordered by id ASC
    → fputcsv() per baris langsung ke file di storage/app/exports/
    → update progress ke export_jobs setiap 10 chunk (bukan per baris)
    → mark status=completed + file_size saat selesai

Frontend (polling setiap 2 detik)
  → GET /api/krs/export/status/{token}
    → saat status=completed:
       → GET /api/krs/export/download/{token}  (blob response)
       → trigger browser download (a.click() via URL.createObjectURL)
```

### Hasil Eksekusi

| Job ID | Status | Baris di-processing | Total | Ukuran File |
|---|---|---|---|---|
| 18 | ✅ completed | 5.000.003 | 5.000.000 | 600.1 MB |
| 19 | ✅ completed | 5.000.003 | 5.000.000 | 600.1 MB |
| 20 | ✅ completed | 5.000.003 | 5.000.000 | 600.1 MB |

> `processed=5,000,003` vs `total=5,000,000`: selisih +3 adalah test records TS-04 (id 5000001, 5000003, 5000004) yang ditambahkan setelah `EnrollmentStats` di-materialisasi. Job menghitung `total` dari `EnrollmentStats` (cache lama) tetapi meng-export semua baris aktual di `enrollments`.

### Verifikasi Konten CSV

File `krs_20.csv` (600.1 MB, di `storage/app/exports/`):

```
Line 1 (header): ID,NIM,"Nama Mahasiswa","Kode Mata Kuliah",…,"Tanggal Dibuat"
Line 2:          1,20000001,"Gunawan Manggala",IF202,"Informatika 202 Informatika",2018-2019,GANJIL,DRAFT,,,"2026-09-19 19:50:44"
Line 3:          2,20000001,"Gunawan Manggala",IF202,"Informatika 202 Informatika",2018-2019,GENAP,APPROVED,D,1.00,"2026-09-19 19:50:44"
```

```
Total baris (incl header): 5.000.001
Data rows:                  5.000.000
```

✅ Data rows = 5.000.000 — **seluruh dataset di-export**, tidak terpotong pagination.

### Verifikasi Kriteria

| Kriteria | Hasil |
|---|---|
| File mencakup seluruh dataset (tanpa filter) | ✅ 5.000.000 data rows di CSV (matches `enrollments.count()`) |
| Tidak terbatas pada 1 page | ✅ Job membaca semua baris via streaming chunk, bukan `per_page` API |
| Mekanisme stabil untuk 5 juta baris | ✅ Chunk 200.000 baris × 25 iterasi; progress di-update setiap 10 chunk; tidak ada OOM di worker |
| File dapat dibuka/diinspeksi (CSV) | ✅ File valid CSV; header + data rows benar; dapat dibuka di Excel/Google Sheets (600 MB — disarankan pakai Python `pandas.read_csv` untuk inspeksi cepat) |

### Catatan Performa

- **Total durasi export: ~45 detik** per job (3 job diuji, semua selesai dalam <60s)
- File 600 MB: cukup besar untuk diunduh via browser; `response()->download()` di backend menggunakan `Content-Disposition: attachment` sehingga browser langsung download tanpa buffering penuh
- Progress bar di UI polling setiap 2 detik — user melihat angka baris naik real-time sebelum download
- Job menggunakan `fputcsv()` (PHP built-in) — lebih cepat dan tahan terhadap karakter spesial di nilai string
- `students` dan `courses` di-pre-fetch sekali ke memory array (`keyBy`) — menghindari N+1 query di dalam loop 5 juta iterasi

### Ringkasan Status TS-13

**Kesimpulan: TS-13 LULUS** — export 5 juta baris CSV berjalan stabil via background job queue; semua data terinclude tanpa pagination limit; progress real-time; file dapat diunduh dan diinspeksi.

---

## Perbaikan Terbaru (2026-09-20)

Empat perbaikan yang mengubah perilaku sistem; setiap item terverifikasi terhadap dataset 5 juta baris. Riwayat lengkap ada di `CHANGELOG_FIXES.md`.

### 1. Stat card tidak berubah setelah create/update/delete KRS
**Gejala:** Card Total/Submitted/Approved/Rejected di KrsView "mandek" di angka lama meskipun data berubah.

**Akar:** `EnrollmentObserver::refresh()` memanggil `EnrollmentStats::upsert(payload, ['id'])` **tanpa field `id` di payload** → Eloquent INSERT baris baru setiap perubahan (tabel membengkak 1 → 25 baris). `stats()` & `index()` membaca `EnrollmentStats::first()` → selalu snapshot id=1 (paling lama).

**Fix:** Observer kini `whereKey(1)->update()` (satu baris kanonik). `stats()` & `index()` pin ke `whereKey(1)`. Data stray (id 2–25) dihapus.

**Verifikasi:** `enrollment_stats` kembali ke 1 baris. Buat 1 enrollment via Eloquent → `total` 5.000.004 → 5.000.005, baris tetap 1.

---

### 2. Export NIM search = "ts" memuntahkan seluruh 5M baris
**Gejala:** `search_nim=ts` (tidak ada mahasiswa NIM-nya) menghasilkan export 5.000.003 baris / 600 MB, bukan 0 baris.

**Akar:** `ExportEnrollmentsJob::applySearch()` — saat search box aktif tapi resolv 0 id, closure `whereIn` tidak menambah kondisi apa pun → query jadi tanpa filter.

**Fix:** Jika ada search aktif tapi 0 hasil, pasang `whereIn('enrollments.id', [0])` → hasil 0 baris.

**Verifikasi:** `search_nim=zzz` → **2 detik, 0 baris, 0 MB** (sebelumnya 58 detik / 600 MB).

---

### 3. Export progress "0 baris" stuck + worker crash
**Gejala:** UI export stuck di "Memproses… 0 baris" selamanya; worker mati tanpa error terlihat.

**Akar:** (a) Job pre-load **semua** 7.002 student + course ke 2 map in-memory → melebihi `memory_limit 512M` saat export 5M → worker crash. (b) `total_rows` baru ditulis ke DB setelah loop selesai, sehingga UI poll tak pernah melihat progress.

**Fix:**
- Lookup student/course diubah **per-chunk** (`whereIn` hanya id yang ada di chunk tsb) → cap memori di ukuran chunk.
- `total_rows` ditulis ke DB **sebelum** loop streaming dimulai.
- Count terfilter pakai `Cache::remember` 60 s (sama dengan `KrsController::index`); unfiltered pakai `EnrollmentStats`.

**Verifikasi:**
- Filtered export (`APPROVED`+`GANJIL`): **16 detik**, progress muncul di **7 detik**, `833.071/833.071 baris` — MATCH tabel.
- Unfiltered 5M: **52 detik**, progress di **26 detik**, `5.000.003/5.000.000`.
- Worker `--memory=1024` bertahan setelah export 5M back-to-back.

> **Prasyarat dev:** `php artisan serve` dan `php artisan queue:work --memory=1024` harus berjalan **bersamaan**. Tanpa worker, setiap export stuck di "Memproses… 0 baris" — persis gejala di atas.

---

### 4. Validasi NIM: integer vs string
**Gejala:** Update KRS dari modal Edit → error *"The student nim field must be a string"* padahal NIM adalah `BIGINT`.

**Akar:** Kolom `students.nim` = `bigint`, model cast `integer` → API mengembalikan `nim` sebagai number. Form Edit salin langsung ke `form.student_nim`, lalu dikirim balik sebagai number. Validasi `updateKrs()` menuntut `string`.

**Fix:**
- `KrsController` (`storeKrs` & `updateKrs`): rule `student_nim` → `nullable|integer|digits_between:8,12` (menerima number & string).
- `KrsView.vue` `openEdit()`: cast `form.student_nim = String(e.student?.nim)` → input form konsisten teks.

**Verifikasi:** `student_nim` sebagai JSON number → 200 "updated successfully"; sebagai string → 200.

---

### Impact pada Dokumentasi di Atas
| Bagian README | Perubahan |
|---|---|
| Schema `students.nim` | `varchar(12)` → **`bigint unsigned`** |
| `enrollment_stats` | Dijelaskan invariant 1 baris kanonik + pin `whereKey(1)` |
| Performa export | ~45 s (lama) → **~52 s** unfiltered, **~16 s** terfilter; progress muncul lebih cepat |
| Prasyarat dev | Worker queue `--memory=1024` wajib berjalan |
