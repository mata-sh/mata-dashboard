#!/usr/bin/env bash
# Copy the MATA Node reference docs into docs/node/ (gitignored) before an MkDocs build.
#
# Source, in order: $MATA_NODE_DIR, a sibling ../mata-node checkout, or a sparse clone of
# $MATA_NODE_REPO at $MATA_NODE_REF. Edit these pages in mata-node, never in docs/node/.
set -euo pipefail

root="$(CDPATH='' cd -- "$(dirname "$0")/../.." && pwd)"
target="$root/docs/node"
repo="${MATA_NODE_REPO:-https://github.com/mata-sh/mata-node.git}"
ref="${MATA_NODE_REF:-main}"
src="${MATA_NODE_DIR:-}"

if [ -z "$src" ] && [ -d "$root/../mata-node/docs" ]; then
    src="$root/../mata-node"
fi

if [ -z "$src" ]; then
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    if ! git clone --quiet --depth 1 --branch "$ref" --filter=blob:none --sparse "$repo" "$tmp/mata-node"; then
        echo "Cannot clone $repo. Set MATA_NODE_DIR to a local mata-node checkout." >&2
        exit 1
    fi
    git -C "$tmp/mata-node" sparse-checkout set docs docker
    src="$tmp/mata-node"
fi

rm -rf "$target"
mkdir -p "$target"

# page in mata-node -> page in docs/node/
cp "$src/docs/configuration.md" "$target/configuration.md"
cp "$src/docs/cli.md" "$target/cli.md"
cp "$src/docs/log-parsers.md" "$target/log-parsers.md"
cp "$src/docs/shell-command-surface.md" "$target/shell-command-surface.md"
# docker/README.md links into ../docs/; those pages sit next to it here.
sed 's#](\.\./docs/#](#g' "$src/docker/README.md" > "$target/docker.md"

echo "Synced MATA Node docs from $src"
