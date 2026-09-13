# NGELAYAR NOAA Gresik Pipeline

## Bounding box

Wilayah: Perairan Kabupaten Gresik, Jawa Timur, termasuk pesisir Gresik daratan, perairan dekat Selat Madura, dan perairan sekitar Bawean.

- minimum latitude: `-7.35`
- maksimum latitude: `-5.60`
- minimum longitude: `112.35`
- maksimum longitude: `113.10`

Bounding box ini sengaja dibatasi pada area relevan Gresik dan tidak mengambil seluruh perairan Jawa Timur.

## Dataset NOAA ERDDAP

SST:

- Dataset ID: `jplMURSST41mday`
- Variabel: `sst`
- Resolusi spasial metadata: `0.01 degrees`
- Satuan metadata: `degree_C`
- Periode metadata: `2002-06-16T00:00:00Z` sampai `2026-07-16T00:00:00Z`
- Info URL: `https://erddap.marine.usf.edu/erddap/info/jplMURSST41mday/index.html`
- Download URL: `https://erddap.marine.usf.edu/erddap/griddap/jplMURSST41mday.nc?sst[(2024-01-01T00:00:00Z):1:(2025-12-31T23:59:59Z)][(-7.35):1:(-5.60)][(112.35):1:(113.10)]`

Chlorophyll-a:

- Dataset ID: `noaacwN20VIIRSchlaDaily`
- Variabel: `chlor_a`
- Resolusi spasial metadata: sekitar `0.0375 degrees`
- Satuan metadata: `mg m^-3`
- Periode metadata: `2021-08-26T12:00:00Z` sampai `2026-06-20T12:00:00Z`
- Info URL: `https://coastwatch.noaa.gov/erddap/info/noaacwN20VIIRSchlaDaily/index.html`
- Bulk download reference: `https://coastwatch.noaa.gov/erddap/griddap/noaacwN20VIIRSchlaDaily.nc?chlor_a[(2024-01-01T12:00:00Z):1:(2025-12-31T12:00:00Z)][(0.0):1:(0.0)][(-5.60):1:(-7.35)][(112.35):1:(113.10)]`

Catatan: NOAA ERDDAP mengembalikan `502 Proxy Error` untuk beberapa request Chlorophyll-a pada saat eksekusi lokal, termasuk slice satu titik `2024-02-01T12:00:00Z`. Script memakai chunk bulanan supaya request besar tidak dilakukan sekaligus, tetapi tetap akan berhenti dan menampilkan error asli NOAA jika upstream server gagal.

## Artefak lokal

Sudah berhasil diunduh:

- `ngelayar_outputs/raw/noaa_jplMURSST41mday_gresik_2024_2025.nc`
- `ngelayar_outputs/raw/chlorophyll_monthly_chunks/noaa_noaacwN20VIIRSchlaDaily_gresik_2024_01.nc`

Belum dapat dibuat secara lokal karena paket ilmiah Python tidak terpasang di container ini dan NOAA mengembalikan `502` untuk Chlorophyll-a setelah Januari 2024:

- `ngelayar_gresik_oceanographic_2025.csv`
- `ngelayar_gresik_oceanographic_2025.nc`
- statistik final
- visualisasi final
- data quality report final

Jalankan `ngelayar_noaa_gresik_colab.py` di Google Colab untuk instalasi dependensi dan pemrosesan lengkap.
