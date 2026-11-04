#!/usr/bin/env bash
# Build the installable plugin zip: dist/lavkhata-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 "Version:" lavkhata/lavkhata.php | awk '{print $NF}')
mkdir -p dist
rm -f "dist/lavkhata-${version}.zip"
zip -rq -9 "dist/lavkhata-${version}.zip" lavkhata -x "*.DS_Store" "*.po" "*.pot"
echo "dist/lavkhata-${version}.zip"
