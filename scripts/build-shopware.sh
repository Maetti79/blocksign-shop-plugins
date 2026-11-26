#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
mkdir -p "$staging/BlockSignEsign/src/Core" build
cp -R plugins/shopware/. "$staging/BlockSignEsign/"
cp packages/php/src/*.php "$staging/BlockSignEsign/src/Core/"
cp LICENSE "$staging/BlockSignEsign/"
find "$staging" -mindepth 1 -type d -exec chmod 755 {} +
find "$staging" -type f -exec chmod 644 {} +
archive="$PWD/build/blocksign-esign-shopware.zip"
rm -f "$archive"
(cd "$staging" && zip -q -r "$archive" BlockSignEsign)
