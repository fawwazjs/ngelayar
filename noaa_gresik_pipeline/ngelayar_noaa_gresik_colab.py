"""
NGELAYAR NOAA CoastWatch ERDDAP pipeline for Gresik waters.

Run in Google Colab:
1. Upload this file or paste the cells into a notebook.
2. Run all cells. Outputs are written to ./ngelayar_outputs.

The script downloads NOAA ERDDAP NetCDF subsets, prepares a tabular ML-ready
dataset, saves CSV/NetCDF outputs, and creates quality-report visualizations.
"""

# If running in Google Colab, uncomment this installation block.
# !pip -q install xarray netCDF4 pandas numpy matplotlib geopandas shapely pyogrio requests

from __future__ import annotations

import json
import math
import shutil
import subprocess
import textwrap
from pathlib import Path
from urllib.parse import quote

import geopandas as gpd
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
import requests
import xarray as xr
from matplotlib.colors import LogNorm
from shapely.geometry import box


# ---------------------------------------------------------------------------
# 1. Study area: Gresik Regency coastal waters, East Java, Indonesia
# ---------------------------------------------------------------------------
# This bbox includes the mainland Gresik coast, Gresik nearshore fishing waters,
# Madura Strait approaches, and Bawean Island waters, while avoiding a download
# of all East Java waters.
BBOX = {
    "min_lat": -7.35,
    "max_lat": -5.60,
    "min_lon": 112.35,
    "max_lon": 113.10,
}

TIME_START = "2024-01-01T00:00:00Z"
TIME_END = "2025-12-31T23:59:59Z"
CHL_TIME_START = "2024-01-01T12:00:00Z"
CHL_TIME_END = "2025-12-31T12:00:00Z"

OUTPUT_DIR = Path("ngelayar_outputs")
RAW_DIR = OUTPUT_DIR / "raw"
FIG_DIR = OUTPUT_DIR / "figures"
for directory in (OUTPUT_DIR, RAW_DIR, FIG_DIR):
    directory.mkdir(parents=True, exist_ok=True)

SST_DATASET_ID = "jplMURSST41mday"
CHL_DATASET_ID = "noaacwN20VIIRSchlaDaily"

SST_ERDDAP = "https://erddap.marine.usf.edu/erddap"
CHL_ERDDAP = "https://coastwatch.noaa.gov/erddap"

SST_INFO_URL = f"{SST_ERDDAP}/info/{SST_DATASET_ID}/index.html"
CHL_INFO_URL = f"{CHL_ERDDAP}/info/{CHL_DATASET_ID}/index.html"


def erddap_grid_url(base: str, dataset_id: str, extension: str, query: str) -> str:
    """Build an encoded ERDDAP griddap URL from a validated base/dataset."""
    safe = "(),:[]"
    return f"{base}/griddap/{dataset_id}.{extension}?{quote(query, safe=safe)}"


SST_QUERY = (
    "sst"
    f"[({TIME_START}):1:({TIME_END})]"
    f"[({BBOX['min_lat']}):1:({BBOX['max_lat']})]"
    f"[({BBOX['min_lon']}):1:({BBOX['max_lon']})]"
)

# NOAA-20 VIIRS chlorophyll has dimensions: time, altitude, latitude, longitude.
# Its latitude axis is descending in the ERDDAP metadata, so the latitude slice
# must be max_lat -> min_lat for the direct download.
CHL_QUERY = (
    "chlor_a"
    f"[({CHL_TIME_START}):1:({CHL_TIME_END})]"
    "[(0.0):1:(0.0)]"
    f"[({BBOX['max_lat']}):1:({BBOX['min_lat']})]"
    f"[({BBOX['min_lon']}):1:({BBOX['max_lon']})]"
)

SST_DOWNLOAD_URL = erddap_grid_url(SST_ERDDAP, SST_DATASET_ID, "nc", SST_QUERY)
CHL_DOWNLOAD_URL = erddap_grid_url(CHL_ERDDAP, CHL_DATASET_ID, "nc", CHL_QUERY)

