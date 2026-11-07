#!/usr/bin/env bash
# Dry-run tests for fp32 delivery: constants, workflow wiring, strip, git-size guard.
# Does not download the 325 MB model and does not upload anything.
set -euo pipefail
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/kokoro_fp32_artifact.env"

assert() {
  if [ "$1" != "$2" ]; then
    echo "ASSERT fail: expected [$2] got [$1] ($3)" >&2
    exit 1
  fi
}
assert_ok() {
  if ! eval "$1"; then
    echo "ASSERT fail: $2" >&2
    exit 1
  fi
}

echo "== constants"
assert "$KOKORO_FP32_BYTES" "325532232" "model bytes"
assert "$KOKORO_FP32_SHA256" "8fbea51ea711f2af382e88c833d9e288c6dc82ce5e98421ea61c058ce21a34cb" "model sha256"
assert "$KOKORO_FP32_HF_REV" "468588286ebb2dd77c25b9771e5d165896538cce" "pinned revision"
assert "$KOKORO_FP32_REL" "bots/kokoro/models/onnx-community/Kokoro-82M-v1.0-ONNX/onnx/model.onnx" "relative dest"
assert "$KOKORO_RUNTIME_BYTES" "33843226" "runtime+voices bytes"
assert "$KOKORO_PACK_BYTES" "359375458" "combined first-enable bytes"
assert "$((KOKORO_FP32_BYTES + KOKORO_RUNTIME_BYTES))" "$KOKORO_PACK_BYTES" "pack = model + runtime"
assert "$GITHUB_MAX_FILE_BYTES" "104857600" "100 MiB git limit"
assert_ok "[ \"$KOKORO_FP32_BYTES\" -gt \"$GITHUB_MAX_FILE_BYTES\" ]" "fp32 model must exceed git limit (reason we do not commit it)"

echo "== fetch script pins revision and verifies"
fetch="$SCRIPT_DIR/fetch_kokoro_fp32.sh"
assert_ok "grep -q KOKORO_FP32_HF_REV \"$fetch\"" "fetch uses pinned revision variable"
assert_ok "grep -Fq 'resolve/\${KOKORO_FP32_HF_REV}' \"$fetch\"" "fetch URL includes pinned rev"
assert_ok "! grep -q '/resolve/main/' \"$fetch\"" "fetch must not use unpinned /resolve/main/"
assert_ok "grep -q sha256sum \"$fetch\"" "fetch verifies sha256"
assert_ok "grep -q KOKORO_FP32_BYTES \"$fetch\"" "fetch verifies size"

echo "== workflow wiring"
build="$ROOT/.github/workflows/build-chess.yml"
deploy="$ROOT/.github/workflows/deploy-chess.yml"
assert_ok "grep -q 'strip_kokoro_onnx_for_git.sh chess' \"$build\"" "build strips onnx before commit"
assert_ok "grep -q 'guard_chess_git_size.sh chess' \"$build\"" "build runs git-size guard"
assert_ok "grep -q 'test_kokoro_fp32_delivery.sh' \"$build\"" "build runs delivery tests"
assert_ok "grep -q 'fetch_kokoro_fp32.sh' \"$deploy\"" "deploy fetches official model"
assert_ok "grep -q 'Fetch official Kokoro fp32' \"$deploy\"" "deploy has dedicated fetch step"
assert_ok "grep -q 'kokoro_fp32_artifact.env' \"$deploy\"" "deploy sources pinned artifact env"
assert_ok "grep -Fq '\$KOKORO_FP32_REL' \"$deploy\"" "deploy uses relative dest"
# live URL is $SITE/$KOKORO_FP32_REL → https://alltherex.com/chess/bots/kokoro/.../model.onnx
assert_ok "grep -q 'SITE: https://alltherex.com/chess' \"$deploy\"" "deploy SITE prefix is /chess"
assert_ok "grep -q 'alltherex/chess' \"$deploy\"" "deploy still uploads both FTP destinations"
assert_ok "grep -q 'secrets.PLESK_FTP_HOST' \"$deploy\"" "deploy still uses existing FTP host secret"
assert_ok "grep -q 'secrets.PLESK_FTP_USER' \"$deploy\"" "deploy still uses existing FTP user secret"
assert_ok "grep -q 'secrets.PLESK_FTP_PASSWORD' \"$deploy\"" "deploy still uses existing FTP password secret"
new_secrets=$(grep -oE 'secrets\.[A-Z0-9_]+' "$deploy" | sort -u | grep -vE 'secrets\.PLESK_FTP_(HOST|USER|PASSWORD)' || true)
assert "$new_secrets" "" "no new Actions secrets"
assert_ok "grep -q 'id: smoke' \"$deploy\"" "existing smoke step kept"
assert_ok "grep -q 'Roll back the page to the previous build' \"$deploy\"" "existing rollback kept"
assert_ok "grep -q 'put chess/app.html' \"$deploy\"" "page still uploaded last"
assert_ok "! grep -q 'mirror --' \"$deploy\"" "must not replace per-file upload with full-tree mirror"
assert_ok "grep -q 'will upload non-git' \"$deploy\"" "deploy explicitly queues the non-git model"
assert_ok "grep -q 'app/.env' \"$ROOT/chess/.gitignore\"" "preserves existing chess gitignore"
assert_ok "grep -Fq 'bots/kokoro/models/**/onnx/*.onnx' \"$ROOT/chess/.gitignore\"" "gitignore excludes onnx weights"

