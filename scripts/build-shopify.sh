#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
root="$staging/blocksign-shopify"
mkdir -p "$root/plugins/shopify" "$root/packages/typescript/src" "$root/tests" build
cp -R plugins/shopify/src plugins/shopify/README.md plugins/shopify/package.json \
  plugins/shopify/config.example.json plugins/shopify/shopify.app.toml.example plugins/shopify/tsconfig.json "$root/plugins/shopify/"
cp packages/typescript/src/gate.ts packages/typescript/src/blocksign.ts "$root/packages/typescript/src/"
cp packages/typescript/package.json packages/typescript/package-lock.json packages/typescript/tsconfig.json "$root/packages/typescript/"
cp tests/shopify.mjs tests/gate.mjs tests/gate.json "$root/tests/"
cp LICENSE README.md "$root/"
find "$staging" -mindepth 1 -type d -exec chmod 755 {} +
find "$staging" -type f -exec chmod 644 {} +
archive="$PWD/build/blocksign-esign-shopify.zip"
rm -f "$archive"
(cd "$staging" && zip -q -r "$archive" blocksign-shopify)
