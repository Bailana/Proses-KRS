# Export Strategy — KRS Export System

## Overview

Sistem export CSV untuk data KRS (Kartu Rencana Studi) dengan kemampuan menangani **5+ juta baris** tanpa timeout atau kehabisan memory.

## Arsitektur

```
┌─────────────┐     GET /init      ┌──────────────┐     dispatch     ┌──────────────┐
│   Frontend  │ ──────────────────▶│  KrsController│ ──────────────▶  │ ExportJob    │
│  (Polling)  │                     │  (Queue)     │                  │  (MySQL)     │
└──────┬──────┘                     └──────────────┘                  └──────┬───────┘
       │                                     ▲                               │
       │  GET /status/{token}                │  GET /download/{token}        │
       ▼                                     │                              │
┌──────────────┐                              │                     ┌──────────────┐
│   UI Update  │◀─────────────────────────────┘                     │  CSV File   │
│  (Progress)  │                                                   │  storage/   │
└──────────────┘                                                   │  app/export │
                                                                  └──────────────┘
```

## API Endpoints

### 1. `GET /api/krs/export/init`
Memicu proses export. Mengembalikan `job_id` dan `download_token`.

**Query params:**
- `filters` (optional): JSON string of advanced filters, e.g. `[{"column":"status","operator":"equals","value":"APPROVED"}]`

**Response:**
```json
{
  "job_id": 42,
  "download_token": "a1b2c3...",
  "status": "processing",
  "progress": 0
}
```

### 2. `GET /api/krs/export/status/{token}`
Mempolling status export.

**Response:**
```json
{
  "status": "processing|completed|failed",
  "progress": 45,
  "processed_rows": 2250000,
  "file_size": null
}
```

### 3. `GET /api/krs/export/download/{token}`
Mengunduh file CSV saat export selesai.

**Response:** CSV file binary download.

## Frontend Flow

1. User klik "Export CSV"
2. Frontend call `GET /api/krs/export/init?filters=...`
3. Frontend mulai polling `GET /api/krs/export/status/{token}` setiap 2 detik
4. Tampilkan progress bar (percentage + row count)
5. Saat `status === 'completed'`, download file via blob URL
6. Bersihkan interval polling

## Backend Processing Strategy

### Phase 1: Pre-load Lookup Tables
```php
// Ambil semua student dan course sekali (bukan join berulang)
$studentMap = DB::table('students')->get(['id','nim','name'])->keyBy('id');
$courseMap  = DB::table('courses')->get(['id','code','name'])->keyBy('id');
```
- Query ini cepat karena table kecil (ribuan baris)
- Data disimpan di memory array lookup

### Phase 2: Chunked Batch Processing
```php
$chunkSize = 50000;
while (true) {
    $batch = DB::table('enrollments')
        ->where('id', '>', $lastId)
        ->orderBy('id', 'asc')
        ->limit($chunkSize)
        ->get();
    
    foreach ($batch as $row) {
        $student = $studentMap->get($row->student_id);
        $course  = $courseMap->get($row->course_id);
        fputcsv($stream, [...]);
    }
    $lastId = $batch->last()->id;
}
```

**Keuntungan:**
- Tiap query hanya 50K baris (bukan seluruh 5M sekaligus)
- Memory tetap rendah (~50K records per batch)
- ID-based cursor pagination (tidak pakai OFFSET)
- File ditulis streaming ke disk (tidak akumulasi di memory)

### Phase 3: Progress Tracking
- Setiap 100.000 baris, update progress di database
- Progress = `(processed_rows / total_rows) * 100`

## Performa

| Skenario | Estimasi Waktu | File Size |
|----------|---------------|-----------|
| 5M baris (tanpa filter) | ~2 menit | ~630 MB |
| 500K baris (filter tertentu) | ~20 detik | ~63 MB |
| 50K baris (filter spesifik) | ~2 detik | ~6 MB |

## Database Schema

### `export_jobs` Table
```sql
CREATE TABLE export_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(255) DEFAULT 'krs',
    status VARCHAR(255) DEFAULT 'pending',
    progress INT DEFAULT 0,
    processed_rows INT DEFAULT 0,
    total_rows INT NULL,
    file_size INT NULL,
    file_path VARCHAR(255) NULL,
    download_token VARCHAR(255) UNIQUE,
    filters JSON NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

## Queue Configuration

```env
QUEUE_CONNECTION=database
```

Jalankan worker:
```bash
php artisan queue:work --sleep=3 --tries=1
```

Atau daemon mode:
```bash
php artisan queue:work --daemon
```

## Index Optimizations

Untuk performa export yang optimal:

```sql
-- Primary key sudah ada di enrollments.id
-- Tambahkan index jika filtering sering dipakai:
ALTER TABLE enrollments ADD INDEX idx_status (status);
ALTER TABLE enrollments ADD INDEX idx_student_id (student_id);
ALTER TABLE enrollments ADD INDEX idx_course_id (course_id);
ALTER TABLE enrollments ADD INDEX idx_academic_year (academic_year);
ALTER TABLE enrollments ADD INDEX idx_created_at (created_at);
```

## File Management

File export disimpan di: `storage/app/exports/`

Untuk production, pertimbangkan:
1. **Auto-cleanup**: Hapus file lama setelah X hari
2. **Storage driver**: Gunakan S3/GCS untuk file besar
3. **Rate limiting**: Batasi max concurrent exports per user

## Error Handling

- Job timeout: 3600 detik (1 jam)
- Max attempts: Laravel default (retry otomatis)
- Failed jobs: tabel `failed_jobs` untuk debugging
- Status polling tetap berjalan meski job gagal

## Future Improvements

1. **XLSX Support**: Gunakan `maatwebsite/excel` dengan `ChunkReadFilter`
2. **Background worker**: Supervisor untuk queue worker persistent
3. **WebSocket**: Real-time progress update (tanpa polling)
4. **Scheduled cleanup**: Cron job hapus export lama
5. **Partial export**: Export per academic year/semester untuk performa lebih baik
