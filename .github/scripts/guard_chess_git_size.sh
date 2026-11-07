#!/usr/bin/env bash
# Fail if any file under a tree is at or over GitHub's 100 MB limit.
set -euo pipefail
ROOT="${1:-chess}"
ROOT="${ROOT%/}"
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/kokoro_fp32_artifact.env"
MAX="${GITHUB_MAX_FILE_BYTES}"
if [ ! -d "$ROOT" ]; then
  echo "guard: no directory $ROOT" >&2
  exit 1
fi
fail=0
while IFS= read -r f; do
  sz=$(wc -c < "$f")
  if [ "$sz" -ge "$MAX" ]; then
    echo "oversized for git (>= $MAX bytes): $sz $f" >&2
    fail=1
  fi
done < <(find "$ROOT" -type f -print)
if [ "$fail" -ne 0 ]; then
  echo "refusing to commit files at or over GitHub's 100 MB limit" >&2
  exit 1
fi
echo "git-size guard ok under $ROOT (limit $MAX bytes)"
