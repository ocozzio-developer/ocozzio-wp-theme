#!/usr/bin/env bash
# Writes update.json or update-beta.json on the update-feed branch, pointing at release v<version>.
# Usage: .github/feed.sh <update.json|update-beta.json> <version>
set -euo pipefail

file="$1"
version="$2"
base="https://github.com/${GITHUB_REPOSITORY}/releases"

git config --global user.name "github-actions[bot]"
git config --global user.email "41898282+github-actions[bot]@users.noreply.github.com"

if git ls-remote --exit-code --heads origin update-feed >/dev/null; then
  git fetch --depth=1 origin update-feed
  git worktree add feed FETCH_HEAD
  cd feed
  git checkout -q -B update-feed
else
  git worktree add --detach feed
  cd feed
  git checkout -q --orphan update-feed
  git rm -rqf --ignore-unmatch .
fi

printf '{\n  "version": "%s",\n  "details_url": "%s/tag/v%s",\n  "download_url": "%s/download/v%s/ocozzio.zip"\n}\n' \
  "$version" "$base" "$version" "$base" "$version" > "$file"

git add "$file"
git commit -qm "$file: $version"
git push -q origin update-feed
echo "$file now points at v$version"
