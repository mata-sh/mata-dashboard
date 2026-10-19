#!/bin/sh
# Prints the release from git tags: 0.8.0 on a tag, 0.8.0-60-g7a5d2fb past it.
# The one place that derives it: make stamps it into images, bin/mata runs it in checkouts.
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

# safe.directory: the web server user runs this in a checkout it does not own.
exec git -c safe.directory="$root" -C "$root" describe --tags --match '[0-9]*' --always 2>/dev/null
