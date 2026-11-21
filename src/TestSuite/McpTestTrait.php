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
use CakeDC\Mcp\Catalog\CatalogScopes;
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
     * The MCP server the requests go to (`Mcp.servers`).
     */
    protected string $_mcpServer = McpConfig::DEFAULT_SERVER;

    /**
     * The notifications the server sent during the last `mcpCallTool()`.
     *
     * @var list<array<string, mixed>>
     */
    protected array $_mcpNotifications = [];

    /**
     * The notifications of the last request, whatever it was.
     *
     * @var list<array<string, mixed>>
     */
    protected array $_mcpLastNotifications = [];

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
     * The prompt name of the last `mcpGetPrompt()`.
     */
    protected ?string $_mcpPrompt = null;

    /**
     * The URI of the last `mcpReadResource()`.
     */
    protected ?string $_mcpResource = null;

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
     * Sends the following requests to another MCP server: `default` or a key of `Mcp.servers`.
     *
     * @param string $server The server.
     * @return void
     */
    public function mcpUseServer(string $server): void
    {
        $this->_mcpServer = $server;
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
            // As clients that show progress do: the tool's progress notifications are then sent and recorded.
            '_meta' => ['progressToken' => 'mcp-test'],
        ], log: $this->_mcpLog);
        $this->_mcpNotifications = $this->_mcpLastNotifications;

        return $this->_mcpResponse;
    }

    /**
     * Gets a prompt as the current identity, and keeps the response for `assertMcpPrompt*()`.
     *
     * @param string $name The prompt name.
     * @param array<string, mixed> $arguments Its arguments.
     * @return array<string, mixed> The JSON-RPC response.
     */
    public function mcpGetPrompt(string $name, array $arguments = []): array
    {
        $this->_mcpPrompt = $name;
        $params = ['name' => $name];
        if ($arguments !== []) {
            $params['arguments'] = $arguments;
        }
        $this->_mcpResponse = $this->mcpRequest('prompts/get', $params, log: $this->_mcpLog);

        return $this->_mcpResponse;
    }

    /**
     * Reads a resource as the current identity (a concrete URI, also one a template matches), and keeps the
     * response for `assertMcpResource*()`.
     *
     * @param string $uri The resource URI.
     * @return array<string, mixed> The JSON-RPC response.
     */
    public function mcpReadResource(string $uri): array
    {
        $this->_mcpResource = $uri;
        $this->_mcpResponse = $this->mcpRequest('resources/read', ['uri' => $uri], log: $this->_mcpLog);

        return $this->_mcpResponse;
    }

    /**
     * Asks for the completions of an argument of a prompt (`['type' => 'ref/prompt', 'name' => ...]`) or of a
     * variable of a resource template (`['type' => 'ref/resource', 'uri' => 'orders://{number}']`), and keeps
     * the response for `assertMcpCompletionValues()`.
     *
     * @param array<string, string> $ref The reference.
     * @param string $argument The argument or variable name.
     * @param string $value What the user typed so far.
     * @return array<string, mixed> The JSON-RPC response.
     */
    public function mcpComplete(array $ref, string $argument, string $value): array
    {
        $this->_mcpResponse = $this->mcpRequest('completion/complete', [
            'ref' => $ref,
            'argument' => ['name' => $argument, 'value' => $value],
        ], log: $this->_mcpLog);

        return $this->_mcpResponse;
    }

    /**
     * The resources the current identity sees in `resources/list`.
     *
     * @return list<array<string, mixed>>
     */
    public function mcpListResources(): array
    {
        return $this->mcpListAll('resources/list', 'resources', false);
    }

    /**
     * The resource templates the current identity sees in `resources/templates/list`.
     *
     * @return list<array<string, mixed>>
     */
    public function mcpListResourceTemplates(): array
    {
        return $this->mcpListAll('resources/templates/list', 'resourceTemplates', false);
    }

    /**
     * The prompts the current identity sees in `prompts/list`.
     *
     * @return list<array<string, mixed>>
     */
    public function mcpListPrompts(): array
    {
        return $this->mcpListAll('prompts/list', 'prompts', false);
    }

    /**
     * Asserts the messages of the last `mcpGetPrompt()`: one `['role' => ..., 'text' => ...]` per message, in order.
     * A message whose content is not text has `text` null.
     *
     * @param list<array{role: string, text: string|null}> $expected The expected messages.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpPromptMessages(array $expected, string $message = ''): void
    {
        Assert::assertSame(
            $expected,
            $this->mcpPromptMessages(),
            $message ?: 'Unexpected prompt messages. Response: ' . $this->mcpJson($this->mcpResponse()),
        );
    }

    /**
     * Asserts that a text message of the last `mcpGetPrompt()` contains $text.
     *
     * @param string $text The expected text.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpPromptContains(string $text, string $message = ''): void
    {
        Assert::assertStringContainsString(
            $text,
            implode("\n", array_filter(array_column($this->mcpPromptMessages(), 'text'), 'is_string')),
            $message,
        );
    }

    /**
     * Asserts that the last `mcpGetPrompt()` was answered with a JSON-RPC error (an unknown prompt, missing
     * arguments, a prompt that failed).
     *
     * @param string|null $contains Text the error message must contain.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpPromptError(?string $contains = null, string $message = ''): void
    {
        $this->assertMcpJsonRpcError(null, $contains, $message);
    }

    /**
     * Asserts that the last `mcpReadResource()` returned a content with this exact text, and with $mimeType
     * when given.
     *
     * @param string $expected The expected text.
     * @param string|null $mimeType The expected MIME type of that content.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceText(string $expected, ?string $mimeType = null, string $message = ''): void
    {
        $contents = $this->mcpResourceContents();
        $matching = array_filter(
            $contents,
            fn(array $content): bool => ($content['text'] ?? null) === $expected
                && ($mimeType === null || ($content['mimeType'] ?? null) === $mimeType),
        );
        Assert::assertNotSame([], $matching, $message ?: sprintf(
            'No content of the resource has the text %s%s. Contents: %s',
            json_encode($expected),
            $mimeType === null ? '' : ' with MIME type ' . $mimeType,
            $this->mcpJson($contents),
        ));
    }

    /**
     * Asserts that a text content of the last `mcpReadResource()` contains $text.
     *
     * @param string $text The expected text.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceContains(string $text, string $message = ''): void
    {
        Assert::assertStringContainsString(
            $text,
            implode("\n", array_filter(array_column($this->mcpResourceContents(), 'text'), 'is_string')),
            $message,
        );
    }

    /**
     * Asserts that the last `mcpReadResource()` was answered with a JSON-RPC error (an unknown URI, a resource
     * that failed).
     *
     * @param string|null $contains Text the error message must contain.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceError(?string $contains = null, string $message = ''): void
    {
        $this->assertMcpJsonRpcError(null, $contains, $message);
    }

    /**
     * Asserts that the authorizer denied the last `mcpReadResource()`: it was answered exactly like an unknown URI,
     * and the URI exists (a resource, or one a template matches), so a typo cannot pass as a denial.
     *
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceDenied(string $message = ''): void
    {
        $uri = (string)$this->_mcpResource;
        $error = $this->mcpResponse()['error'] ?? null;
        Assert::assertTrue(
            is_array($error) && ($error['message'] ?? null) === sprintf('Resource not found for uri: "%s".', $uri),
            $message ?: sprintf(
                'Expected "%s" to be answered like an unknown URI, got: %s',
                $uri,
                $this->mcpJson($this->mcpResponse()),
            ),
        );
        $response = $this->_mcpResponse;
        try {
            $read = $this->mcpRequest('resources/read', ['uri' => $uri], true);
        } finally {
            $this->_mcpResponse = $response;
        }
        Assert::assertArrayNotHasKey(
            'error',
            $read,
            $message
                ?: sprintf('Resource "%s" is not registered, so its rejection proves nothing. Check the URI.', $uri),
        );
    }

    /**
     * Asserts that the authorizer denied the last `mcpGetPrompt()`: it was answered exactly like an unknown prompt,
     * and the prompt exists, so a typo cannot pass as a denial.
     *
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpPromptDenied(string $message = ''): void
    {
        $name = (string)$this->_mcpPrompt;
        $error = $this->mcpResponse()['error'] ?? null;
        Assert::assertTrue(
            is_array($error) && ($error['message'] ?? null) === sprintf('Prompt not found: "%s".', $name),
            $message ?: sprintf(
                'Expected "%s" to be answered like an unknown prompt, got: %s',
                $name,
                $this->mcpJson($this->mcpResponse()),
            ),
        );
        Assert::assertContains(
            $name,
            array_column($this->mcpListAll('prompts/list', 'prompts', true), 'name'),
            $message
                ?: sprintf('Prompt "%s" is not registered, so its rejection proves nothing. Check the name.', $name),
        );
    }

    /**
     * Asserts the values of the last `mcpComplete()`, in order.
     *
     * @param list<string> $expected The expected values.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpCompletionValues(array $expected, string $message = ''): void
    {
        $response = $this->mcpResponse();
        $values = $response['result']['completion']['values'] ?? null;
        if (!is_array($values)) {
            Assert::fail($message ?: 'The completion failed: ' . $this->mcpJson($response) . $this->mcpLogText());
        }
        Assert::assertSame($expected, $values, $message);
    }

    /**
     * Asserts that the current identity sees a resource in `resources/list`.
     *
     * @param string $uri The resource URI.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceListed(string $uri, string $message = ''): void
    {
        Assert::assertContains(
            $uri,
            array_column($this->mcpListResources(), 'uri'),
            $message ?: sprintf('Resource "%s" is not listed for the current identity.', $uri),
        );
    }

    /**
     * Asserts that the current identity does not see a resource in `resources/list`. It also checks that the
     * resource exists, so a typo cannot pass as hidden.
     *
     * @param string $uri The resource URI.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceNotListed(string $uri, string $message = ''): void
    {
        $this->mcpAssertHidden('resources/list', 'resources', 'uri', $uri, 'Resource', $message);
    }

    /**
     * Asserts that the current identity sees a resource template in `resources/templates/list`.
     *
     * @param string $uriTemplate The URI template, `orders://{number}`.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceTemplateListed(string $uriTemplate, string $message = ''): void
    {
        Assert::assertContains(
            $uriTemplate,
            array_column($this->mcpListResourceTemplates(), 'uriTemplate'),
            $message
                ?: sprintf('Resource template "%s" is not listed for the current identity.', $uriTemplate),
        );
    }

    /**
     * Asserts that the current identity does not see a resource template in `resources/templates/list`, and
     * that it exists.
     *
     * @param string $uriTemplate The URI template.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpResourceTemplateNotListed(string $uriTemplate, string $message = ''): void
    {
        $this->mcpAssertHidden(
            'resources/templates/list',
            'resourceTemplates',
            'uriTemplate',
            $uriTemplate,
            'Resource template',
            $message,
        );
    }

    /**
     * Asserts that the current identity sees a prompt in `prompts/list`.
     *
     * @param string $name The prompt name.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpPromptListed(string $name, string $message = ''): void
    {
        Assert::assertContains(
            $name,
            array_column($this->mcpListPrompts(), 'name'),
            $message ?: sprintf('Prompt "%s" is not listed for the current identity.', $name),
        );
    }

    /**
     * Asserts that the current identity does not see a prompt in `prompts/list`, and that it exists.
     *
     * @param string $name The prompt name.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpPromptNotListed(string $name, string $message = ''): void
    {
        $this->mcpAssertHidden('prompts/list', 'prompts', 'name', $name, 'Prompt', $message);
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
     * @return array<string, mixed> The JSON-RPC response to the last `mcpCallTool()`, `mcpGetPrompt()`,
     *   `mcpReadResource()` or `mcpComplete()`.
     */
    public function mcpResponse(): array
    {
        if ($this->_mcpResponse === null) {
            Assert::fail(
                'No request was sent yet. Call mcpCallTool(), mcpGetPrompt(), mcpReadResource() or mcpComplete().',
            );
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
            $this->mcpRegisteredToolNames(),
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
     * Asserts one value of the last result's `structuredContent`, found by a dot path
     * (`order.lines.0.sku`). Value types matter. Fails when the path does not exist.
     *
     * @param string $path Dot path into the structured content.
     * @param mixed $expected The expected value.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpStructuredContentPath(string $path, mixed $expected, string $message = ''): void
    {
        [$found, $value] = $this->mcpStructuredContentAt($path);
        if (!$found) {
            Assert::fail($message ?: sprintf('The structuredContent has no "%s".', $path));
        }
        Assert::assertSame($expected, $value, $message);
    }

    /**
     * Asserts that a dot path exists in the last result's `structuredContent`, even with a `null` value.
     *
     * @param string $path Dot path into the structured content.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpStructuredContentHas(string $path, string $message = ''): void
    {
        Assert::assertTrue(
            $this->mcpStructuredContentAt($path)[0],
            $message ?: sprintf('The structuredContent has no "%s".', $path),
        );
    }

    /**
     * Asserts that another identity sees a tool in `tools/list`, with default scopes and no claims. The test's own
     * identity, scopes and claims stay as they were.
     *
     * @param mixed $identity The identity to list the tools for.
     * @param string $name The tool name.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolListedFor(mixed $identity, string $name, string $message = ''): void
    {
        $this->mcpAs($identity, fn() => $this->assertMcpToolListed(
            $name,
            $message ?: sprintf('Tool "%s" is not listed for that identity.', $name),
        ));
    }

    /**
     * Asserts that another identity does not see a tool in `tools/list` (and that the tool exists), with default
     * scopes and no claims. The test's own identity, scopes and claims stay as they were.
     *
     * @param mixed $identity The identity to list the tools for.
     * @param string $name The tool name.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpToolNotListedFor(mixed $identity, string $name, string $message = ''): void
    {
        $this->mcpAs($identity, fn() => $this->assertMcpToolNotListed($name, $message));
    }

    /**
     * Runs $assertion as $identity, then puts back the test's identity, scopes and claims.
     *
     * @param mixed $identity The identity.
     * @param callable(): void $assertion The assertion.
     * @return void
     */
    private function mcpAs(mixed $identity, callable $assertion): void
    {
        $saved = [$this->_mcpIdentity, $this->_mcpScopes, $this->_mcpClaims];
        $this->mcpActingAs($identity);
        try {
            $assertion();
        } finally {
            [$this->_mcpIdentity, $this->_mcpScopes, $this->_mcpClaims] = $saved;
        }
    }

    /**
     * @param string $path Dot path into the last result's structured content.
     * @return array{0: bool, 1: mixed} Whether the path exists, and its value.
     */
    private function mcpStructuredContentAt(string $path): array
    {
        $value = $this->mcpResult()['structuredContent'] ?? null;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }

        return [true, $value];
    }

    /**
     * The notifications the server sent during the last `mcpCallTool()`, all of them or those of one method
     * (`notifications/progress`, `notifications/message`...).
     *
     * @param string|null $method A notification method, or null for all.
     * @return list<array<string, mixed>>
     */
    public function mcpNotifications(?string $method = null): array
    {
        return array_values(array_filter(
            $this->_mcpNotifications,
            fn(array $notification): bool => $method === null || ($notification['method'] ?? null) === $method,
        ));
    }

    /**
     * Asserts that the last call sent a notification of $method.
     *
     * @param string $method The notification method, for example `notifications/message`.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpNotificationSent(string $method, string $message = ''): void
    {
        Assert::assertNotEmpty(
            $this->mcpNotifications($method),
            $message ?: sprintf('The last call sent no %s notification.', $method),
        );
    }

    /**
     * Asserts that the last call reported progress: at least once, or exactly $count times.
     *
     * @param int|null $count The expected number of progress notifications, or null for at least one.
     * @param string $message The failure message.
     * @return void
     */
    public function assertMcpProgressSent(?int $count = null, string $message = ''): void
    {
        $sent = count($this->mcpNotifications('notifications/progress'));
        if ($count === null) {
            Assert::assertGreaterThan(0, $sent, $message ?: 'The last call reported no progress.');

            return;
        }
        Assert::assertSame($count, $sent, $message ?: sprintf('The last call reported progress %d times.', $sent));
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
            $this->mcpRegisteredToolNames(),
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
        $factory = $factory->forServer($this->_mcpServer);
        $factory->identity()->set($this->_mcpIdentity);

        $scopes = McpConfig::get('identity.scopes', $this->_mcpServer);
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
            $settings = AuthSettings::fromConfig($this->_mcpServer);
            $policy = $settings->mode === AuthSettings::HOST ? null : $settings->scopePolicy();
            if ($policy !== null) {
                $catalog = McpConfig::get('catalog.enabled', $this->_mcpServer) === true
                    ? array_values(array_filter((array)McpConfig::get('catalog.tools', $this->_mcpServer), 'is_string'))
                    : [];
                // call_tools needs the scopes of the catalogued tools it runs, as the endpoint requires.
                $required = CatalogScopes::required($policy, $request, $catalog);
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
                // So the log notifications of a tool are sent and recorded.
                (string)json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 'mcp-test-log-level',
                    'method' => 'logging/setLevel',
                    'params' => ['level' => 'debug'],
                ]),
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

        $this->_mcpLastNotifications = array_values(array_filter(
            $transport->sent,
            fn(array $message): bool => !array_key_exists('id', $message) && isset($message['method']),
        ));
        $response = $transport->responseFor(2);
        if ($response === null) {
            Assert::fail($transport->waitsOnClient()
                ? sprintf(
                    'The tool asked the client something (sampling or elicitation), which McpTestTrait cannot '
                    . 'answer, so `%s` got no response.',
                    $method,
                )
                : sprintf('The server sent no response to `%s`.', $method));
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
     * Every page of a list method.
     *
     * @param string $method `resources/list`, `resources/templates/list` or `prompts/list`.
     * @param string $key The key of the items in the result.
     * @param bool $trusted Skip the authorizer, to see everything registered.
     * @return list<array<string, mixed>>
     */
    private function mcpListAll(string $method, string $key, bool $trusted): array
    {
        $items = [];
        $cursor = null;
        for ($page = 0; $page < 100; $page++) {
            $response = $this->mcpRequest($method, $cursor === null ? null : ['cursor' => $cursor], $trusted);
            // A server with nothing of that kind does not announce the capability, and answers "method not found".
            if (($response['error']['code'] ?? null) === -32601) {
                return [];
            }
            $result = $response['result'] ?? null;
            if (!is_array($result) || !is_array($result[$key] ?? null)) {
                Assert::fail($method . ' failed: ' . $this->mcpJson($response));
            }
            foreach ($result[$key] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $cursor = $result['nextCursor'] ?? null;
            if (!is_string($cursor)) {
                break;
            }
        }

        return $items;
    }

    /**
     * Asserts that $value is not in what the current identity lists, and is in what is registered.
     *
     * @param string $method The list method.
     * @param string $key The key of the items in the result.
     * @param string $field The item field compared with $value.
     * @param string $value The URI, URI template or name.
     * @param string $what `Resource`, `Resource template` or `Prompt`, for the messages.
     * @param string $message The failure message.
     * @return void
     */
    private function mcpAssertHidden(
        string $method,
        string $key,
        string $field,
        string $value,
        string $what,
        string $message,
    ): void {
        Assert::assertNotContains(
            $value,
            array_column($this->mcpListAll($method, $key, false), $field),
            $message ?: sprintf('%s "%s" is listed for the current identity.', $what, $value),
        );
        Assert::assertContains(
            $value,
            array_column($this->mcpListAll($method, $key, true), $field),
            $message ?: sprintf(
                '%s "%s" is not registered, so its absence proves nothing. Check the name.',
                $what,
                $value,
            ),
        );
    }

    /**
     * @return list<array{role: string, text: string|null}> The messages of the last `mcpGetPrompt()`.
     */
    private function mcpPromptMessages(): array
    {
        $response = $this->mcpResponse();
        $messages = $response['result']['messages'] ?? null;
        if (!is_array($messages)) {
            Assert::fail('The prompt failed: ' . $this->mcpJson($response) . $this->mcpLogText());
        }
        $found = [];
        foreach ($messages as $item) {
            $content = is_array($item) ? ($item['content'] ?? null) : null;
            $found[] = [
                'role' => is_array($item) ? (string)($item['role'] ?? '') : '',
                'text' => is_array($content) && ($content['type'] ?? null) === 'text'
                    && is_string($content['text'] ?? null) ? $content['text'] : null,
            ];
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>> The contents of the last `mcpReadResource()`.
     */
    private function mcpResourceContents(): array
    {
        $response = $this->mcpResponse();
        $contents = $response['result']['contents'] ?? null;
        if (!is_array($contents)) {
            Assert::fail('The resource read failed: ' . $this->mcpJson($response) . $this->mcpLogText());
        }

        return array_values(array_filter($contents, 'is_array'));
    }

    /**
     * Every tool registered on the server, including those `tools/list` leaves out (catalogued and app-only
     * tools), as `bin/cake mcp check` sees them.
     *
     * @return list<string>
     */
    private function mcpRegisteredToolNames(): array
    {
        $factory = $this->mcpContainer()->get(ServerFactory::class);
        if (!$factory instanceof ServerFactory) {
            Assert::fail('The container has no MCP ServerFactory. Is the CakeDC/Mcp plugin loaded?');
        }

        return array_map(
            fn(array $tool): string => (string)$tool['name'],
            $factory->forServer($this->_mcpServer)->registeredToolDefinitions(),
        );
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