SST_NC = RAW_DIR / "noaa_jplMURSST41mday_gresik_2024_2025.nc"
CHL_NC = RAW_DIR / "noaa_noaacwN20VIIRSchlaDaily_gresik_2024_2025.nc"
CHL_MONTHLY_DIR = RAW_DIR / "chlorophyll_monthly_chunks"
FINAL_CSV = OUTPUT_DIR / "ngelayar_gresik_oceanographic_2025.csv"
FINAL_NC = OUTPUT_DIR / "ngelayar_gresik_oceanographic_2025.nc"
REPORT_MD = OUTPUT_DIR / "ngelayar_gresik_data_quality_report.md"
CHL_MONTHLY_DIR.mkdir(parents=True, exist_ok=True)


def print_study_area() -> None:
    print("Bounding box perairan Gresik yang digunakan")
    print(json.dumps(BBOX, indent=2))
    print()
    print("Dataset NOAA ERDDAP yang digunakan")
    print(f"SST info      : {SST_INFO_URL}")
    print(f"SST download  : {SST_DOWNLOAD_URL}")
    print(f"Chl-a info    : {CHL_INFO_URL}")
    print(f"Chl-a bulk download reference: {CHL_DOWNLOAD_URL}")
    first_label, first_start, first_end = monthly_time_windows()[0]
    last_label, last_start, last_end = monthly_time_windows()[-1]
    first_url = erddap_grid_url(CHL_ERDDAP, CHL_DATASET_ID, "nc", chlorophyll_query(first_start, first_end))
    last_url = erddap_grid_url(CHL_ERDDAP, CHL_DATASET_ID, "nc", chlorophyll_query(last_start, last_end))
    print("Chl-a download diproses per bulan untuk menghindari ERDDAP 502 pada request besar.")
    print(f"Chl-a monthly example {first_label}: {first_url}")
    print(f"Chl-a monthly example {last_label}: {last_url}")
    print()


def check_url(url: str) -> None:
    response = requests.get(url, timeout=60)
    response.raise_for_status()


def download_file(url: str, destination: Path, overwrite: bool = False) -> Path:
    if destination.exists() and not overwrite and destination.stat().st_size > 0:
        print(f"Skip existing file: {destination}")
        return destination

    print(f"Downloading {destination.name}")
    if shutil.which("curl"):
        subprocess.run(
            ["curl", "-g", "-L", "--fail", "--max-time", "900", "-o", str(destination), url],
            check=True,
        )
    else:
        print("curl tidak ditemukan; mencoba requests. Beberapa server ERDDAP menolak URL bracket-encoded.")
        with requests.get(url, stream=True, timeout=300) as response:
            response.raise_for_status()
            with destination.open("wb") as file:
                for chunk in response.iter_content(chunk_size=1024 * 1024):
                    if chunk:
                        file.write(chunk)
    print(f"Saved {destination} ({destination.stat().st_size / 1_000_000:.2f} MB)")
    return destination


def chlorophyll_query(start: str, end: str) -> str:
    return (
        "chlor_a"
        f"[({start}):1:({end})]"
        "[(0.0):1:(0.0)]"
        f"[({BBOX['max_lat']}):1:({BBOX['min_lat']})]"
        f"[({BBOX['min_lon']}):1:({BBOX['max_lon']})]"
    )


def monthly_time_windows() -> list[tuple[str, str, str]]:
    starts = pd.date_range("2024-01-01", "2025-12-01", freq="MS", tz="UTC")
    windows = []
    for start in starts:
        end = start + pd.offsets.MonthEnd(0)
        label = start.strftime("%Y_%m")
        # NOAA-20 VIIRS daily files use noon-centered daily timestamps.
        windows.append((label, start.strftime("%Y-%m-%dT12:00:00Z"), end.strftime("%Y-%m-%dT12:00:00Z")))
    return windows


def download_chlorophyll_monthly(overwrite: bool = False) -> list[Path]:
    paths = []
    print("\nDownloading Chlorophyll-a as monthly ERDDAP chunks")
    for label, start, end in monthly_time_windows():
        url = erddap_grid_url(CHL_ERDDAP, CHL_DATASET_ID, "nc", chlorophyll_query(start, end))
        path = CHL_MONTHLY_DIR / f"noaa_noaacwN20VIIRSchlaDaily_gresik_{label}.nc"
        download_file(url, path, overwrite=overwrite)
        paths.append(path)
    return paths


