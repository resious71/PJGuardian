"""악의적·비정상 zip을 samples/zips_bad/ 에 만든다 (P05).

Python 표준 라이브러리(zipfile)만 사용한다. 각 zip의 시험 목적은 zip 주석(archive comment)에도 적는다.
zipfile은 Windows에서 ZipInfo 이름의 역슬래시를 '/'로 바꾸므로, 이름을 그대로 넣어야 하는 항목은
ZipInfo 생성 뒤 filename 속성을 직접 바꾼다.

사용법: python tools/make_bad_zips.py
"""

from __future__ import annotations

import struct
import sys
import unicodedata
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "samples" / "zips_bad"
SAMPLE_ZIP = ROOT / "samples" / "zips" / "python_sample.zip"
FIXED_TIME = (2026, 1, 1, 0, 0, 0)


def info(name: str, *, raw: bool = False) -> zipfile.ZipInfo:
    zi = zipfile.ZipInfo("placeholder", date_time=FIXED_TIME)
    zi.filename = name if raw else name.replace("\\", "/")
    zi.compress_type = zipfile.ZIP_DEFLATED
    zi.external_attr = 0o100644 << 16
    zi.create_system = 3  # Unix
    return zi


class RawNameInfo(zipfile.ZipInfo):
    """이름 바이트와 UTF-8 플래그를 직접 정하는 ZipInfo (CP949 이름, 플래그 없는 UTF-8 이름용)."""

    def __init__(self, raw_name: bytes, utf8_flag: bool) -> None:
        super().__init__("placeholder", date_time=FIXED_TIME)
        self.filename = raw_name.decode("latin-1")
        self._raw_name = raw_name
        self._utf8_flag = utf8_flag
        self.compress_type = zipfile.ZIP_DEFLATED
        self.external_attr = 0o100644 << 16

    def _encodeFilenameFlags(self):  # noqa: N802 (zipfile 내부 메서드 이름)
        flags = self.flag_bits | 0x800 if self._utf8_flag else self.flag_bits & ~0x800
        return self._raw_name, flags


def write(name: str, comment: str, entries: list[tuple[zipfile.ZipInfo, bytes]]) -> Path:
    path = OUT / name
    with zipfile.ZipFile(path, "w") as zf:
        zf.comment = comment.encode("utf-8")
        for zi, data in entries:
            zf.writestr(zi, data)
    return path


def ok_file(name: str = "src/app.py", data: bytes = b"x = 1\n") -> tuple[zipfile.ZipInfo, bytes]:
    return info(name), data


