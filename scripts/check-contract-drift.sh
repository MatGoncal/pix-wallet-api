#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CANONICAL="${CONTRACT_CANONICAL_DIR:-$ROOT/scripts/contract-canonical}"
SPECS="$ROOT/Docs/specs"
FILES=(API_CONTRACT.md error-codes.md openapi.yaml)

if [[ ! -d "$CANONICAL" ]]; then
  SHARED="$ROOT/../../_shared/openapi"
  if [[ -d "$SHARED" ]]; then
    CANONICAL="$SHARED"
  else
    echo "Contract canonical directory not found: $CANONICAL" >&2
    exit 1
  fi
fi

for file in "${FILES[@]}"; do
  if [[ ! -f "$SPECS/$file" ]]; then
    echo "Missing contract file: $SPECS/$file" >&2
    exit 1
  fi

  if [[ ! -f "$CANONICAL/$file" ]]; then
    echo "Missing canonical file: $CANONICAL/$file" >&2
    exit 1
  fi

  if ! diff -u "$CANONICAL/$file" "$SPECS/$file" >/dev/null; then
    echo "Contract drift detected in $file" >&2
    diff -u "$CANONICAL/$file" "$SPECS/$file" >&2 || true
    exit 1
  fi
done

echo "Contract files match canonical baseline."
