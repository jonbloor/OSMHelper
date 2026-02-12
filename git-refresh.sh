#!/usr/bin/env bash

set -e

echo "=== Git refresh starting ==="

# ensure we are in a git repo
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "❌ Not inside a git repository"
  exit 1
fi

# ensure .env is ignored
if [ -f ".gitignore" ]; then
  if ! grep -q '^.env' .gitignore; then
    echo ".env" >> .gitignore
    echo "Added .env to .gitignore"
  fi
else
  echo ".env" > .gitignore
  echo "Created .gitignore with .env"
fi

# untrack env if previously tracked
git rm --cached .env 2>/dev/null || true

# stage everything including deletions
git add -A

echo
git status --short
echo

# commit (skip if nothing to commit)
if git diff --cached --quiet; then
  echo "Nothing to commit."
else
  git commit -m "Server refresh $(date +%F-%H%M)"
fi

# push
if git push origin main; then
  echo "✅ Push successful"
else
  echo "Push rejected — trying safe force push"
  git push --force-with-lease origin main
fi

echo "=== Done ==="
