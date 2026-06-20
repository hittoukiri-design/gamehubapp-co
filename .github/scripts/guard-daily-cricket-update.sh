#!/usr/bin/env bash
set -euo pipefail

echo "Checking SEO automation safety boundaries..."

changed_files="$(
  {
    git diff --name-only
    git diff --cached --name-only
    git ls-files --others --exclude-standard
  } | sort -u
)"

blocked=0
if [ -n "$changed_files" ]; then
  while IFS= read -r path; do
    [ -z "$path" ] && continue
    case "$path" in
      blog/*|cricket-betting-site-india/index.html|sitemap.xml)
        ;;
      *)
        echo "::error file=$path::SEO automation may not change this path."
        blocked=1
        ;;
    esac
  done <<EOF
$changed_files
EOF
fi

if ! grep -q '<title>YaarWin Game Login India' index.html; then
  echo "::error file=index.html::Root homepage title changed or is missing."
  blocked=1
fi

if grep -q 'Cricket Betting Site India' index.html; then
  echo "::error file=index.html::Root homepage contains cricket guide title/content."
  blocked=1
fi

if ! grep -q 'id="yw-page-visit-counter-js"' index.html || ! grep -q 'yaarwinappco' index.html; then
  echo "::error file=index.html::Admin visit counter hook is missing."
  blocked=1
fi

if [ "$(git hash-object index.html)" = "$(git hash-object cricket-betting-site-india/index.html)" ]; then
  echo "::error file=index.html::Root homepage must not match the cricket guide page."
  blocked=1
fi

if [ "$blocked" -ne 0 ]; then
  echo "SEO safety guard failed. No commit will be pushed."
  exit 1
fi

echo "SEO safety guard passed."
