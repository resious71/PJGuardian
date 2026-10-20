# Recommended server size and disk capacity (P32B)

This document turns spec 19.1 (recommended server size) and 19.3 (capacity formula) into something operators can use directly. Korean version: [server_sizing.md](server_sizing.md).
In the app, **System admin > Checks > Capacity estimator** calculates with the same formula (a read-only calculation, the same function `public/assets/js/lib/capacity.js`).

> Each number is labeled by its basis. **Spec**: a value set by the requirements spec. **Measured**: measured on the development PC (Windows 11, PHP 8.3, SQLite) (`docs/performance.md`). **Estimate**: not measured — confirm it on the production server (`docs/unverified.md`).

## 1. Recommended server size

| Scale (concurrent users) | CPU | Memory | Disk | Basis |
| --- | --- | --- | --- | --- |
| Baseline (2 concurrent analyses, up to 1,000 users) | **8 cores or more** | **32 GB or more** | SSD, at least the value from section 3 | **Spec** 19.1 (based on 2 concurrent analyses). Per-analysis memory limit 8 GB (`analysis.process_memory_limit`) × 2 + web, DB, and OS |
| Up to 50 concurrent users | 4 cores keep screen p95 under 0.5 s | — | — | **Measured**: 50-user load test on the development PC, p95 under 0.5 s (`docs/performance.md` section 2) |
| 100 concurrent users | 8 cores or more recommended | 32 GB | — | **Measured**: with 100 users the development PC's CPU saturated and dashboard p95 was 4.4 s (above the 3 s target of spec 19.6). The value on an 8-core server is an **estimate** — confirm on the production server with `node tools/loadtest/run.mjs` |

- The CPU saturates first (measured). Memory is dominated by the analysis process limit — lowering `analysis.process_memory_limit` lowers the memory needed, but large projects may then fail with a memory error.
- SSD is recommended because SQLite and snapshots write a lot (estimate — not measured on HDD). Put the backup folder on a **different disk** (the backup card in System monitoring shows whether it is on the same disk).
- On Linux the ruff memory limit (`prlimit`) applies. Windows applies only the time and output limits (spec 19.10).

## 2. Formula

The data disk (`data/` in the install folder) and the backup disk are calculated separately.

```
Analysis workspace = concurrent analyses × (upload zip + extracted size)          ← spec 19.3
Snapshots          = projects × full snapshots kept × upload zip                  ← zip of the analyzed files (at most the upload — the generous side)
Database           = defects × 560 B × 1.2 + projects × Run details kept × 2 KB + audit log retention days × rows per day × 412 B
Logs               = 1 GB (margin)
Data disk          = (analysis workspace + snapshots + database + logs) ÷ disk warning ratio
Backup disk        = backups kept × (database + snapshots)                        ← when backups include snapshots (default)
```

| Value | Default | Basis |
| --- | --- | --- |
| Concurrent analyses | 2 (`analysis.max_concurrent`) | Spec 19.8 |
| Extracted size | 4 × zip | **Estimate** (a common compression ratio for source code zips). Worst case is the limit `extract.max_extracted_size`, 10 GB |
| Full snapshots kept | 5 (`snapshot.full_keep_count`) | Spec 7 |
| One defect | 560 bytes (including indexes) | **Measured** (P08: 109,000 defects = 61.5 MB) |
| History and interval margin | × 1.2 | **Estimate** (larger with many edits and reopenings) |
| Run summary | 2 KB per Run, 15 per project (`runs.detail_keep`) | **Estimate** |
| One audit log row | 412 bytes | **Measured** (P30: 1,000,000 rows = 393 MB) |
| Audit log rows per day | 1,000 | **Estimate** (depends on admin actions and exports — check the actual count on the Audit log screen) |
| Audit log retention | 90 days (`log.audit_retention`) | Spec 19.7 |
| Disk warning ratio | 80% (`disk.warn_percent`) | Spec 19.3 — divided so the server runs below the warning line |

- **Worst-case analysis workspace from the upload limits**: 2 concurrent × (2 GB + 10 GB) = **24 GB**. This is the "minimum for analysis work" value of `php tools/check_env.php` (uploads are checked against the same basis when they start — spec 19.3).

## 3. Examples (formula above, default settings)

| Scale | Input | Analysis workspace | Snapshots | Database | Data disk | Backup disk |
| --- | --- | --- | --- | --- | --- | --- |
| Small | 10 projects × 10,000 defects, 50 MB zip, 7 backups | 0.5 GB | 2.4 GB | 0.1 GB | **5.0 GB** | 17.8 GB |
| Medium | 50 projects × 20,000 defects, 100 MB zip, 14 backups | 1.0 GB | 24.4 GB | 0.7 GB | **33.8 GB** | 351 GB |
| Design scale | 200 projects × 10,000 defects, 100 MB zip, 60 backups (daily, 60 days) | 1.0 GB | 97.7 GB | 1.3 GB | **126 GB** | about 5.9 TB |

- Keeping 60 daily backups that include snapshots, as in the design-scale row, makes the backup disk very large. Snapshots are recreated by analyzing again, so consider lowering the automatic backup retention (`backup.retention_max_count`) or moving old bundles to other storage (to exclude snapshots, use `php tools/backup.php --no-snapshots`).
- Adding the upload-limit workspace (24 GB) to the data disk keeps large uploads from being blocked.

## 4. Checking in production

1. Right after installation, enter the expected scale in **Checks > Capacity estimator** to size the disk.
2. After a few weeks, look at **Checks > Disk usage** (usage by category) and the daily audit log count, replace the estimates (extraction ratio, audit log rows) with the real values, and recalculate.
3. With many concurrent users, run a load test once on the production server as described in `docs/performance.md`.