def build_all() -> None:
    write("zip_slip.zip", "zip slip: 상위 디렉토리로 빠져나가는 항목(../../evil.txt, a/../../evil.txt)", [
        ok_file(),
        (info("../../evil.txt"), b"evil\n"),
        (info("a/../../evil.txt"), b"evil\n"),
    ])

    write("absolute_path.zip", "절대 경로: /etc/evil.txt, C:/evil.txt, \\\\server\\share\\evil.txt", [
        ok_file(),
        (info("/etc/evil.txt"), b"evil\n"),
        (info("C:/evil.txt"), b"evil\n"),
        (info("\\\\server\\share\\evil.txt", raw=True), b"evil\n"),
    ])

    write("backslash_path.zip", "역슬래시 경로: ..\\..\\evil.txt", [
        ok_file(),
        (info("..\\..\\evil.txt", raw=True), b"evil\n"),
    ])

    link = info("src/link_to_passwd")
    link.external_attr = (0o120777 << 16)  # S_IFLNK
    write("symlink.zip", "심볼릭 링크: 외부 속성에 S_IFLNK, 내용은 링크 대상 경로", [
        ok_file(),
        (link, b"../../../../etc/passwd"),
    ])

    inner = OUT / "_inner.tmp"
    with zipfile.ZipFile(inner, "w") as zf:
        zf.writestr(info("inner.py"), b"y = 2\n")
    write("nested.zip", "중첩 zip: 안에 .zip 파일이 들어 있음", [
        ok_file(),
        (info("vendor/inner.zip"), inner.read_bytes()),
    ])
    inner.unlink()

    # 0 바이트 반복 300MB → 압축 후 수백 KB. 메모리를 아끼기 위해 스트리밍으로 쓴다.
    bomb = OUT / "bomb_small.zip"
    with zipfile.ZipFile(bomb, "w") as zf:
        zf.comment = "zip bomb(작은 버전): 압축 후 수백 KB, 해제하면 300MB(0 바이트 반복). 시험에서는 한도를 낮춰 확인".encode()
        zf.writestr(info("src/app.py"), b"x = 1\n")
        zi = info("data/zeros.bin")
        with zf.open(zi, "w") as fh:
            block = b"\0" * (1024 * 1024)
            for _ in range(300):
                fh.write(block)

    write("many_files.zip", "파일 수 폭탄: 빈 파일 5,000개(시험에서는 파일 수 한도를 낮춰 확인)",
          [(info(f"many/f{i:05d}.txt"), b"") for i in range(5000)])

    build_lying_size()

    hangul = "한글폴더/모듈_예제.py"
    write_raw("cp949_names.zip", "CP949 이름(UTF-8 플래그 없음): Windows 압축 도구 형식", [
        (hangul.encode("cp949"), False, "# 한글 파일\nx = 1\n".encode()),
        ("src/정상.py".encode("cp949"), False, b"y = 2\n"),
    ])

    write("utf8_names.zip", "UTF-8 플래그가 있는 한글 파일명", [
        (info("한글폴더/모듈_예제.py"), "# 한글 파일\nx = 1\n".encode()),
        (info("src/정상.py"), b"y = 2\n"),
    ])

    # 플래그 없이 UTF-8 바이트를 쓴 이름(일부 Linux·macOS 도구). 명세 5.1에 따르면 CP949로 해석된다.
    write_raw("utf8_noflag.zip", "UTF-8 바이트 이름이지만 UTF-8 플래그 없음(명세상 CP949로 해석 → 실패 또는 깨짐 확인용)", [
        ("한글폴더/모듈_예제.py".encode("utf-8"), False, b"x = 1\n"),
    ])

    write("excluded_dirs.zip", "제외 대상 폴더(node_modules, .git, __pycache__, .venv, venv, dist, build)와 정상 파일", [
        ok_file("src/app.py"),
        ok_file("README.md", b"# readme\n"),
        ok_file("builder/tool.py", b"z = 3\n"),       # 'build'와 이름만 비슷: 제외되면 안 됨
        ok_file("src/dist.py", b"w = 4\n"),           # 파일 이름이 dist.py: 제외되면 안 됨
        ok_file("node_modules/pkg/index.js", b"module.exports = 1;\n"),
        ok_file("src/node_modules/inner/x.js", b"1;\n"),
        ok_file(".git/config", b"[core]\n"),
        ok_file("app/__pycache__/m.cpython-313.pyc", b"\0\0"),
        ok_file(".venv/lib/site.py", b"s = 1\n"),
        ok_file("venv/bin/activate", b"# sh\n"),
        ok_file("dist/bundle.js", b"1;\n"),
        ok_file("build/out.js", b"1;\n"),
    ])

    long_name = "a" * 100 + "/" + "b" * 100 + "/" + "c" * 80 + ".py"
    write("long_path.zip", f"경로 길이 {len(long_name)}자(260자 초과) 항목", [
        ok_file(),
        (info(long_name), b"x = 1\n"),
    ])

    write("reserved_names.zip", "Windows 예약 이름(CON, NUL, aux.txt, com1.py)과 끝 점·공백, 금지 문자(: ? *)", [
        ok_file(),
        (info("CON"), b"1\n"),
        (info("src/NUL"), b"1\n"),
        (info("src/aux.txt"), b"1\n"),
        (info("lib/com1.py"), b"1\n"),
        (info("docs/trailing."), b"1\n"),
        (info("docs/space "), b"1\n"),
        (info("src/a:stream.py"), b"1\n"),
        (info("src/what?.py"), b"1\n"),
    ])

    nfc = "src/한글.py"
    nfd = unicodedata.normalize("NFD", nfc)
    write("case_conflict.zip", "대소문자·유니코드 정규화만 다른 이름(README.md/readme.md, Src/·src/, NFC·NFD)", [
        ok_file("README.md", b"1\n"),
        ok_file("readme.md", b"2\n"),
        ok_file("Lib/a.py", b"1\n"),
        ok_file("lib/b.py", b"2\n"),
        (info(nfc), b"1\n"),
        (info(nfd), b"2\n"),
    ])

    data = SAMPLE_ZIP.read_bytes()
    (OUT / "corrupt.zip").write_bytes(data[: len(data) // 3] + data[len(data) * 2 // 3:])

    (OUT / "not_a_zip.zip").write_text("이 파일은 zip이 아니라 확장자만 .zip인 텍스트 파일이다.\n", encoding="utf-8")


def write_raw(name: str, comment: str, entries: list[tuple[bytes, bool, bytes]]) -> None:
    with zipfile.ZipFile(OUT / name, "w") as zf:
        zf.comment = comment.encode("utf-8")
        for raw, flag, data in entries:
            zf.writestr(RawNameInfo(raw, flag), data)


def build_lying_size() -> None:
    """중앙 디렉토리와 로컬 헤더의 '압축 해제 크기'를 실제보다 작게 위조한다.

    앞쪽에 정상 파일 몇 개를 두어, 해제 도중 막혔을 때 이미 쓴 파일이 지워지는지도 확인한다.
    """
    path = OUT / "lying_size.zip"
    actual = 2 * 1024 * 1024
    claimed = 100
    with zipfile.ZipFile(path, "w") as zf:
        zf.comment = f"크기 위조: 마지막 항목의 선언 크기 {claimed} bytes, 실제 {actual} bytes(0 바이트)".encode()
        for i in range(3):
            zf.writestr(info(f"src/ok{i}.py"), b"x = 1\n")
        zf.writestr(info("data/lying.bin"), b"\0" * actual)
    raw = bytearray(path.read_bytes())
    with zipfile.ZipFile(path) as zf:
        target = zf.getinfo("data/lying.bin")
        local = target.header_offset
    # 로컬 파일 헤더: 서명 PK\3\4, 압축 해제 크기는 오프셋 22
    assert raw[local:local + 4] == b"PK\x03\x04"
    struct.pack_into("<I", raw, local + 22, claimed)
    # 중앙 디렉토리 헤더: 서명 PK\1\2, 압축 해제 크기는 오프셋 24, 파일 이름은 46부터
    name = b"data/lying.bin"
    pos = 0
    patched = False
    while (pos := raw.find(b"PK\x01\x02", pos)) != -1:
        name_len = struct.unpack_from("<H", raw, pos + 28)[0]
        if bytes(raw[pos + 46:pos + 46 + name_len]) == name:
            struct.pack_into("<I", raw, pos + 24, claimed)
            patched = True
        pos += 4
    assert patched, "중앙 디렉토리 항목을 찾지 못함"
    path.write_bytes(bytes(raw))


def main() -> int:
    OUT.mkdir(parents=True, exist_ok=True)
    build_all()
    for p in sorted(OUT.glob("*.zip")):
        print(f"{p.name:<22} {p.stat().st_size:>10,} bytes")
    return 0


if __name__ == "__main__":
    sys.exit(main())
