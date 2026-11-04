#!/usr/bin/env bash
# Build from a clean local King commit, never uploading/pushing a repository.
set -euo pipefail
project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
king_source="${KING_SOURCE:-$(cd "$project_dir/.." && pwd)/king-ext}"
image_name="${IMAGE:-e-invoice-validator:local}"
if [[ ! -d "$king_source/.git" && ! -f "$king_source/.git" ]]; then
    echo 'KING_SOURCE must identify the local king-ext Git checkout.' >&2
    exit 1
fi
king_commit="$(git -C "$king_source" rev-parse HEAD)"
if ! git -C "$king_source" diff --quiet HEAD -- extension infra/scripts/bootstrap-saxonc.sh; then
    echo 'King source has uncommitted runtime changes. Commit them locally before building an image.' >&2
    exit 1
fi
source_dir="$(mktemp -d)"
trap 'rm -rf "$source_dir"' EXIT
git -C "$king_source" archive "$king_commit" LICENSE extension infra/scripts/bootstrap-saxonc.sh | tar -xf - -C "$source_dir"
# The additional context contains tracked King sources only: no .git, .env,
# credentials, model weights, shared objects, or local build output.
docker buildx build --load \
    --build-context "king_source=$source_dir" \
    --build-arg "KING_COMMIT=$king_commit" \
    --tag "$image_name" \
    --file "$project_dir/Dockerfile" \
    "$project_dir" "$@"
printf 'Built %s using local King %s\n' "$image_name" "$king_commit"
