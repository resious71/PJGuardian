#!/usr/bin/env bash
# Linux용 git 빌드·배치(X09). git은 공식 Linux 바이너리 배포가 없어 운영 Linux 서버(또는 같은 배포판의 빌드 서버)에서 공식 소스로 빌드한다.
#   - 소스: kernel.org 공식 tarball, sha256을 아래 상수(또는 --sha256)로 대조. gpg가 있으면 sha256sums.asc 서명도 확인(--gpg)
#   - 옮겨도 동작하게(RUNTIME_PREFIX) 빌드해 bin/git/<버전>/linux/{bin,libexec,share} 에 배치하고 버전 폴더의 SHA256SUMS에 linux/ 줄을 넣는다
#   - 시스템에 설치하지 않는다(make install은 임시 DESTDIR로만). 시스템 git을 복사하지 않는다.
#   - bin/에 쓸 수 있는 서버 관리자 계정으로 실행한다(웹·앱 계정에는 bin/ 쓰기 권한을 주지 않는다 — 명세 12장, docs/tool_setup.md 5절)
#
# 사용: tools/build_git_linux.sh [--version=2.56.0] [--sha256=<64자리>] [--bin=<프로젝트>/bin] [--jobs=4] [--gpg] [--force]
# 필요 패키지(예: Debian/Ubuntu): build-essential libcurl4-openssl-dev libssl-dev zlib1g-dev libexpat1-dev gettext 없이 가능, pkg-config
#                     (RHEL/Rocky): gcc make libcurl-devel openssl-devel zlib-devel expat-devel
# 끝나면: php tools/check_bin_vcs.php (Linux에서는 매니페스트·ELF만, 실행 확인은 아래 출력의 --version·임시 저장소 시험)
set -euo pipefail

VERSION="2.56.0"
# https://www.kernel.org/pub/software/scm/git/sha256sums.asc (2026-10-07 확인, git-2.56.0.tar.xz)
SHA256="26c56c296b38c0695b26fa95f475f1d01704d2d38e73465ca30b0b2f5dc789d3"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT/bin"
JOBS="$(nproc 2>/dev/null || echo 2)"
USE_GPG=0
FORCE=0
for a in "$@"; do
  case "$a" in
    --version=*) VERSION="${a#*=}"; SHA256="" ;;
    --sha256=*) SHA256="${a#*=}" ;;
    --bin=*) BIN="${a#*=}" ;;
    --jobs=*) JOBS="${a#*=}" ;;
    --gpg) USE_GPG=1 ;;
    --force) FORCE=1 ;;
    *) echo "알 수 없는 인자: $a" >&2; exit 2 ;;
  esac
