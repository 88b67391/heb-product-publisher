#!/usr/bin/env bash
# 本地打包 heb-product-publisher.zip（与 GitHub Actions release.yml 逻辑一致）
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/heb-product-publisher.zip"
STAGE="$(mktemp -d)/heb-product-publisher"

mkdir -p "$ROOT/build" "$STAGE"

rsync -a \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='build' \
	--exclude='node_modules' \
	--exclude='.idea' \
	--exclude='.vscode' \
	--exclude='.DS_Store' \
	--exclude='*.zip' \
	--exclude='tests' \
	--exclude='phpunit.xml*' \
	--exclude='composer.lock' \
	"$ROOT/" "$STAGE/"

rm -f "$OUT"
( cd "$(dirname "$STAGE")" && zip -rq "$OUT" "heb-product-publisher" )

echo "Built: $OUT"
ls -lh "$OUT"

PLUGIN=$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$ROOT/heb-product-publisher.php" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
echo "Plugin version: $PLUGIN"
echo "Release tag should be: v${PLUGIN}"
