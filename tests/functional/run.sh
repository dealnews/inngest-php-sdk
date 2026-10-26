#!/usr/bin/env bash
#
# Runs the functional tests against real Inngest servers in Docker.
#
# Usage:
#   tests/functional/run.sh [phpunit args...]   # up, test, down
#   KEEP=1 tests/functional/run.sh              # leave the stack running
#   tests/functional/run.sh --filter HelloWorld
#
# Needs Docker with the compose plugin. Run `composer install` first; the
# containers use the vendor/ directory from your checkout.

set -euo pipefail

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The self-hosted server's signing key (hex, without the signkey- prefix)
export FUNCTIONAL_SIGNING_KEY_HEX="${FUNCTIONAL_SIGNING_KEY_HEX:-\
3f1c8e2a9b7d4c6e5f0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6a}"

compose=(docker compose -f "$dir/docker-compose.yml")

cleanup() {
    if [[ "${KEEP:-}" == "" ]]; then
        "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1
    else
        echo "Stack left running (KEEP=1). Stop it with:"
        echo "  docker compose -f $dir/docker-compose.yml down --volumes"
    fi
}
trap cleanup EXIT

"${compose[@]}" up --detach --quiet-pull dev selfhosted app app-cloud
"${compose[@]}" run --rm phpunit "$@"