def open_and_standardize_sst(path: Path) -> xr.Dataset:
    ds = xr.open_dataset(path)
    print("\nStruktur dataset SST:")
    print(ds)
    if "sst" not in ds.data_vars:
        raise KeyError(f"Variabel SST tidak ditemukan. Variabel tersedia: {list(ds.data_vars)}")

    sst = ds["sst"]
    units = str(sst.attrs.get("units", "")).lower()
    if units in {"k", "kelvin", "degree_k", "degrees_k"}:
        sst = sst - 273.15
        sst.attrs["units"] = "degree_C"
    elif "c" in units:
        sst.attrs["units"] = ds["sst"].attrs.get("units", "degree_C")
    else:
        print(f"PERINGATAN: satuan SST tidak eksplisit Celsius/Kelvin: {units}")

    out = sst.to_dataset(name="sst").sortby("latitude").sortby("longitude")
    return out.sel(
        time=slice("2024-01-01", "2025-12-31"),
        latitude=slice(BBOX["min_lat"], BBOX["max_lat"]),
        longitude=slice(BBOX["min_lon"], BBOX["max_lon"]),
    )


def standardize_chl_dataset(ds: xr.Dataset) -> xr.Dataset:
    print("\nStruktur dataset Chlorophyll-a:")
    print(ds)
    if "chlor_a" not in ds.data_vars:
        raise KeyError(f"Variabel chlor_a tidak ditemukan. Variabel tersedia: {list(ds.data_vars)}")

    if "altitude" in ds.dims or "altitude" in ds.coords:
        ds = ds.squeeze("altitude", drop=True)

    out = ds[["chlor_a"]].sortby("latitude").sortby("longitude")
    return out.sel(
        time=slice("2024-01-01", "2025-12-31"),
        latitude=slice(BBOX["min_lat"], BBOX["max_lat"]),
        longitude=slice(BBOX["min_lon"], BBOX["max_lon"]),
    )


def open_and_standardize_chl(paths: list[Path]) -> xr.Dataset:
    pieces = []
    for path in paths:
        pieces.append(xr.open_dataset(path))
    ds = xr.concat(pieces, dim="time").sortby("time")
    ds = ds.isel(time=~ds.indexes["time"].duplicated())
    return standardize_chl_dataset(ds)


def coord_resolution(values: xr.DataArray) -> float:
    arr = np.asarray(values.values, dtype=float)
    diffs = np.diff(np.sort(arr))
    diffs = diffs[np.isfinite(diffs) & (diffs > 0)]
    return float(np.median(diffs)) if len(diffs) else math.nan


def align_to_chlorophyll_grid(sst_ds: xr.Dataset, chl_ds: xr.Dataset) -> xr.Dataset:
    print("\nPenyelarasan grid dan waktu")
    print(f"Resolusi SST lat/lon      : {coord_resolution(sst_ds.latitude):.5f} / {coord_resolution(sst_ds.longitude):.5f} derajat")
    print(f"Resolusi Chl-a lat/lon    : {coord_resolution(chl_ds.latitude):.5f} / {coord_resolution(chl_ds.longitude):.5f} derajat")
    print(f"Jumlah waktu SST          : {sst_ds.sizes.get('time', 0)}")
    print(f"Jumlah waktu Chl-a        : {chl_ds.sizes.get('time', 0)}")

    # The target is a daily tabular ML dataset. Chlorophyll-a is the coarser
    # daily grid, so SST is interpolated to chlorophyll latitude/longitude/time.
    # Resulting SST values are daily estimates derived from monthly means.
    sst_on_chl = sst_ds["sst"].interp(
        time=chl_ds.time,
        latitude=chl_ds.latitude,
        longitude=chl_ds.longitude,
        method="linear",
    )
    return xr.merge([sst_on_chl.to_dataset(name="sst"), chl_ds[["chlor_a"]]], compat="override")


def dataset_to_dataframe(ds: xr.Dataset) -> pd.DataFrame:
    df = ds.to_dataframe().reset_index()
    keep_cols = ["time", "latitude", "longitude", "sst", "chlor_a"]
    df = df[keep_cols].sort_values(["time", "latitude", "longitude"]).reset_index(drop=True)
    return df


