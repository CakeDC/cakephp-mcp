<?php
declare(strict_types=1);

namespace CakeDC\Mcp\TestSuite;

use Cake\Core\ContainerApplicationInterface;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Core\TestSuite\ContainerStubTrait;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\Routing\RoutingApplicationInterface;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Middleware\IdentityMiddleware;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\OAuth\ResourceServer;
use CakeDC\Mcp\Server\ServerFactory;
use Closure;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Authorization\AccessToken;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Calls the MCP tools of the application in memory, for `Cake\TestSuite\TestCase`.
 *
 * Every call builds the application (bootstrap, routes) and its container,
 * then the server the same way the HTTP endpoint does: discovery, constructor
 * injection and the configured authorizer. The identity comes from `mcpActingAs()` instead of
 * the HTTP authentication, which `IntegrationTestTrait` can cover. Services
 * can be replaced with `mockService()`.
 */
trait McpTestTrait
{
    use ContainerStubTrait;

    /**
     * The identity the next calls are made with. Null is an anonymous caller.
     */
    protected mixed $_mcpIdentity = null;

    /**
     * The scopes of the access token, null for 2.0 behaviour (the `identity.scopes` callable).
     *
     * @var list<string>|null
     */
    protected ?array $_mcpScopes = null;

    /**
     * Claims added to the access token.
     *
     * @var array<string, mixed>
     */
    protected array $_mcpClaims = [];

    /**
     * The JSON-RPC response to the last `mcpCallTool()`.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $_mcpResponse = null;

    /**
     * The name of the tool of the last `mcpCallTool()`.
     */
    protected ?string $_mcpTool = null;

    /**
     * What the server logged in the `mcp` scope during the last `mcpCallTool()`,
     * warnings and worse. Shown when an assertion fails.
     *
     * @var list<string>
     */
    protected array $_mcpLog = [];

    /**
     * Makes the next calls as this identity, in the shape your authentication
     * produces (an entity, an `ArrayObject`, an array...). Null makes them anonymous.
     *
     * With $scopes the access token carries them, and in the oauth and external modes the
     * `Mcp.auth.scopes` policy is applied first, as the HTTP endpoint would. In oauth mode the scopes
     * are first expanded with the `implies` of the resource declared in `OAuthServer.resources`, as the
     * authorization server does for the endpoint. A `tools/call` the policy refuses is checked with
     * `assertMcpInsufficientScope()`; any other refused request (the `tools/list` behind `mcpListTools()`
     * and the `assertMcpTool*Listed()` assertions) fails the test instead of returning the 403 answer.
     *
     * @param mixed $identity The identity.
     * @param list<string>|null $scopes The token's scopes; null keeps the `identity.scopes` callable.
     * @param array<string, mixed> $claims Claims added to the token (they win over `sub`).
     * @return void
     */
    public function mcpActingAs(mixed $identity, ?array $scopes = null, array $claims = []): void
    {
        $this->_mcpIdentity = $identity;
        $this->_mcpScopes = $scopes === null ? null : array_values(array_filter($scopes, 'is_string'));
        $this->_mcpClaims = $claims;
    }

    /**
     * Calls a tool and keeps the response for the `assertMcp*()` methods.
     *
     * @param string $name The tool name.
     * @param array<string, mixed> $arguments The tool arguments.
     * @return array<string, mixed> The JSON-RPC response.
     */
    public function mcpCallTool(string $name, array $arguments = []): array
    {
        $this->_mcpTool = $name;
        $this->_mcpResponse = $this->mcpRequest('tools/call', [
            'name' => $name,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ], log: $this->_mcpLog);

        return $this->_mcpResponse;
    }

    /**
     * The definitions of the tools the current identity may use.
     *
     * @return list<array<string, mixed>>
     */
    public function mcpListTools(): array
    {
        return $this->mcpToolDefinitions(false);
    }

