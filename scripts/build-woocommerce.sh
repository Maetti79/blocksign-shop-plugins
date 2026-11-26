#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
mkdir -p "$staging/blocksign-esign/core" build
cp plugins/woocommerce/blocksign-esign.php LICENSE "$staging/blocksign-esign/"
cp packages/php/src/*.php "$staging/blocksign-esign/core/"
archive="$PWD/build/blocksign-esign-woocommerce.zip"
rm -f "$archive"
(cd "$staging" && zip -q -r "$archive" blocksign-esign)
