#!/usr/bin/env bash
# Builds a Claude Desktop extension (.mcpb) for the TYPO3 MCP server.
#
# Usage:
#   build.sh [server-url] [display-name] [output-file]
#   build.sh https://dev.example.com "Example (dev)"
#
# server-url pre-fills the URL field in the extension settings, so team members
# only have to paste their token. Without it, the URL is asked for as well.
# Needs bash and python3 only (no zip, no npm).
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_URL="${1:-}"
DISPLAY_NAME="${2:-TYPO3}"
SLUG="$(printf '%s' "$DISPLAY_NAME" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+|-+$//g')"
OUTPUT="${3:-${SLUG:-typo3}.mcpb}"

python3 -I - "$SOURCE_DIR" "$OUTPUT" "$SERVER_URL" "$DISPLAY_NAME" "$SLUG" <<'PY'
import json, sys, zipfile

source_dir, output, server_url, display_name, slug = sys.argv[1:6]
with open(f'{source_dir}/manifest.json', encoding='utf-8') as f:
    manifest = json.load(f)

manifest['display_name'] = display_name
if slug and slug != 'typo3':
    # Distinct names let several sites (dev, live) be installed side by side
    manifest['name'] = f'typo3-mcp-{slug}'
if server_url:
    manifest['user_config']['server_url']['default'] = server_url

# Files only, no directory entries: those break some unpackers
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    archive.writestr('manifest.json', json.dumps(manifest, indent=2) + '\n')
    archive.write(f'{source_dir}/server/index.js', 'server/index.js')
    archive.write(f'{source_dir}/icon.png', 'icon.png')
print(f'Wrote {output}')
PY
