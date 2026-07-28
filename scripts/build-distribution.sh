#!/usr/bin/env bash
# Concurrency-safe convenience wrapper around Castor distribution tasks.
# Does not reimplement packaging — only invokes checked-in Castor tasks.
# Holds a worktree-local lock directory across build/checksums/verify.
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

LOCK_DIR="${REPO_ROOT}/var/tmp/distribution-build.lock"
LOCK_OWNER_FILE="${LOCK_DIR}/owner"
LOCK_HELD="false"

usage() {
  cat <<'EOF'
Usage: scripts/build-distribution.sh [options]

Options:
  --target=<linux-amd64|linux-arm64|darwin-amd64|darwin-arm64>
  --target VALUE
  --output=<dir>          Dist output directory (default: var/tmp/dist)
  --output VALUE
  --version=<semver>      Embed release version (alias: --release-version)
  --version VALUE
  --release-version=<semver>
  --release-version VALUE
  --commit=<sha>          Embed commit SHA
  --commit VALUE
  --static                Build host static binary (after PHAR)
  --phar-only             Only build PHAR into dist (default when --static omitted)
  -h, --help              Show help

Environment:
  HATFIELD_BUILD_VERSION, HATFIELD_BUILD_COMMIT, HATFIELD_DIST_DIR

Concurrency:
  Acquires ${REPO_ROOT}/var/tmp/distribution-build.lock for the whole sequence.
  Concurrent invocations fail closed with holder diagnostics.
EOF
}

require_value() {
  local flag="$1"
  local value="${2:-}"
  if [[ -z "${value}" || "${value}" == --* ]]; then
    echo "ERROR: ${flag} requires a non-empty value" >&2
    usage >&2
    exit 1
  fi
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --target=*)
      TARGET="${1#*=}"
      require_value "--target" "${TARGET}"
      shift
      ;;
    --target)
      require_value "--target" "${2:-}"
      TARGET="$2"
      shift 2
      ;;
    --output=*)
      OUTPUT="${1#*=}"
      require_value "--output" "${OUTPUT}"
      shift
      ;;
    --output)
      require_value "--output" "${2:-}"
      OUTPUT="$2"
      shift 2
      ;;
    --release-version=*)
      VERSION="${1#*=}"
      require_value "--release-version" "${VERSION}"
      shift
      ;;
    --release-version)
      require_value "--release-version" "${2:-}"
      VERSION="$2"
      shift 2
      ;;
    --version=*)
      VERSION="${1#*=}"
      require_value "--version" "${VERSION}"
      shift
      ;;
    --version)
      require_value "--version" "${2:-}"
      VERSION="$2"
      shift 2
      ;;
    --commit=*)
      COMMIT="${1#*=}"
      require_value "--commit" "${COMMIT}"
      shift
      ;;
    --commit)
      require_value "--commit" "${2:-}"
      COMMIT="$2"
      shift 2
      ;;
    --static) STATIC="true"; shift ;;
    --phar-only) PHAR_ONLY="true"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
  esac
done

if [[ "${STATIC}" == "true" && "${PHAR_ONLY}" == "true" ]]; then
  echo "ERROR: --static and --phar-only are mutually exclusive" >&2
  exit 1
fi

release_lock() {
  # May remove only a lock this PID successfully acquired and still owns.
  if [[ "${LOCK_HELD}" != "true" ]]; then
    return 0
  fi
  if [[ -f "${LOCK_OWNER_FILE}" ]]; then
    local owner
    owner="$(cat "${LOCK_OWNER_FILE}" 2>/dev/null || true)"
    if [[ "${owner}" == "$$" ]]; then
      rm -f "${LOCK_OWNER_FILE}" 2>/dev/null || true
      rmdir "${LOCK_DIR}" 2>/dev/null || true
    fi
  fi
  LOCK_HELD="false"
}

on_int() {
  release_lock
  exit 130
}

on_term() {
  release_lock
  exit 143
}

mkdir -p "${REPO_ROOT}/var/tmp"
# Close mkdir→owner orphan window: ignore signals during atomic acquire + ownership mark.
trap '' INT TERM
if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
  trap - INT TERM
  holder="unknown"
  if [[ -f "${LOCK_OWNER_FILE}" ]]; then
    holder="$(cat "${LOCK_OWNER_FILE}" 2>/dev/null || echo unknown)"
  fi
  holder_alive="no"
  if [[ "${holder}" =~ ^[0-9]+$ ]] && kill -0 "${holder}" 2>/dev/null; then
    holder_alive="yes"
  fi
  cat >&2 <<EOF
ERROR: distribution build lock is held.
  lock:   ${LOCK_DIR}
  holder: pid=${holder} alive=${holder_alive}
If the previous build crashed, remove only an orphan lock you own:
  rm -rf ${LOCK_DIR}
EOF
  exit 1
fi
printf '%s\n' "$$" >"${LOCK_OWNER_FILE}"
LOCK_HELD="true"
# Handlers after ownership is marked: EXIT cleans up; INT/TERM release then exit (never continue).
trap release_lock EXIT
trap on_int INT
trap on_term TERM

export HATFIELD_DIST_DIR="${OUTPUT:-${HATFIELD_DIST_DIR:-var/tmp/dist}}"
if [[ -n "${VERSION}" ]]; then
  export HATFIELD_BUILD_VERSION="${VERSION}"
fi
if [[ -n "${COMMIT}" ]]; then
  export HATFIELD_BUILD_COMMIT="${COMMIT}"
fi

echo "Repo root: ${REPO_ROOT}"
echo "Dist dir:  ${HATFIELD_DIST_DIR}"
echo "Lock:      ${LOCK_DIR} (pid $$)"

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
  # build-static already writes SHA256SUMS for present hatfield.* artifacts.
  castor distribution:build-static "${STATIC_ARGS[@]+"${STATIC_ARGS[@]}"}"
  castor distribution:verify ${OUTPUT:+--output="${OUTPUT}"}
else
  # PHAR-only / default: build already wrote SHA256SUMS; hard-require PHAR,
  # allow missing native, skip topology (no native artifact expected).
  castor distribution:verify --skip-topology --allow-missing-native ${OUTPUT:+--output="${OUTPUT}"}
fi

echo "Distribution build complete."
