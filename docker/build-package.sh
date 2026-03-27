#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/.docker-build"
STAGE_DIR="${BUILD_DIR}/package-stage"
PACKAGE_DIR="${STAGE_DIR}/pkg_maxcache"
VERSION="$(grep -m1 -oE '<version>[^<]+' "${ROOT_DIR}/pkg_maxcache/pkg_maxcache.xml" | sed 's/<version>//')"
OUTPUT_ZIP="${BUILD_DIR}/pkg_maxcache-${VERSION}.zip"
LATEST_ZIP="${BUILD_DIR}/pkg_maxcache-lab.zip"
UPDATE_DIR="${ROOT_DIR}/updates"
UPDATE_FEED="${UPDATE_DIR}/pkg_maxcache.xml"
REPO_SLUG="velisnolis/MAxCache-for-Joomla"

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

zip_dir_contents() {
  local src_dir="$1"
  local zip_path="$2"

  rm -f "$zip_path"
  (
    cd "$src_dir"
    zip -qr "$zip_path" .
  )
}

require_cmd zip

rm -rf "$STAGE_DIR"
mkdir -p "${PACKAGE_DIR}/packages"

cp "${ROOT_DIR}/pkg_maxcache/pkg_maxcache.xml" "${PACKAGE_DIR}/pkg_maxcache.xml"
cp "${ROOT_DIR}/pkg_maxcache/script.php" "${PACKAGE_DIR}/script.php"

zip_dir_contents "${ROOT_DIR}/pkg_maxcache/packages/plg_system_maxcache" "${PACKAGE_DIR}/packages/plg_system_maxcache.zip"

rm -f "$OUTPUT_ZIP" "$LATEST_ZIP"
(
  cd "$PACKAGE_DIR"
  zip -qr "$OUTPUT_ZIP" .
)

cp "$OUTPUT_ZIP" "$LATEST_ZIP"

mkdir -p "$UPDATE_DIR"
SHA256="$(shasum -a 256 "$OUTPUT_ZIP" | awk '{print $1}')"
RELEASE_URL="https://github.com/${REPO_SLUG}/releases/download/v${VERSION}/pkg_maxcache-${VERSION}.zip"
INFO_URL="https://github.com/${REPO_SLUG}/releases/tag/v${VERSION}"

cat > "$UPDATE_FEED" <<EOF
<?xml version="1.0" encoding="utf-8"?>
<updates>
  <update>
    <name>MAxCache for Joomla</name>
    <description>MAxCache for Joomla package for Joomla sites using deterministic static page caching.</description>
    <element>pkg_maxcache</element>
    <type>package</type>
    <client>site</client>
    <version>${VERSION}</version>
    <infourl title="MAxCache for Joomla">${INFO_URL}</infourl>
    <downloads>
      <downloadurl type="full" format="zip">${RELEASE_URL}</downloadurl>
    </downloads>
    <tags>
      <tag>stable</tag>
    </tags>
    <maintainer>Alex Miras</maintainer>
    <maintainerurl>https://miras.pro</maintainerurl>
    <targetplatform name="joomla" version="[56]\.[0-9]+"/>
    <sha256>${SHA256}</sha256>
  </update>
</updates>
EOF

echo "$OUTPUT_ZIP"
