# MCP Apps client (vendored)

`app-with-deps-2.0.4.js` is `dist/src/app-with-deps.js` of the npm package
`@modelcontextprotocol/ext-apps` 2.0.4, the official MCP Apps client of the
Model Context Protocol project, unchanged. `LICENSE` is the package's license.

- Package tarball integrity (npm): `sha512-B+PcbuGiaf0e5I3uy4iO5tS3Abo+5w8JvZFzHMzQdq9yVwP836zJkw2+KCGOQ0r4TM1fuQi4JdnbuWjkS4aBvg==`
- SHA-512 of `app-with-deps-2.0.4.js` (base64): `wbn1NozhekXAEbh+3h8/n1udATIrngdzSuQynu9d4EywxFfbiE5Q/Wh+GOf6WD88jzX9Nrb3dMg+HzVEDhMC1Q==`

`CakeDC\Mcp\App\AppBridge` inlines it in the `CakeDC/Mcp.app` layout, turning
its final `export{...}` into `globalThis.McpApps = {...}` at render time, so the
file stays as published and the app's script uses `McpApps.App`.

## Updating

1. Download the new version's tarball from npm and check its integrity against
   `npm view @modelcontextprotocol/ext-apps@<version> dist.integrity`.
2. Copy `package/dist/src/app-with-deps.js` here as `app-with-deps-<version>.js`,
   and `package/LICENSE` over `LICENSE`.
3. Update `AppBridge::FILE`, the two values above, and the version in
   `tests/TestCase/App/AppBridgeTest.php`.
4. Run the test suite: `AppBridgeTest` checks that the file still ends with the
   `export{...}` the bridge rewrites.
