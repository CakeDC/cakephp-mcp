#!/usr/bin/env bash
#
# Runs the official MCP conformance suite against the plugin's /mcp endpoint,
# served by nginx and php-fpm in Docker. Needs Docker, Node 22 and the
# Composer dependencies installed.
#
#   tests/Conformance/run.sh                 both protocol revisions
#   tests/Conformance/run.sh 2026-07-28      one revision

set -uo pipefail

cd "$(dirname "$0")"

PACKAGE="${CONFORMANCE_PACKAGE:-@modelcontextprotocol/conformance@0.2.0-alpha.11}"
export CONFORMANCE_PORT="${CONFORMANCE_PORT:-8000}"
URL="http://localhost:${CONFORMANCE_PORT}/mcp"
if [ $# -gt 0 ]; then
    SPECS=("$@")
else
    SPECS=(2025-11-25 2026-07-28)
fi

rm -rf results tmp
mkdir -p tmp/cache tmp/logs
chmod -R 777 tmp

docker compose up -d --build --quiet-pull || exit 1
trap 'docker compose down >/dev/null 2>&1' EXIT

for _ in $(seq 1 60); do
    if curl -s -o /dev/null "$URL"; then
        break
    fi
    sleep 1
done

status=0
for spec in "${SPECS[@]}"; do
    echo "== Conformance: server, ${spec} =="
    npx --yes "$PACKAGE" server \
        --url "$URL" \
        --suite all \
        --spec-version "$spec" \
        --expected-failures "baseline-${spec}.yml" \
        --output-dir "results/${spec}" || status=1
done

if [ $status -ne 0 ]; then
    echo "== Plugin log =="
    cat tmp/logs/*.log 2>/dev/null || echo "(empty)"
    echo "== Containers =="
    docker compose logs --tail 100
fi

exit $status