done
[[ "$VERSION" =~ ^[0-9]{1,5}\.[0-9]{1,5}\.[0-9]{1,6}$ ]] || { echo "버전 형식 오류(x.y.z): $VERSION" >&2; exit 2; }
[[ "$SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo "--sha256 필요(공식 sha256sums.asc의 git-$VERSION.tar.xz 값)" >&2; exit 2; }
[[ "$(uname -s)" == "Linux" && "$(uname -m)" == "x86_64" ]] || { echo "Linux x86_64에서 실행하세요" >&2; exit 2; }
for c in gcc make curl tar sha256sum; do command -v "$c" >/dev/null || { echo "필요한 명령 없음: $c" >&2; exit 2; }; done
command -v curl-config >/dev/null || { echo "libcurl 개발 패키지 필요(curl-config 없음)" >&2; exit 2; }

DEST="$BIN/git/$VERSION/linux"
if [[ -e "$DEST" && $FORCE -ne 1 ]]; then echo "이미 있음: $DEST (교체하려면 --force)" >&2; exit 2; fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cd "$WORK"
URL="https://www.kernel.org/pub/software/scm/git/git-$VERSION.tar.xz"
echo "== 내려받기: $URL"
curl -fsSL -o "git-$VERSION.tar.xz" "$URL"
echo "$SHA256  git-$VERSION.tar.xz" | sha256sum -c -
if [[ $USE_GPG -eq 1 ]]; then
  # Junio C Hamano 서명 키(kernel.org 문서의 지문과 직접 대조할 것)
  curl -fsSL -o sha256sums.asc https://www.kernel.org/pub/software/scm/git/sha256sums.asc
  gpg --verify sha256sums.asc
  grep -q "^$SHA256  git-$VERSION.tar.xz$" sha256sums.asc || { echo "서명된 목록에 이 sha256이 없음" >&2; exit 3; }
fi
# 압축 해제 전 경로 검사(절대 경로·..·링크 거부)
if tar -tvJf "git-$VERSION.tar.xz" | awk '{print $1, $NF}' | grep -E '^[lh]|[[:space:]]/|(^|/)\.\.(/|$)' ; then
  echo "tarball에 링크·절대 경로·.. 항목이 있음 — 중단" >&2; exit 3
fi
tar --no-same-owner -xJf "git-$VERSION.tar.xz"
cd "git-$VERSION"

echo "== 빌드(RUNTIME_PREFIX: 옮겨도 자기 위치 기준)"
OPTS=(prefix=/ RUNTIME_PREFIX=YesPlease gitexecdir=libexec/git-core NO_GETTEXT=1 NO_TCLTK=1 NO_PERL=1 NO_PYTHON=1
      NO_INSTALL_HARDLINKS=YesPlease INSTALL_SYMLINKS= CFLAGS="-O2")
make -j"$JOBS" "${OPTS[@]}" all >/dev/null
make "${OPTS[@]}" DESTDIR="$WORK/stage" install >/dev/null
rm -rf "$WORK/stage/share/man" "$WORK/stage/share/perl5" "$WORK/stage/share/gitweb" "$WORK/stage/share/gitk" "$WORK/stage/share/git-gui"

echo "== 확인(임시 HOME, 시스템·전역 설정 없이)"
export HOME="$WORK/home" GIT_CONFIG_NOSYSTEM=1 GIT_CEILING_DIRECTORIES="$WORK" PATH=/usr/bin:/bin
mkdir -p "$HOME"
"$WORK/stage/bin/git" --version
"$WORK/stage/bin/git" --exec-path
mkdir "$WORK/r" && cd "$WORK/r"
"$WORK/stage/bin/git" init -q -b main && echo x > a && "$WORK/stage/bin/git" add a
GIT_AUTHOR_NAME=c GIT_AUTHOR_EMAIL=c@example.invalid GIT_COMMITTER_NAME=c GIT_COMMITTER_EMAIL=c@example.invalid "$WORK/stage/bin/git" commit -q -m x
"$WORK/stage/bin/git" ls-remote "$WORK/r" | head -1
"$WORK/stage/bin/git" ls-remote https://127.0.0.1:1/none.git 2>&1 | head -1 || true   # git-remote-https 로드 확인(접속 실패가 정상)
echo "== 의존 라이브러리(대상 서버에 같은 라이브러리가 있어야 한다)"
ldd "$WORK/stage/bin/git" "$WORK/stage/libexec/git-core/git-remote-https" | sort -u
ldd --version | head -1

echo "== 배치: $DEST"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -a "$WORK/stage/." "$DEST/"
cd "$BIN/git/$VERSION"
SUMS="SHA256SUMS"
touch "$SUMS"
grep -v ' \*linux/' "$SUMS" > "$SUMS.new" || true
find linux -type f | LC_ALL=C sort | while read -r f; do printf '%s *%s\n' "$(sha256sum "$f" | cut -c1-64)" "$f"; done >> "$SUMS.new"
mv "$SUMS.new" "$SUMS"
{
  echo ""
  echo "linux/  git $VERSION — $(date -u +%Y-%m-%dT%H:%M:%SZ) $(hostname)에서 빌드"
  echo "  소스: $URL  sha256 $SHA256"
  echo "  빌드 옵션: ${OPTS[*]}"
  echo "  $(ldd --version | head -1)"
  echo "  진입점: linux/bin/git"
} >> VERSION.txt
echo "완료. 매니페스트 확인: php tools/check_bin_vcs.php"
