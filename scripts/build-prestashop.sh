#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
mkdir -p "$staging/blocksignesign/core" build
cp -R plugins/prestashop/. "$staging/blocksignesign/"
cp LICENSE "$staging/blocksignesign/"
cp packages/php/src/*.php "$staging/blocksignesign/core/"
find "$staging" -mindepth 1 -type d -exec chmod 755 {} +
find "$staging" -type f -exec chmod 644 {} +
archive="$PWD/build/blocksign-esign-prestashop.zip"
rm -f "$archive"
(cd "$staging" && zip -q -r "$archive" blocksignesign)
