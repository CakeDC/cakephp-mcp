<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use stdClass;

/**
 * Requests to /mcp as a remote client sends them (2026-07-28 protocol revision, no session), for
 * test cases that use IntegrationTestTrait.
 *
 * @property array<string, mixed> $_request
 * @property \Psr\Http\Message\ResponseInterface|null $_response
 */
trait McpHttpTrait
{
    private const MCP_REVISION = '2026-07-28';

    /**
     * Posts $message to /mcp. No Authorization header unless $headers has one.
     *
     * @param array<string, mixed> $message The JSON-RPC message.
     * @param array<string, string> $headers Extra or replacing headers.
     * @return void
     */
    protected function postMcp(array $message, array $headers = []): void
    {
        $this->_request['headers'] = $headers + [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        $this->post('/mcp', (string)json_encode($message));
    }

    /**
     * @param int $id The JSON-RPC id.
     * @param string $method The method.
     * @param array<string, mixed> $params Its params.
     * @return array<string, mixed>
     */
    protected function mcpMessage(int $id, string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::MCP_REVISION,
            'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
        ];

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    /**
     * @param string $method The JSON-RPC method.
     * @param string|null $name The tool name, for tools/call.
     * @param string|null $token A bearer token.
     * @return array<string, string>
     */
    protected function mcpHeaders(string $method, ?string $name = null, ?string $token = null): array
    {
        $headers = ['MCP-Protocol-Version' => self::MCP_REVISION, 'Mcp-Method' => $method];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * @param string $name The tool.
     * @param array<string, mixed> $arguments Its arguments.
     * @param string|null $token A bearer token.
     * @return void
     */
    protected function callTool(string $name, array $arguments = [], ?string $token = null): void
    {
        $this->postMcp(
            $this->mcpMessage(1, 'tools/call', [
                'name' => $name,
                'arguments' => $arguments === [] ? new stdClass() : $arguments,
            ]),
            $this->mcpHeaders('tools/call', $name, $token),
        );
    }

    /**
     * @param string|null $token A bearer token.
     * @return void
     */
    protected function listTools(?string $token = null): void
    {
        $this->postMcp($this->mcpMessage(1, 'tools/list'), $this->mcpHeaders('tools/list', null, $token));
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseJson(): array
    {
        $decoded = json_decode((string)$this->_response?->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The quoted parameters of the response's WWW-Authenticate challenge.
     *
     * @return array<string, string>
     */
    protected function challenge(): array
    {
        $header = (string)$this->_response?->getHeaderLine('WWW-Authenticate');
        preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', $header, $matches, PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $match) {
            $params[$match[1]] = stripslashes($match[2]);
        }

        return $params;
    }
}
