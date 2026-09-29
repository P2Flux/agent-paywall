#!/usr/bin/env bash
# Build the package exactly as WordPress receives it: the committed tree minus .distignore.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/..}"
name="p2flux-agent-paywall"
bash "$root/dev/release-check.sh"
[ -z "$(git -C "$root" status --porcelain --untracked-files=no)" ] || { echo "FAIL: uncommitted changes" >&2; exit 1; }
staging="$(mktemp -d)"
mkdir -p "$staging/$name"
git -C "$root" archive --format=tar HEAD | tar -x -C "$staging/$name"
( cd "$staging/$name"
  while IFS= read -r pattern; do
    case "$pattern" in ''|\#*) continue;; esac
    rm -rf $pattern
  done < "$root/.distignore"
  find . -name '*.md' -type f -delete )
version="$(grep -m1 '^ \* Version:' "$root/$name.php" | awk '{print $3}')"
zip="$out/$name-$version.zip"
rm -f "$zip"
( cd "$staging" && zip -qr "$zip" "$name" )
rm -rf "$staging"
( cd "$out" && sha256sum "$(basename "$zip")" )