    /**
     * @return array<string, mixed> The JSON-RPC response to the last `mcpCallTool()`.
     */
    public function mcpResponse(): array
    {
        if ($this->_mcpResponse === null) {
            Assert::fail('No tool was called yet. Call mcpCallTool() first.');
        }

        return $this->_mcpResponse;
    }

    /**
     * The text content of the last result (or tool error), one line per item.
     *
     * @return string
     */
    public function mcpResultText(): string
    {
        $content = $this->mcpResult()['content'] ?? [];
        $texts = [];
        foreach (is_array($content) ? $content : [] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $texts[] = $item['text'];
            }
        }

        return implode("\n", $texts);
    }

    /**
     * Asserts that the last tool call returned a result that is not a tool error.
     *
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolSucceeded(string $message = ''): void
    {
        $response = $this->mcpResponse();
        Assert::assertArrayHasKey(
            'result',
            $response,
            $message ?: 'The tool call failed: ' . $this->mcpJson($response) . $this->mcpLogText(),
        );
        Assert::assertNotTrue(
            $this->mcpResult()['isError'] ?? false,
            $message ?: 'The tool returned an error: ' . $this->mcpResultText() . $this->mcpLogText(),
        );
    }

    /**
     * Asserts that the last tool call returned a tool error, the kind the model
     * reads (`ToolCallException`, `ToolError::invalid()`).
     *
     * @param string|null $contains Text the error message must contain.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolError(?string $contains = null, string $message = ''): void
    {
        Assert::assertTrue(
            ($this->mcpResult()['isError'] ?? false) === true,
            $message ?: 'Expected a tool error, got: ' . $this->mcpJson($this->mcpResponse()) . $this->mcpLogText(),
        );
        if ($contains !== null) {
            Assert::assertStringContainsString($contains, $this->mcpResultText(), $message);
        }
    }

    /**
     * Asserts that the last tool call was answered with a JSON-RPC error: invalid
     * arguments (-32602), an unexpected exception (-32603), an unknown or denied tool.
     *
     * @param int|null $code The expected error code.
     * @param string|null $contains Text the error message must contain.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpJsonRpcError(?int $code = null, ?string $contains = null, string $message = ''): void
    {
        $response = $this->mcpResponse();
        $error = $response['error'] ?? null;
        if (!is_array($error)) {
            Assert::fail(
                $message ?: 'Expected a JSON-RPC error, got: ' . $this->mcpJson($response) . $this->mcpLogText(),
            );
        }
        if ($code !== null) {
            Assert::assertSame($code, $error['code'] ?? null, $message ?: 'Unexpected JSON-RPC error code.');
        }
        if ($contains !== null) {
            Assert::assertStringContainsString($contains, (string)($error['message'] ?? ''), $message);
        }
    }

    /**
     * Asserts that the authorizer rejected the last tool call. A denied tool is
     * answered exactly like an unknown one, so this also checks that the tool
     * exists; otherwise a typo in the name would pass as a denial.
     *
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolDenied(string $message = ''): void
    {
        $this->mcpResponse();
        $tool = (string)$this->_mcpTool;
        $this->assertMcpJsonRpcError(-32602, sprintf('Tool not found: "%s".', $tool), $message);
        Assert::assertContains(
            $tool,
            array_column($this->mcpToolDefinitions(true), 'name'),
            $message ?: sprintf('Tool "%s" is not registered, so its rejection proves nothing. Check the name.', $tool),
        );
    }

    /**
     * Asserts the last call was refused because the access token lacks scopes, as the HTTP endpoint
     * answers with 403 `insufficient_scope` (only with scopes given to `mcpActingAs()`, in the oauth and
     * external modes). Only tool calls get this answer: a refused `tools/list` fails the test right away.
     *
     * @param list<string>|null $scopes The scopes the call needs, any when null.
     * @param string $message Shown on failure.
     * @return void
     */
    public function assertMcpInsufficientScope(?array $scopes = null, string $message = ''): void
    {
        $response = $this->mcpResponse();
        $error = $response['error'] ?? null;
        Assert::assertTrue(
            is_array($error) && ($error['message'] ?? null) === 'insufficient_scope',
            $message ?: 'Expected the call to be refused for missing scopes, got: ' . $this->mcpJson($response),
        );
        if ($scopes !== null) {
            Assert::assertEqualsCanonicalizing(
                $scopes,
                $error['data']['scopes'] ?? null,
                $message,
            );
        }
    }

    /**
     * Asserts that the text of the last result contains a string.
     *
     * @param string $text The expected text.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResultContains(string $text, string $message = ''): void
    {
        Assert::assertStringContainsString($text, $this->mcpResultText(), $message);
    }

    /**
     * Asserts the `structuredContent` of the last result. Key order does not
     * matter, value types do.
     *
     * @param array<mixed> $expected The expected structured content.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpStructuredContent(array $expected, string $message = ''): void
    {
        Assert::assertSame(
            $this->mcpSortKeys($expected),
            $this->mcpSortKeys($this->mcpResult()['structuredContent'] ?? null),
            $message,
        );
    }

    /**
     * Asserts that the current identity sees a tool in `tools/list`.
     *
     * @param string $name The tool name.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolListed(string $name, string $message = ''): void
    {
        Assert::assertContains(
            $name,
            array_column($this->mcpListTools(), 'name'),
            $message ?: sprintf('Tool "%s" is not listed for the current identity.', $name),
        );
    }

    /**
     * Asserts that the current identity does not see a tool in `tools/list`. It
     * also checks that the tool exists, so a typo cannot pass as hidden.
     *
     * @param string $name The tool name.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolNotListed(string $name, string $message = ''): void
    {
        Assert::assertNotContains(
            $name,
            array_column($this->mcpListTools(), 'name'),
            $message ?: sprintf('Tool "%s" is listed for the current identity.', $name),
        );
        Assert::assertContains(
            $name,
            array_column($this->mcpToolDefinitions(true), 'name'),
            $message ?: sprintf('Tool "%s" is not registered, so its absence proves nothing. Check the name.', $name),
        );
    }

    /**
     * The container the server is built from: the application's, after its
     * bootstrap, with the services replaced through `mockService()`.
     *
     * @return \Cake\Core\ContainerInterface
     */
    protected function mcpContainer(): ContainerInterface
    {
        $app = $this->createApp();
        $app->bootstrap();
        if ($app instanceof PluginApplicationInterface) {
            $app->pluginBootstrap();
        }
        // createApp() resets the routes. Load them as the HTTP and console runners do,
        // so tools that build URLs work as in production.
        if ($app instanceof RoutingApplicationInterface) {
            $builder = Router::createRouteBuilder('/');
            $app->routes($builder);
            if ($app instanceof PluginApplicationInterface) {
                $app->pluginRoutes($builder);
            }
        }
        if (!$app instanceof ContainerApplicationInterface) {
            Assert::fail(sprintf('`%s` has no container to build the MCP server from.', $app::class));
        }

        return $app->getContainer();
    }

    /**
     * Sends one request after the initialize handshake.
     *
     * @param string $method The JSON-RPC method.
     * @param array<string, mixed>|null $params Its params.
     * @param bool $trusted Skip the authorizer.
     * @param list<string> $log Receives what the server logged, warnings and worse.
     * @return array<string, mixed>
     */
    private function mcpRequest(string $method, ?array $params, bool $trusted = false, array &$log = []): array
    {
        $request = ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method];
        if ($params !== null) {
            $request['params'] = $params;
        }

        $factory = $this->mcpContainer()->get(ServerFactory::class);
        if (!$factory instanceof ServerFactory) {
            Assert::fail('The container has no MCP ServerFactory. Is the CakeDC/Mcp plugin loaded?');
        }
        $factory->identity()->set($this->_mcpIdentity);

        $scopes = McpConfig::get('identity.scopes');
        $token = null;
        if ($this->_mcpIdentity !== null) {
            $token = IdentityMiddleware::accessTokenFor(
                $this->_mcpIdentity,
                is_callable($scopes) ? Closure::fromCallable($scopes) : null,
            );
            if ($this->_mcpScopes !== null || $this->_mcpClaims !== []) {
                $token = new AccessToken(
                    $this->_mcpScopes ?? $token->getScopes(),
                    $this->_mcpClaims + $token->getClaims(),
                );
            }
        }
        if ($token !== null && $this->_mcpScopes !== null && !$trusted) {
            $settings = AuthSettings::fromConfig();
            $policy = $settings->mode === AuthSettings::HOST ? null : $settings->scopePolicy();
            if ($policy !== null) {
                $required = $policy->requiredFor($request);
                // In oauth mode the authorization server expands the token's scopes with the `implies` of
                // the declared resource before the policy sees them; the policy then adds its own `implies`.
                $granted = $policy->expand((new ResourceServer($settings))->grantedScopes($token->getScopes()));
                if (array_diff($required, $granted) !== []) {
                    if ($method !== 'tools/call') {
                        Assert::fail(sprintf(
                            'The access token lacks scopes for `%s`: it needs %s.',
                            $method,
                            implode(' ', $required),
                        ));
                    }

                    // What the endpoint answers with HTTP 403 and `insufficient_scope`.
                    return ['jsonrpc' => '2.0', 'id' => 2, 'error' => [
                        'code' => 403,
                        'message' => 'insufficient_scope',
                        'data' => ['scopes' => $required],
                    ]];
                }
                $token = new AccessToken($granted, $token->getClaims());
            }
        }
        $transport = new RecordingTransport(
            [
                (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'protocolVersion' => ProtocolVersion::latestHandshake()->value,
                    'capabilities' => new stdClass(),
                    'clientInfo' => ['name' => 'McpTestTrait', 'version' => '1.0'],
                ]]),
                (string)json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
                (string)json_encode($request),
            ],
            $token,
        );
        // The real exception of a failing tool only reaches the log, rarely configured in tests.
        $logConfig = '_mcp_test_trait';
        Log::setConfig($logConfig, [
            'className' => ArrayLog::class,
            'scopes' => [McpLogger::SCOPE],
            'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
        ]);
        try {
            $factory->create($trusted)->run($transport);
        } finally {
            $engine = Log::engine($logConfig);
            $log = $engine instanceof ArrayLog ? array_values(array_map('strval', $engine->read())) : [];
            Log::drop($logConfig);
        }

        $response = $transport->responseFor(2);
        if ($response === null) {
            Assert::fail(sprintf('The server sent no response to `%s`.', $method));
        }

        return $response;
    }

    /**
     * @param bool $trusted Skip the authorizer, to see every registered tool.
     * @return list<array<string, mixed>>
     */
    private function mcpToolDefinitions(bool $trusted): array
    {
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < 100; $page++) {
            $response = $this->mcpRequest('tools/list', $cursor === null ? null : ['cursor' => $cursor], $trusted);
            $result = $response['result'] ?? null;
            if (!is_array($result) || !is_array($result['tools'] ?? null)) {
                Assert::fail('tools/list failed: ' . $this->mcpJson($response));
            }
            foreach ($result['tools'] as $tool) {
                if (is_array($tool)) {
                    $tools[] = $tool;
                }
            }
            $cursor = $result['nextCursor'] ?? null;
            if (!is_string($cursor)) {
                break;
            }
        }

        return $tools;
    }

    /**
     * @return array<string, mixed> The `result` of the last tool call, or an empty array.
     */
    private function mcpResult(): array
    {
        $result = $this->mcpResponse()['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * @param mixed $value A decoded JSON value.
     * @return mixed The value with the keys of its objects sorted, recursively.
     */
    private function mcpSortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map($this->mcpSortKeys(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return string The server log of the last call, for failure messages, or an empty string.
     */
    private function mcpLogText(): string
    {
        return $this->_mcpLog === [] ? '' : "\nServer log:\n" . implode("\n", $this->_mcpLog);
    }

    /**
     * @param array<mixed> $value A decoded JSON value.
     * @return string
     */
    private function mcpJson(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
