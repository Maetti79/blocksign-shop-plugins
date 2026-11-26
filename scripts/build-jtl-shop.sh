#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
mkdir -p "$staging/blocksign_esign/core" build
cp -R plugins/jtl-shop/. "$staging/blocksign_esign/"
cp LICENSE "$staging/blocksign_esign/"
cp packages/php/src/*.php "$staging/blocksign_esign/core/"
find "$staging" -mindepth 1 -type d -exec chmod 755 {} +
find "$staging" -type f -exec chmod 644 {} +
archive="$PWD/build/blocksign-esign-jtl-shop.zip"
rm -f "$archive"
(cd "$staging" && zip -q -r "$archive" blocksign_esign)
