# CATATAN — Proses KRS

Catatan singkat stack, performa, dan limitasi. Untuk detail fix & riwayat, lihat `CHANGELOG_FIXES.md`.

---

## Stack

| Layer | Teknologi | Versi
|---|---|---|
| Backend | PHP + Laravel | PHP **8.3.26**, Laravel **13.32** |
| Frontend | Vue 3 + Vite | Vue **3.5.42**, Vite **8.3**, Axios **1.20** |
| Build | `npm run build` → `dist/*` → `public/assets/` | gitignore; hanya `public/index.html` yang di-commit |
| DB | MySQL | **8.4.3** (Laragon) |
| Queue | `QUEUE_CONNECTION=database` | Export CSV via `queue:work --memory=1024` |
| Cache | `CACHE_STORE=database` | Count terfilter cached 60 s (`Cache::remember`) |
| Node | | **v24.12.0** (dev); production build pakai apa pun 20+ |

> Di deploy produksi, ganti `QUEUE_CONNECTION` & `CACHE_STORE` ke **Redis** agar cepat (lihat README §Deploy).

---

## Performa (dataset 5.000.000 enrollments, 7.002 students, 47 courses)

| Skenario | Waktu terukur |
|---|---|
| List unfiltered (total dari `enrollment_stats`) | ~300 ms |
| Stats cards (1 baris kanonik) | ~260 ms |
| Filter semester (cached count 60 s) | ~280 ms |
| Search NIM parsial (two-phase) | ~400 ms |
| **Export terfilter** (quick filter + search, <1000 baris) | **~16 s**, progress tampil di **7 s** |
| **Export tanpa filter** (5 juta baris, 600 MB CSV) | **~52 s**, progress tampil di **26 s** |
| Sorting kolom JOIN (nim, student_name, course_code, course_name) | ~4–15 s/halaman |
| Sorting kolom internal (status, grade, gpa_points, created_at) | ~0.25–6 s/halaman |

### Optimasi yang sudah diterapkan
- `enrollment_stats` materialized (1 baris kanonik, `whereKey(1)`) — total & breakdown instan.
- Count terfilter di-cache 60 s (`Cache::remember`, key = hash SQL+bindings).
- Cursor-based pagination untuk unfiltered (O(1) di halaman akhir).
- Export chunked: 200K baris/chunk (unfiltered), 50K/chunk (terfilter); lookup student/course **per-chunk**, bukan pre-load seluruh tabel → cap memori di ukuran chunk.
- Composite index `idx_enrollments_semester_status (semester, status, academic_year)`.

---

## Asumsi / Limitasi

### 1. Dua proses wajib jalan di dev
```
php artisan serve --host=127.0.0.1 --port=8000        # web
php artisan queue:work --tries=1 --timeout=3600 --memory=1024   # export
```
Tanpa `queue:work`, **setiap export stuck di "Memproses… 0 baris"** (job tidak diproses). Ini gejala yang sering muncul jika lupa menjalankan worker.

### 2. `enrollment_stats` harus selalu 1 baris
Observer update baris id=1 via `whereKey(1)->update()`. Jika environment punya leftover baris duplikat (mis. dari versi lama), bersihkan sekali:
```sql
DELETE FROM enrollment_stats WHERE id > 1;
-- lalu recompute:
UPDATE enrollment_stats SET
  total=(SELECT COUNT(*) FROM enrollments),
  draft=(SELECT COUNT(*) FROM enrollments WHERE status='DRAFT'),
  submitted=(SELECT COUNT(*) FROM enrollments WHERE status='SUBMITTED'),
  approved=(SELECT COUNT(*) FROM enrollments WHERE status='APPROVED'),
  rejected=(SELECT COUNT(*) FROM enrollments WHERE status='REJECTED');
```

### 3. NIM adalah `BIGINT UNSIGNED`, bukan string
Kolom `students.nim` dikonversi dari `varchar(12)` ke `bigint unsigned` (migration `convert_numeric_columns_to_integer`). Model cast `integer`. Konsekuensi:
- API mengembalikan `nim` sebagai **number** (bukan quoted string).
- Form frontend sekarang cast ke `String()` di `openEdit()` agar input konsisten.
- Validasi backend menerima `integer` (`digits_between:8,12`).
- NIM **tidak boleh** diawali `0` (leading-zero hilang saat convert ke integer).

### 4. `processed_rows` bisa melebihi `total_rows`
Jumlah `processed_rows` = jumlah baris aktual di `enrollments` saat export. `total_rows` berasal dari `enrollment_stats` (snapshot terakhir). Jika ada enrollment baru yang dibuat setelah snapshot, selisihnya kecil (+1 sampai beberapa). Bukan bug.

### 5. File export 600 MB
Export 5M baris menghasilkan CSV ~600 MB di `storage/app/exports/`. Untuk inspeksi cepat, gunakan `pandas.read_csv(..., chunksize=...)` di Python, bukan Excel. File otomatis dibuat per-job dan aman dihapus setelah download.

### 6. Worker memory
`memory_limit` CLI default 512 MB. Export 5M baris + lookup per-chunk aman di **1024 MB**. Jika naik ke skala >10M, naikkan `--memory` dan pertimbangkan Redis untuk `CACHE_STORE`.

### 7. `APP_KEY` di `.env`
`APP_KEY` saat ini hardcoded untuk dev. **Jangan commit** value production ke repo — generate ulang per environment.

### 8. Seeder `TRUNCATE` 5M baris
Seeder `AcademicSeeder` memakai `TRUNCATE` + batch insert 2000/baris. Di MySQL 8.4, durasi penuh ~10 menit.Seeder meng-*overwrite* seluruh `students`, `courses`, `enrollments` — gunakan backup sebelum seed di environment yang berisi data produksi.

### 9. Session driver `database`
`SESSION_DRIVER=database` berarti sesi disimpan di tabel `sessions`. Di produksi dengan banyak user, pertimbangkan Redis/DB driver lain agar tabel `sessions` tidak bengkak.

### 10. `grade` & `gpa_points` banyak null di dataset seed
Mayoritas baris seed berstatus `DRAFT` tanpa nilai. Sorting `grade`/`gpa_points` akan menampilkan baris null di awal (ASC) atau akhir (DESC) — ini perilaku MySQL default, bukan bug.
