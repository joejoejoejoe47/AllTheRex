#!/usr/bin/env bash
# Download the official Kokoro fp32 ONNX at a pinned revision and verify size + SHA-256.
# Usage: fetch_kokoro_fp32.sh DEST_PATH
set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/kokoro_fp32_artifact.env"
DEST="${1:-}"
if [ -z "$DEST" ]; then
  echo "usage: fetch_kokoro_fp32.sh DEST_PATH" >&2
  exit 1
fi
URL="https://huggingface.co/${KOKORO_FP32_REPO}/resolve/${KOKORO_FP32_HF_REV}/onnx/model.onnx"
mkdir -p "$(dirname "$DEST")"
if [ -f "$DEST" ]; then
  got_bytes=$(wc -c < "$DEST")
  got_sha=$(sha256sum "$DEST" | awk '{print $1}')
  if [ "$got_bytes" = "$KOKORO_FP32_BYTES" ] && [ "$got_sha" = "$KOKORO_FP32_SHA256" ]; then
    echo "already verified $DEST"
    exit 0
  fi
  echo "removing unverified $DEST (bytes=$got_bytes sha=$got_sha)"
  rm -f "$DEST"
fi
echo "fetching pinned $URL"
curl -fL --retry 3 --retry-delay 2 "$URL" -o "$DEST.partial"
got_bytes=$(wc -c < "$DEST.partial")
got_sha=$(sha256sum "$DEST.partial" | awk '{print $1}')
if [ "$got_bytes" != "$KOKORO_FP32_BYTES" ] || [ "$got_sha" != "$KOKORO_FP32_SHA256" ]; then
  rm -f "$DEST.partial"
  echo "verification failed bytes=$got_bytes sha=$got_sha (want $KOKORO_FP32_BYTES $KOKORO_FP32_SHA256)" >&2
  exit 1
fi
mv "$DEST.partial" "$DEST"
echo "fetched and verified $DEST ($got_bytes bytes $got_sha)"
