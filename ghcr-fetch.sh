#!/bin/bash
# ghcr-fetch.sh — 从 GHCR 以纯 curl 拉取 Compose Manager 插件包 (.txz)
# 不依赖 Docker / oras：GHCR 公开包可匿名拿 token -> manifest -> blob
# 用法: ./ghcr-fetch.sh [版本] [期望MD5]
#   例: ./ghcr-fetch.sh 2026.10.31d 36b458298e805dd6b5a0b6f9245f0b60
# 成功后 .txz 落在 /boot/config/plugins/compose.manager/ (U盘, 重启不丢)
set -euo pipefail

REPO="bcfxclub/compose-manager"
TAG="${1:-2026.10.31d}"
EXPECT_MD5="${2:-}"
DEST="/boot/config/plugins/compose.manager"
mkdir -p "$DEST"

TOKEN=$(curl -fsSL "https://ghcr.io/token?service=ghcr.io&scope=repository:${REPO}:pull" \
  | sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] || { echo "ERROR: 拿不到 GHCR 匿名 token (包未公开?)"; exit 1; }

ACCEPT="Accept: application/vnd.oci.image.manifest.v1+json, application/vnd.oci.image.index.v1+json"
MANIFEST=$(curl -fsSL -H "Authorization: Bearer $TOKEN" -H "$ACCEPT" \
  "https://ghcr.io/v2/${REPO}/manifests/${TAG}")

# 若返回的是 index, 取第一个子 manifest 再拉一次
if echo "$MANIFEST" | grep -q '"manifests"'; then
  CHILD=$(echo "$MANIFEST" | grep -o '"digest"[[:space:]]*:[[:space:]]*"sha256:[a-f0-9]*"' | head -1 | grep -o 'sha256:[a-f0-9]*')
  MANIFEST=$(curl -fsSL -H "Authorization: Bearer $TOKEN" -H "$ACCEPT" \
    "https://ghcr.io/v2/${REPO}/manifests/${CHILD}")
fi

# 从 layers 里取 .txz 那一层 (先压平 JSON, 避免单行 manifest 时取到 config 的 digest)
FLAT=$(echo "$MANIFEST" | tr -d '\n')
LAYERPART=$(echo "$FLAT" | sed 's/.*"layers"[[:space:]]*:[[:space:]]*\[//')
DIGEST=$(echo "$LAYERPART" | grep -o 'sha256:[a-f0-9]*' | head -1)
FILENAME=$(echo "$LAYERPART" | sed -n 's/.*"org.opencontainers.image.title"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)
[ -n "${DIGEST:-}" ] && [ -n "${FILENAME:-}" ] || { echo "ERROR: manifest 里找不到 .txz 层"; echo "$MANIFEST" | head -c 1000; exit 1; }

echo "拉取 $FILENAME ($DIGEST)"
curl -fsSL -H "Authorization: Bearer $TOKEN" \
  "https://ghcr.io/v2/${REPO}/blobs/${DIGEST}" -o "$DEST/$FILENAME"

if [ -n "$EXPECT_MD5" ]; then
  ACTUAL=$(md5sum "$DEST/$FILENAME" | cut -d' ' -f1)
  [ "$ACTUAL" = "$EXPECT_MD5" ] || { echo "ERROR: MD5 不符 $ACTUAL != $EXPECT_MD5"; rm -f "$DEST/$FILENAME"; exit 1; }
  echo "MD5 校验通过: $ACTUAL"
fi
echo "已保存: $DEST/$FILENAME"