echo "== strip dry-run keeps sidecar, drops onnx"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
onnx_dir="$tmp/chess/bots/kokoro/models/onnx-community/Kokoro-82M-v1.0-ONNX/onnx"
mkdir -p "$onnx_dir"
printf 'sidecar\n' > "$onnx_dir/model.onnx.sha256"
printf 'q8-fake\n' > "$onnx_dir/model_quantized.onnx"
printf 'fp32-fake\n' > "$onnx_dir/model.onnx"
bash "$SCRIPT_DIR/strip_kokoro_onnx_for_git.sh" "$tmp/chess"
assert_ok "[ ! -f \"$onnx_dir/model.onnx\" ]" "strip removes model.onnx"
assert_ok "[ ! -f \"$onnx_dir/model_quantized.onnx\" ]" "strip removes q8 leftover"
assert_ok "[ -f \"$onnx_dir/model.onnx.sha256\" ]" "strip keeps sha256 sidecar"

echo "== git-size guard dry-run"
small="$tmp/guard-small"
mkdir -p "$small"
printf 'ok\n' > "$small/tiny.txt"
bash "$SCRIPT_DIR/guard_chess_git_size.sh" "$small"

big="$tmp/guard-big"
mkdir -p "$big"
truncate -s "$GITHUB_MAX_FILE_BYTES" "$big/too-big.bin"
if bash "$SCRIPT_DIR/guard_chess_git_size.sh" "$big"; then
  echo "ASSERT fail: guard should reject a ${GITHUB_MAX_FILE_BYTES}-byte file" >&2
  exit 1
fi
echo "guard correctly rejected oversized file"

echo "== simulated build commit omits onnx"
repo="$tmp/sim-repo"
mkdir -p "$repo"
git -C "$repo" init -q
git -C "$repo" config user.email "test@example.com"
git -C "$repo" config user.name "test"
mkdir -p "$repo/chess/bots/kokoro/models/onnx-community/Kokoro-82M-v1.0-ONNX/onnx"
printf 'sidecar\n' > "$repo/chess/bots/kokoro/models/onnx-community/Kokoro-82M-v1.0-ONNX/onnx/model.onnx.sha256"
printf 'should-not-be-committed\n' > "$repo/chess/bots/kokoro/models/onnx-community/Kokoro-82M-v1.0-ONNX/onnx/model.onnx"
# defense in depth: ignore *.onnx like the real chess/.gitignore
printf 'bots/kokoro/models/**/onnx/*.onnx\n' > "$repo/chess/.gitignore"
bash "$SCRIPT_DIR/strip_kokoro_onnx_for_git.sh" "$repo/chess"
bash "$SCRIPT_DIR/guard_chess_git_size.sh" "$repo/chess"
git -C "$repo" add -A chess
tracked=$(git -C "$repo" ls-files chess)
assert_ok "! printf '%s\n' \"$tracked\" | grep -q '\\.onnx$'" "tracked chess files must not include .onnx"
assert_ok "printf '%s\n' \"$tracked\" | grep -q 'model.onnx.sha256'" "sidecar is tracked"

echo "== fetch verification rejects a bad local file (no network)"
bad="$tmp/bad.onnx"
printf 'not-the-model\n' > "$bad"
got_bytes=$(wc -c < "$bad")
got_sha=$(sha256sum "$bad" | awk '{print $1}')
assert_ok "[ \"$got_bytes\" != \"$KOKORO_FP32_BYTES\" ]" "fixture must not match official size"
assert_ok "[ \"$got_sha\" != \"$KOKORO_FP32_SHA256\" ]" "fixture must not match official sha"

echo "== optional local cache SHA check (read-only, not preview assets)"
cache="/workspace/mc-createbot/node_modules/@huggingface/transformers/.cache/onnx-community/Kokoro-82M-v1.0-ONNX/onnx/model.onnx"
if [ -f "$cache" ]; then
  c_bytes=$(wc -c < "$cache")
  c_sha=$(sha256sum "$cache" | awk '{print $1}')
  assert "$c_bytes" "$KOKORO_FP32_BYTES" "diagnostic cache size"
  assert "$c_sha" "$KOKORO_FP32_SHA256" "diagnostic cache sha256"
  echo "diagnostic cache matches pinned artifact (read-only)"
else
  echo "no local diagnostic cache; skipped extra SHA probe"
fi

echo "PASS kokoro_fp32_delivery tests"
echo "model_bytes=$KOKORO_FP32_BYTES runtime_bytes=$KOKORO_RUNTIME_BYTES combined_first_enable=$KOKORO_PACK_BYTES"
echo "live_dest=\$SITE/$KOKORO_FP32_REL -> https://alltherex.com/chess/$KOKORO_FP32_REL"
