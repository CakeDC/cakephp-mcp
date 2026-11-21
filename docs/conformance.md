# Protocol conformance

The plugin is checked against the official
[MCP conformance suite](https://github.com/modelcontextprotocol/conformance),
the same one the SDK runs. The SDK already passes it on its own; running it
again through the plugin checks what the plugin adds on top: the Cake route and
controller, the request and response conversion, sessions in Cake cache,
the identity middleware and the DNS-rebinding protection.

The endpoint runs the way a real deployment does, with nginx in front of
php-fpm, so server-sent events also go through nginx.

## Running it

You need Docker, Node.js 22 and the Composer dependencies installed:

```bash
composer install
tests/Conformance/run.sh              # both protocol revisions
tests/Conformance/run.sh 2026-07-28   # one revision
```

`CONFORMANCE_PORT` changes the local port (8000 by default) and
`CONFORMANCE_PACKAGE` the suite version. By default the script runs the newest
published alpha of the suite (`@modelcontextprotocol/conformance@alpha`), so the
plugin is always checked against the latest scenarios: a new release that adds a
scenario the plugin fails turns the build red on purpose. Set
`CONFORMANCE_PACKAGE` to a fixed version to reproduce an older run.

The CI workflow runs the suite on pushes to the main branches and on every
pull request.

The container uses the PHP version of the host that runs the script (8.4 in
CI), because it runs the `vendor/` directory installed there. Set
`CONFORMANCE_PHP` to use another one.

## The client side

`tests/Conformance/run-client.sh` runs the client scenarios of the same suite
against the plugin's MCP client: the suite starts its own scenario servers
(and authorization servers) on this machine and runs
`php tests/Conformance/client.php <server-url>`, which uses `McpClients` the
way an application does. It needs Node.js 22, PHP and the Composer
dependencies, not Docker:

```bash
tests/Conformance/run-client.sh              # both protocol revisions
tests/Conformance/run-client.sh 2026-07-28   # one revision
```

For the sign-in scenarios the script runs the user mode sign-in without a
browser (the suite's authorization server approves at once), with an
in-memory token store, and with `allowPrivateNetwork` and `allowInsecure` on
the client, because those servers are on `localhost` over plain http; an
application would not set them for a server on the internet.

`baseline-client-<revision>.yml` lists the client scenarios expected to fail,
with the reason: elicitation and the input requests of an
`InputRequiredResult`, which the client does not answer; the Client ID
Metadata Document scenario, which expects a client id the suite names; a scope
step-up server that challenges only after the client's probe; and two
behaviours of the SDK itself (the version retry after an
`UnsupportedProtocolVersion` error, and the `Mcp-Param` header of an empty
string). Results go to `tests/Conformance/results-client/`. The CI workflow
runs it in the same job as the server side.

## How it is set up

- `webroot/index.php` serves the test application with the plugin's `/mcp`
  endpoint, open to anonymous callers, without discovery.
- `ConformanceFixture` registers, through `Mcp.server.configure`, the same
  tools, resources and prompts as the SDK's own conformance server. They live in
  `Sdk/`, copied from the SDK with their license.
- `baseline-<revision>.yml` lists the scenarios expected to fail at each
  revision, with the reason. Both are empty: every scenario passes.

Results are written to `tests/Conformance/results/` and the plugin log to
`tests/Conformance/tmp/logs/mcp.log`.