def quality_checks(df: pd.DataFrame) -> dict:
    total_rows = len(df)
    missing_count = df[["sst", "chlor_a"]].isna().sum()
    missing_pct = (missing_count / total_rows * 100).round(3)
    duplicate_count = int(df.duplicated(["time", "latitude", "longitude"]).sum())

    invalid_sst = df["sst"].notna() & ((df["sst"] < -2.0) | (df["sst"] > 40.0))
    invalid_chl = df["chlor_a"].notna() & ((df["chlor_a"] <= 0.0) | (df["chlor_a"] > 1000.0))

    extreme_chl_valid = df["chlor_a"].notna() & (df["chlor_a"] > 30.0) & (df["chlor_a"] <= 1000.0)
    extreme_sst_valid = df["sst"].notna() & (df["sst"] >= 35.0) & (df["sst"] <= 40.0)

    missing_locations = (
        df.assign(any_missing=df[["sst", "chlor_a"]].isna().any(axis=1))
        .query("any_missing")
        .groupby(["latitude", "longitude"], as_index=False)
        .size()
        .sort_values("size", ascending=False)
        .head(20)
    )

    report = {
        "total_rows": int(total_rows),
        "missing_count": missing_count.to_dict(),
        "missing_pct": missing_pct.to_dict(),
        "duplicate_count": duplicate_count,
        "invalid_sst_count": int(invalid_sst.sum()),
        "invalid_chl_count": int(invalid_chl.sum()),
        "extreme_valid_sst_count": int(extreme_sst_valid.sum()),
        "extreme_valid_chl_count": int(extreme_chl_valid.sum()),
        "missing_locations_top20": missing_locations,
    }

    print("\nMissing value")
    print(pd.DataFrame({"missing_count": missing_count, "missing_pct": missing_pct}))
    print("\nDuplikasi time + latitude + longitude:", duplicate_count)
    print("\nNilai invalid")
    print(f"SST invalid (< -2 atau > 40 degC): {report['invalid_sst_count']}")
    print(f"Chl-a invalid (<= 0 atau > 1000 mg/m^3): {report['invalid_chl_count']}")
    print("\nExtreme but valid, tidak dihapus otomatis")
    print(f"SST 35-40 degC: {report['extreme_valid_sst_count']}")
    print(f"Chl-a 30-1000 mg/m^3: {report['extreme_valid_chl_count']}")
    print("\nTop lokasi missing value")
    print(missing_locations)
    return report


def descriptive_stats(df: pd.DataFrame) -> pd.DataFrame:
    stats = df[["sst", "chlor_a"]].agg(["min", "max", "mean", "median", "std"]).T
    stats = stats.rename(
        columns={
            "min": "minimum",
            "max": "maksimum",
            "mean": "mean",
            "median": "median",
            "std": "standard_deviation",
        }
    )
    print("\nStatistik deskriptif")
    print(stats)
    return stats


def load_coastline() -> gpd.GeoDataFrame:
    coastline_zip = RAW_DIR / "ne_10m_coastline.zip"
    coastline_url = "https://naturalearth.s3.amazonaws.com/10m_physical/ne_10m_coastline.zip"
    if not coastline_zip.exists():
        download_file(coastline_url, coastline_zip)
    coastline = gpd.read_file(f"zip://{coastline_zip.resolve()}")
    bbox_poly = gpd.GeoDataFrame(geometry=[box(BBOX["min_lon"], BBOX["min_lat"], BBOX["max_lon"], BBOX["max_lat"])], crs="EPSG:4326")
    return gpd.clip(coastline.to_crs("EPSG:4326"), bbox_poly)


def plot_map(ds: xr.Dataset, variable: str, title: str, output: Path, log_scale: bool = False) -> None:
    coastline = load_coastline()
    bbox_gdf = gpd.GeoDataFrame(
        geometry=[box(BBOX["min_lon"], BBOX["min_lat"], BBOX["max_lon"], BBOX["max_lat"])],
        crs="EPSG:4326",
    )
    da = ds[variable].mean("time", skipna=True)

    fig, ax = plt.subplots(figsize=(8, 8))
    values = da.values
    lon = da.longitude.values
    lat = da.latitude.values

    if log_scale:
        positive = values[np.isfinite(values) & (values > 0)]
        norm = LogNorm(vmin=max(np.nanpercentile(positive, 2), 0.001), vmax=np.nanpercentile(positive, 98)) if positive.size else None
        mesh = ax.pcolormesh(lon, lat, values, shading="auto", cmap="viridis", norm=norm)
    else:
        mesh = ax.pcolormesh(lon, lat, values, shading="auto", cmap="turbo")

    coastline.plot(ax=ax, color="black", linewidth=0.8)
    bbox_gdf.boundary.plot(ax=ax, color="red", linewidth=1.4)
    ax.set_xlim(BBOX["min_lon"], BBOX["max_lon"])
    ax.set_ylim(BBOX["min_lat"], BBOX["max_lat"])
    ax.set_xlabel("Longitude")
    ax.set_ylabel("Latitude")
    ax.set_title(title)
    ax.grid(True, linewidth=0.3, alpha=0.5)
    cbar = fig.colorbar(mesh, ax=ax, shrink=0.75)
    cbar.set_label(variable)
    fig.tight_layout()
    fig.savefig(output, dpi=200)
    plt.close(fig)
    print(f"Saved figure: {output}")


