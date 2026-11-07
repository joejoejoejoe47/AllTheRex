#!/usr/bin/env bash
# Remove Kokoro ONNX weight files from a built chess/ tree so they are not committed.
# GitHub rejects files over 100 MB; fp32 model.onnx is 325,532,232 bytes.
# The checksum sidecar (*.sha256) is kept. q8 leftovers are also removed so they
# cannot be recommitted as a silent fallback.
set -euo pipefail
ROOT="${1:-chess}"
ROOT="${ROOT%/}"
if [ ! -d "$ROOT" ]; then
  echo "strip: no directory $ROOT" >&2
  exit 1
fi
removed=0
if [ -d "$ROOT/bots/kokoro" ]; then
  while IFS= read -r f; do
    echo "stripping $f ($(wc -c < "$f") bytes)"
    rm -f "$f"
    removed=$((removed + 1))
  done < <(find "$ROOT/bots/kokoro" -type f -name '*.onnx' -print)
fi
echo "stripped $removed onnx file(s) under $ROOT"
