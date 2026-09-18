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
| nim | varchar(12) unique | NIM |
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

| Skenario | Waktu |
|----------|-------|
| List unfiltered (cached total) | ~300 ms |
| Stats cards | ~260 ms |
| Filter semester (cached) | ~280 ms |
| Search NIM (two-phase) | ~400 ms |
| Export 5 juta ke CSV | ~30 menit (async, <500 MB RAM) |

Optimasi: materialized stats, cached total, two-phase search, composite indexes, query cache 60 s, chunked export 200K baris/chunk.

---

## Kontribusi
1. Fork repo
2. Buat branch (`git checkout -b feature/xxx`)
3. Commit & push
4. Buat Pull Request
