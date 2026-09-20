# 🧠 Catatan Perbaikan — proses_akademik

Riwayat fix terverifikasi. Entri terbaru di atas.

### Fix #3 — Stat card stuck at angka lama: observer stats menyisipkan baris baru, bukan update

| Tanggal | 2026-09-20 |
| File | `app/Observers/EnrollmentObserver.php`, `app/Http/Controllers/Api/KrsController.php` |
| Masalah | Setelah menambah/mengubah KRS, angka di card (Total KRS / Submitted / Approved / Rejected) tidak berubah — tetap menampilkan nilai lama |
| Akar | (1) `EnrollmentObserver::refresh()` melakukan `upsert([...], ['id'])` **tanpa field `id` di payload** → Eloquent memperlakukannya sebagai insert baru, bukan update. Tabel `enrollment_stats` membengkak jadi 25 baris. (2) `stats()` membaca `EnrollmentStats::first()` → selalu mengembalikan baris id=1, yaitu snapshot paling lama (total 5.000.000), bukan yang terbaru |
| Fix | Observer sekarang selalu update **satu baris kanonik (id=1)** via `whereKey(1)->update()`. `stats()` & `index()` juga diubah ke `whereKey(1)->first()` / `whereKey(1)->firstOr(...)`. Baris duplikat (id 2–25) dihapus manual |
| Verifikasi | Sebelum: `enrollment_stats` punya 25 baris, UI baca baris id=1 (total 5.000.000, stale). Sesudah: hanya 1 baris. Buat enrollment via Eloquent → `total` naik dari 5.000.004 ke 5.000.005, `rows in enrollment_stats = 1` |
| Pelajaran | `upsert(payload, uniqueKeys)` di Laravel: kalau payload tidak memuat field yang ada di `uniqueKeys`, Eloquent tidak bisa menentukan row mana yang di-update dan akan INSERT baru. Selalu sertakan kunci unik di payload, atau pakai `where()->update()` eksplisit. Jangan andalkan `first()` untuk tabel yang bisa punya banyak baris — pin ke id kanonik |
| Log Keyword | `stat card tidak berubah`, `enrollment_stats 25 rows`, `observer insert`, `first() stale`, `whereKey(1)` |
| Deploy | Lokal — worker dev. Data `enrollment_stats` perlu di-cleanup sekali (hapus id>1, recompute id=1) |

---

### Fix #2 — Export terfilter "0 baris" + worker mati: memori & worker tidak jalan

| Tanggal | 2026-09-20 |
| File | `app/Jobs/ExportEnrollmentsJob.php` |
| Masalah | (a) UI export stuck di "Memproses... 0 baris" selamanya setelah worker mati. (b) Export dengan search NIM tak-ada (mis. `ts`) malah memuntahkan **5 juta baris** (600MB) alih-alih 0 baris |
| Akar | (a) `queue:work` sebelumnya crash "Memory limit exceeded" saat export 5M baris — job memuat SEMUA 7.002 student + course ke 2 map in-memory di start, lalu stream 5M baris → melebihi `memory_limit 512M`. Worker mati → job baru tak pernah diproses. (b) Di `applySearch()`, saat search box aktif tapi resolv 0 id, closure `whereIn/whereOrIn` tak menambah kondisi apa pun → query jadi tanpa filter → export seluruh tabel |
| Fix | (1) Lookup student/course diubah dari 2 map global jadi **per-chunk** (`whereIn` hanya id yang ada di chunk tsb) — cap memori di ukuran chunk, bukan tabel penuh. (2) `applySearch()` sekarang: jika ada search aktif tapi 0 hasil, pakai `whereIn('enrollments.id',[0])` → 0 baris. (3) Worker di-start ulang dengan `--memory=1024` |
| Verifikasi | `search_nim=20000001` → **2s, 810/810 baris, MATCH [OK]** vs tabel. `status=APPROVED&semester=GANJIL` → **18s, 833.071 baris, MATCH [OK]**. `search_nim=zzz` (tak ada) → **2s, 0 baris** (sebelumnya 58s, 5.000.003 baris/600MB). Worker tetap hidup setelah export 5M baris (tidak crash lagi) |
| Pelajaran | Worker queue yang mati diam-diam bikin SEMUA export stuck di "processing 0 baris" — cek `queue:work` hidup & log "Memory limit exceeded". Jangan load tabel penuh ke memori untuk export besar; chunk lookup per-batch. Search yang resolv 0 hasil harus eksplisit 0, bukan "abaikan filter" |
| Log Keyword | `export stuck 0 baris`, `Worker STOPPED Memory limit exceeded`, `Memory limit`, `search_nim ts`, `5.000.003`, `applySearch noMatches`, `--memory=1024` |
| Deploy | Lokal — `artisan serve` + `queue:work --memory=1024`. **Ingatkan user: dev harus menjalankan keduanya** |

### Fix #1 — Export KRS progress stuck di 0% / 0 baris: count query tidak ter-cache

| Tanggal | 2026-09-20 |
| File | `app/Jobs/ExportEnrollmentsJob.php` |
| Masalah | UI export menampilkan `0% / 0 baris` lama, karena `buildFilteredQuery()->count()` full scan 5M baris via JOIN tanpa cache |
| Akar | `KrsController::index()` sudah cache count per-filter 60s (`Cache::remember`) + `EnrollmentStats` utk unfiltered — job export tidak pakai mekanisme sama → scan penuh kedua |
| Fix | (1) `estimatedTotal` pakai `Cache::remember` keyed JSON params, TTL 60s. (2) Unfiltered pakai `EnrollmentStats::first()->total`. (3) `total_rows` ditulis ke DB **sebelum** loop streaming |
| Verifikasi | Filtered export selesai **16s** (dari ~141s), progress muncul di **7s**, `833.071/833.071 MATCH [OK]`. Unfiltered 5M baris: **52s**, progress di **26s** |
| Pelajaran | Jika controller sudah cache suatu query, job yang menjalankan query identik harus pakai cache sama. `total_rows` harus di-set sebelum loop karena UI poll dari DB |
| Log Keyword | `export progress stuck`, `0%/0 baris`, `total_rows`, `estimatedTotal`, `buildFilteredQuery count` |
| Deploy | Lokal |
