"""samples/ 의 샘플 프로젝트를 samples/zips/ 에 zip으로 만든다 (P02).

Python 표준 라이브러리만 사용한다.
- zip 안 파일명은 UTF-8이며, 비ASCII 이름에는 zipfile이 UTF-8 플래그(0x800)를 자동으로 붙인다.
- 경로 구분자는 '/' 이다.
- 항목 순서와 시각을 고정해 실행할 때마다 같은 바이트의 zip을 만든다.

사용법: python tools/make_sample_zips.py
"""

from __future__ import annotations

import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SAMPLES = ROOT / "samples"
OUT = SAMPLES / "zips"

# (zip 파일 이름, 샘플 폴더, zip 안 최상위 폴더 접두사)
TARGETS = [
    ("python_sample.zip", "python_sample", ""),
    ("js_sample.zip", "js_sample", ""),
    ("ts_sample.zip", "ts_sample", ""),
    ("js_with_js_config.zip", "js_with_js_config", ""),
    ("python_syntax_error.zip", "python_syntax_error", ""),
    ("empty_no_target.zip", "empty_no_target", ""),
    # python_sample.zip 과 내용이 같고 모든 항목이 최상위 폴더 하나 아래에 있다 (최상위 폴더 제거 시험용)
    ("python_sample_toplevel.zip", "python_sample", "my_project_v2/"),
]

FIXED_TIME = (2026, 1, 1, 0, 0, 0)


def build(zip_name: str, folder: str, prefix: str) -> int:
    src = SAMPLES / folder
    if not src.is_dir():
        raise SystemExit(f"샘플 폴더가 없습니다: {src}")
    files = sorted(p for p in src.rglob("*") if p.is_file())
    dest = OUT / zip_name
    with zipfile.ZipFile(dest, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for path in files:
            arcname = prefix + path.relative_to(src).as_posix()
            info = zipfile.ZipInfo(arcname, date_time=FIXED_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            zf.writestr(info, path.read_bytes())
    return len(files)


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    for zip_name, folder, prefix in TARGETS:
        count = build(zip_name, folder, prefix)
        size = (OUT / zip_name).stat().st_size
        print(f"{zip_name:<28} 파일 {count:>3}개  {size:>6} bytes")
    return 0


if __name__ == "__main__":
    sys.exit(main())
