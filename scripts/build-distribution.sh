#!/usr/bin/env bash
# Trap-safe, concurrency-safe convenience wrapper around Castor distribution tasks.
# Does not reimplement packaging — only invokes checked-in Castor tasks.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

TARGET=""
OUTPUT=""
VERSION="${HATFIELD_BUILD_VERSION:-}"
COMMIT="${HATFIELD_BUILD_COMMIT:-}"
STATIC="false"
PHAR_ONLY="false"

usage() {
  cat <<'EOF'
Usage: scripts/build-distribution.sh [options]

Options:
  --target=<linux-amd64|linux-arm64|darwin-amd64|darwin-arm64>
  --output=<dir>          Dist output directory (default: var/tmp/dist)
  --version=<semver>      Embed release version (alias: --release-version, also accepts space form)
  --release-version=<semver>      Embed release version
  --commit=<sha>          Embed commit SHA
  --static                Build host static binary (after PHAR)
  --phar-only             Only build PHAR into dist (default when --static omitted)
  -h, --help              Show help

Environment:
  HATFIELD_BUILD_VERSION, HATFIELD_BUILD_COMMIT, HATFIELD_DIST_DIR
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --target=*) TARGET="${1#*=}"; shift ;;
    --target) TARGET="${2:-}"; shift 2 ;;
    --output=*) OUTPUT="${1#*=}"; shift ;;
    --output) OUTPUT="${2:-}"; shift 2 ;;
    --release-version=*) VERSION="${1#*=}"; shift ;;
    --version=*) VERSION="${1#*=}"; shift ;;
    --version) VERSION="${2:-}"; shift 2 ;;
    --commit=*) COMMIT="${1#*=}"; shift ;;
    --commit) COMMIT="${2:-}"; shift 2 ;;
    --static) STATIC="true"; shift ;;
    --phar-only) PHAR_ONLY="true"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
  esac
done

TMPDIR_BUILD="$(mktemp -d "${TMPDIR:-/tmp}/hatfield-dist-build.XXXXXX")"
cleanup() {
  rm -rf "${TMPDIR_BUILD}"
}
trap cleanup EXIT INT TERM

export HATFIELD_DIST_DIR="${OUTPUT:-${HATFIELD_DIST_DIR:-var/tmp/dist}}"
if [[ -n "${VERSION}" ]]; then
  export HATFIELD_BUILD_VERSION="${VERSION}"
fi
if [[ -n "${COMMIT}" ]]; then
  export HATFIELD_BUILD_COMMIT="${COMMIT}"
fi

echo "Repo root: ${REPO_ROOT}"
echo "Dist dir:  ${HATFIELD_DIST_DIR}"
echo "Work tmp:  ${TMPDIR_BUILD}"

ARGS=()
if [[ -n "${OUTPUT}" ]]; then
  ARGS+=(--output="${OUTPUT}")
fi
if [[ -n "${VERSION}" ]]; then
  ARGS+=(--release-version="${VERSION}")
fi
if [[ -n "${COMMIT}" ]]; then
  ARGS+=(--commit="${COMMIT}")
fi

castor distribution:build "${ARGS[@]+"${ARGS[@]}"}"

if [[ "${STATIC}" == "true" ]]; then
  STATIC_ARGS=("${ARGS[@]+"${ARGS[@]}"}")
  if [[ -n "${TARGET}" ]]; then
    STATIC_ARGS+=(--target="${TARGET}")
  fi
  castor distribution:build-static "${STATIC_ARGS[@]+"${STATIC_ARGS[@]}"}"
elif [[ "${PHAR_ONLY}" != "true" ]]; then
  # Default remains PHAR-only; --static opts into native.
  :
fi

castor distribution:checksums ${OUTPUT:+--output="${OUTPUT}"}
castor distribution:verify ${OUTPUT:+--output="${OUTPUT}"}

echo "Distribution build complete."