def plot_histograms(df: pd.DataFrame) -> None:
    fig, axes = plt.subplots(1, 2, figsize=(12, 4))
    df["sst"].dropna().hist(ax=axes[0], bins=40, color="#2a9d8f")
    axes[0].set_title("Distribusi SST")
    axes[0].set_xlabel("SST (degC)")
    axes[0].set_ylabel("Frekuensi")

    chl = df["chlor_a"].dropna()
    chl = chl[chl > 0]
    axes[1].hist(chl, bins=40, color="#577590")
    axes[1].set_xscale("log")
    axes[1].set_title("Distribusi Chlorophyll-a")
    axes[1].set_xlabel("Chlorophyll-a (mg/m^3, log scale)")
    axes[1].set_ylabel("Frekuensi")

    fig.tight_layout()
    output = FIG_DIR / "histogram_sst_chlor_a.png"
    fig.savefig(output, dpi=200)
    plt.close(fig)
    print(f"Saved figure: {output}")


def write_report(df: pd.DataFrame, stats: pd.DataFrame, report: dict, sst_ds: xr.Dataset, chl_ds: xr.Dataset) -> None:
    n_geo = df[["latitude", "longitude"]].drop_duplicates().shape[0]
    n_days = pd.to_datetime(df["time"]).dt.normalize().nunique()
    missing_total = int(df[["sst", "chlor_a"]].isna().sum().sum())
    missing_pct_total = missing_total / (len(df) * 2) * 100 if len(df) else math.nan

    content = f"""
    # NGELAYAR Gresik Oceanographic Data Quality Report

    Dataset:
    - NOAA CoastWatch / ERDDAP SST: `{SST_DATASET_ID}`
    - NOAA CoastWatch / ERDDAP Chlorophyll-a: `{CHL_DATASET_ID}`

    Wilayah:
    - Perairan Kabupaten Gresik, Jawa Timur, termasuk pesisir Gresik daratan dan perairan sekitar Bawean.

    Bounding Box:
    - minimum latitude: {BBOX['min_lat']}
    - maksimum latitude: {BBOX['max_lat']}
    - minimum longitude: {BBOX['min_lon']}
    - maksimum longitude: {BBOX['max_lon']}

    Periode:
    - 1 Januari 2024 - 31 Desember 2025

    Jumlah observasi:
    - {len(df):,}

    Jumlah titik geografis:
    - {n_geo:,}

    Jumlah hari:
    - {n_days:,}

    Resolusi spasial SST:
    - latitude {coord_resolution(sst_ds.latitude):.5f} derajat; longitude {coord_resolution(sst_ds.longitude):.5f} derajat

    Resolusi spasial Chlorophyll-a:
    - latitude {coord_resolution(chl_ds.latitude):.5f} derajat; longitude {coord_resolution(chl_ds.longitude):.5f} derajat

    Jumlah missing value:
    - total sel variabel SST + Chl-a: {missing_total:,}
    - SST: {report['missing_count']['sst']:,}
    - Chlorophyll-a: {report['missing_count']['chlor_a']:,}

    Persentase missing value:
    - total: {missing_pct_total:.3f}%
    - SST: {report['missing_pct']['sst']:.3f}%
    - Chlorophyll-a: {report['missing_pct']['chlor_a']:.3f}%

    Duplikasi:
    - duplicate time + latitude + longitude: {report['duplicate_count']:,}

    Nilai invalid:
    - SST invalid (< -2 atau > 40 degC): {report['invalid_sst_count']:,}
    - Chlorophyll-a invalid (<= 0 atau > 1000 mg/m^3): {report['invalid_chl_count']:,}

    Catatan preprocessing:
    - SST `jplMURSST41mday` adalah data bulanan 0.01 derajat. Satuan metadata adalah `degree_C`, sehingga tidak dikonversi dari Kelvin.
    - Chlorophyll-a `noaacwN20VIIRSchlaDaily` adalah data harian sekitar 0.0375 derajat dengan satuan `mg m^-3`.
    - Dataset final memakai grid/tanggal Chlorophyll-a. SST diinterpolasi linear ke grid dan waktu Chlorophyll-a.
    - Interpolasi SST menghasilkan estimasi harian dari rata-rata bulanan, bukan observasi SST harian independen.
    - Outlier ekstrem yang masih berada dalam rentang valid metadata tidak dihapus otomatis.

    Statistik deskriptif:

    ```text
    {stats.to_string()}
    ```

    20 baris pertama dataset final:

    ```text
    {df.head(20).to_string(index=False)}
    ```
    """
    REPORT_MD.write_text(textwrap.dedent(content).strip() + "\n", encoding="utf-8")
    print(f"Saved report: {REPORT_MD}")


