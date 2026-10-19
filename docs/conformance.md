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
