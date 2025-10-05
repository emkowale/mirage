#!/bin/bash
set -e

# Usage: ./release.sh [major|minor|patch]
# Example: ./release.sh patch

if [ -z "$1" ]; then
  echo "Usage: $0 [major|minor|patch]"
  exit 1
fi

BUMP=$1
PROJECT=$(basename "$PWD")

# ---------- helpers ----------
read_file_version() {
  if [ -f "style.css" ]; then
    grep -i "Version:" style.css | head -n1 | sed -E 's/.*Version:[[:space:]]*//'
  elif [ -f "${PROJECT}.php" ]; then
    grep -i "Version:" "${PROJECT}.php" | head -n1 | sed -E 's/.*Version:[[:space:]]*//'
  else
    echo "0.0.0"
  fi
}

ver_max() {
  # prints the greater of two semver strings (no 'v' prefix)
  printf "%s\n%s\n" "$1" "$2" | sort -V | tail -n1
}

tag_exists() {
  git rev-parse -q --verify "refs/tags/$1" >/dev/null 2>&1
}

# ---------- determine base version ----------
LATEST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "v0.0.0")
TAGVER="${LATEST_TAG#v}"
FILEVER="$(read_file_version)"
BASEVER="$(ver_max "$FILEVER" "$TAGVER")"

IFS='.' read -r MAJOR MINOR PATCH <<< "$BASEVER"

case $BUMP in
  major) MAJOR=$((MAJOR+1)); MINOR=0; PATCH=0 ;;
  minor) MINOR=$((MINOR+1)); PATCH=0 ;;
  patch) PATCH=$((PATCH+1)) ;;
  *) echo "❌ Invalid bump type. Use: major, minor, patch"; exit 1 ;;
esac

NEWVER="${MAJOR}.${MINOR}.${PATCH}"
# if tag already exists, bump patch until free
while tag_exists "v${NEWVER}"; do
  PATCH=$((PATCH+1))
  NEWVER="${MAJOR}.${MINOR}.${PATCH}"
done

ZIPNAME="${PROJECT}-v${NEWVER}.zip"
echo "🚀 Bumping $PROJECT $BASEVER → $NEWVER"

# ---------- update versions (SSHFS-safe) ----------
if [ -f "style.css" ]; then
  sed -E 's/^Version:.*/Version: '"${NEWVER}"'/' style.css > style.css.tmp && mv style.css.tmp style.css
  echo "✔ Updated style.css"
fi

if [ -f "${PROJECT}.php" ]; then
  sed -E 's/^(\s*\*\s*Version:).*/\1 '"${NEWVER}"'/' "${PROJECT}.php" > tmpfile && mv tmpfile "${PROJECT}.php"
  echo "✔ Updated ${PROJECT}.php"
fi

# ---------- build a USEFUL, GROUPED changelog section ----------
PREV_TAG="$LATEST_TAG"
if git rev-parse -q --verify "$PREV_TAG" >/dev/null 2>&1 && [ "$PREV_TAG" != "v0.0.0" ]; then
  LOG_RANGE="${PREV_TAG}..HEAD"
else
  LOG_RANGE=""
fi

# All commit subjects (no merges), drop release bumps & generic changelog bumps
if [ -n "$LOG_RANGE" ]; then
  COMMITS_RAW="$(git log $LOG_RANGE --no-merges --pretty=format:'%s' \
    | grep -Ev '^(chore\(release\):|chore: release|update changelog|bump version|release v)' || true)"
  FILES_CHANGED="$(git diff --name-status $LOG_RANGE || true)"
else
  COMMITS_RAW="$(git log --no-merges --pretty=format:'%s' \
    | grep -Ev '^(chore\(release\):|chore: release|update changelog|bump version|release v)' || true)"
  FILES_CHANGED="$(git diff --name-status $(git hash-object -t tree /dev/null) HEAD || true)"
fi

# Buckets (Conventional Commits)
bucket() { echo "$COMMITS_RAW" | grep -Ei "^$1(\(.+\))?: " | sed -E "s/^$1(\(.+\))?: /- /" || true; }

FEAT="$(bucket feat)"
FIX="$(bucket fix)"
PERF="$(bucket perf)"
REFACTOR="$(bucket refactor)"
DOCS="$(bucket docs)"
STYLE="$(bucket style)"
TESTS="$(bucket test)"
BUILD="$(bucket build)"
CI="$(bucket ci)"