def main() -> None:
    print_study_area()

    print("Memeriksa ketersediaan halaman metadata ERDDAP...")
    check_url(SST_INFO_URL)
    check_url(CHL_INFO_URL)

    download_file(SST_DOWNLOAD_URL, SST_NC)
    chl_paths = download_chlorophyll_monthly()

    sst_ds = open_and_standardize_sst(SST_NC)
    chl_ds = open_and_standardize_chl(chl_paths)
    chl_ds.to_netcdf(CHL_NC)
    print(f"Saved combined Chlorophyll-a NetCDF: {CHL_NC}")
    aligned = align_to_chlorophyll_grid(sst_ds, chl_ds)

    df = dataset_to_dataframe(aligned)
    report = quality_checks(df)
    stats = descriptive_stats(df)

    df.to_csv(FINAL_CSV, index=False)
    aligned.to_netcdf(FINAL_NC)
    print(f"\nSaved final CSV: {FINAL_CSV}")
    print(f"Saved final NetCDF: {FINAL_NC}")

    plot_map(aligned, "sst", "Rata-rata SST Perairan Gresik, 2024-2025", FIG_DIR / "map_sst_gresik_2024_2025.png")
    plot_map(aligned, "chlor_a", "Rata-rata Chlorophyll-a Perairan Gresik, 2024-2025", FIG_DIR / "map_chlor_a_gresik_2024_2025.png", log_scale=True)
    plot_histograms(df)
    write_report(df, stats, report, sst_ds, chl_ds)

    print("\n20 baris pertama dataset final:")
    print(df.head(20))

    print("\nMachine Learning readiness:")
    print(
        textwrap.dedent(
            """
            SST + Chlorophyll-a cukup sebagai baseline prediktor oseanografi untuk eksperimen awal ZPPI,
            tetapi belum cukup untuk menyatakan lokasi ikan. Model tetap membutuhkan ground truth/label,
            misalnya titik hasil tangkapan, CPUE, logbook nelayan, AIS/VMS, atau survei lapangan.

            Feature tambahan yang disarankan: bathymetry/kedalaman, jarak ke pantai, arus permukaan,
            tinggi gelombang, angin, salinitas, front/gradien SST, gradien chlorophyll-a, bulan/musim,
            fase bulan, zona larangan/alat tangkap, dan histori tangkapan.

            Random Forest ideal: satu baris per time-lat-lon dengan kolom numerik/temporal dan label
            target seperti CPUE atau kelas zona potensial.

            CNN/spatial model ideal: tensor time x channel x lat x lon; channel dapat berisi SST,
            chlorophyll-a, bathymetry, arus, angin, dan mask daratan, dengan label raster atau titik
            tangkapan yang dirasterisasi.

            Periode 2024-2025 cukup untuk baseline/prototipe, tetapi pendek untuk model operasional
            yang robust. Rekomendasi historis: minimal 5 tahun, lebih baik 2018-2025 atau 2017-2025
            agar variasi monsun, interannual variability, dan kejadian ekstrem lebih terwakili.
            """
        ).strip()
    )


if __name__ == "__main__":
    main()
