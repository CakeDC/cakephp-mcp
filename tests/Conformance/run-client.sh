#!/usr/bin/env bash
#
# Runs the client side of the official MCP conformance suite against the plugin's MCP client (client.php). The
# suite starts its own scenario servers, so no Docker is needed: Node 22, PHP and the Composer dependencies.
#
#   tests/Conformance/run-client.sh                 both protocol revisions
#   tests/Conformance/run-client.sh 2026-07-28      one revision

set -uo pipefail

cd "$(dirname "$0")"

PACKAGE="${CONFORMANCE_PACKAGE:-@modelcontextprotocol/conformance@alpha}"
if [ $# -gt 0 ]; then
    SPECS=("$@")
else
    SPECS=(2025-11-25 2026-07-28)
fi

rm -rf results-client
status=0
for spec in "${SPECS[@]}"; do
    echo "== Conformance: client, ${spec} =="
    npx --yes "$PACKAGE" client \
        --command "php $(pwd)/client.php" \
        --suite all \
        --spec-version "$spec" \
        --expected-failures "baseline-client-${spec}.yml" \
        --output-dir "results-client/${spec}" || status=1
done

exit $status