# Anything that didn't match known prefixes
MISC="$(echo "$COMMITS_RAW" | grep -Ev '^(feat|fix|perf|refactor|docs|style|test|build|ci)(\(.+\))?: ' | sed 's/^/- /' || true)"

# Optional hand-written highlights (put short notes in RELEASE_NOTES.md before running)
NOTES=""
[ -f RELEASE_NOTES.md ] && NOTES="$(cat RELEASE_NOTES.md)"

# Compose the new section (also used as GitHub release notes)
{
  echo "## v${NEWVER} - $(date +%Y-%m-%d)"

  if [ -n "$NOTES" ]; then
    echo
    echo "$NOTES"
  fi

  if [ -n "$FEAT" ]; then echo; echo "### Features"; echo "$FEAT"; fi
  if [ -n "$FIX" ]; then echo; echo "### Fixes"; echo "$FIX"; fi
  if [ -n "$PERF" ]; then echo; echo "### Performance"; echo "$PERF"; fi
  if [ -n "$REFACTOR" ]; then echo; echo "### Refactoring"; echo "$REFACTOR"; fi
  if [ -n "$DOCS" ]; then echo; echo "### Docs"; echo "$DOCS"; fi
  if [ -n "$STYLE" ]; then echo; echo "### Style"; echo "$STYLE"; fi
  if [ -n "$TESTS" ]; then echo; echo "### Tests"; echo "$TESTS"; fi
  if [ -n "$BUILD" ]; then echo; echo "### Build"; echo "$BUILD"; fi
  if [ -n "$CI" ]; then echo; echo "### CI"; echo "$CI"; fi
  if [ -n "$MISC" ]; then echo; echo "### Other"; echo "$MISC"; fi

  if [ -n "$FILES_CHANGED" ]; then
    echo
    echo "### Changed files"
    echo "$FILES_CHANGED" | sed 's/^/- /'
  fi

  if [ -z "$FEAT$FIX$PERF$REFACTOR$DOCS$STYLE$TESTS$BUILD$CI$MISC" ]; then
    echo
    echo "_No user-facing changes in this release._"
  fi
} > .release_notes.tmp

# Prepend to CHANGELOG.md (newest on top)
{
  cat .release_notes.tmp
  echo
  echo
  [ -f CHANGELOG.md ] && cat CHANGELOG.md
} > .changelog.tmp
mv .changelog.tmp CHANGELOG.md
echo "✔ Updated CHANGELOG.md"

# ---------- commit & push ----------
git add -A
git commit -m "chore(release): v${NEWVER}" || echo "ℹ Nothing to commit"
git push

# ---------- tag & push tag ----------
git tag -a "v${NEWVER}" -m "${PROJECT} v${NEWVER}"
git push origin "v${NEWVER}"

# ---------- build clean ZIP with correct WP structure ----------
cd ..
WORKDIR="${PROJECT}-build"
rm -rf "$WORKDIR"
mkdir "$WORKDIR"

rsync -a "$PROJECT/" "$WORKDIR/$PROJECT/" \
  --exclude ".git" --exclude ".github" --exclude "node_modules" \
  --exclude "vendor" --exclude "*.map" --exclude "*.DS_Store" \
  --exclude "release.sh"

cd "$WORKDIR"
zip -r "../$ZIPNAME" "$PROJECT" >/dev/null
cd ..
rm -rf "$WORKDIR"
cd "$PROJECT"

# ---------- GitHub release ----------
if command -v gh >/dev/null 2>&1; then
  gh release create "v${NEWVER}" "../$ZIPNAME" \
    --title "${PROJECT} v${NEWVER}" \
    --notes-file .release_notes.tmp
  echo "✔ Published GitHub release v${NEWVER}"
  rm -f .release_notes.tmp
else
  echo "⚠ 'gh' not found; created tag and zip, but did not publish a GitHub release."
  echo "   You can run: gh release create v${NEWVER} ../${ZIPNAME} --title \"${PROJECT} v${NEWVER}\" --notes-file .release_notes.tmp"
fi

echo "🎉 Release $PROJECT v$NEWVER complete"
