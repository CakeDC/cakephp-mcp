<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use Cake\Cache\Cache;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolCallGuard;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Container\ContainerBridge;
use CakeDC\Mcp\Event\AuditListener;
use CakeDC\Mcp\Event\EventDispatcher;
use CakeDC\Mcp\Event\ToolListFilter;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use InvalidArgumentException;
use Mcp\Capability\Registry;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * Builds the MCP server from the `Mcp` config: attribute discovery, Cake cache
 * pools for discovery and sessions, Cake's container (autowired through
 * ContainerBridge), Cake Log, and the per-tool authorization gate.
 */
final class ServerFactory
{
    /**
     * @param \Psr\Container\ContainerInterface $container The host container.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity The shared current identity.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly CurrentIdentity $identity,
    ) {
    }

    /**
     * @return \CakeDC\Mcp\Identity\CurrentIdentity
     */
    public function identity(): CurrentIdentity
    {
        return $this->identity;
    }

    /**
     * @param bool $trusted Skip per-tool authorization (stdio started by the developer).
     * @return \Mcp\Server
     */
    public function create(bool $trusted = false): Server
    {
        $gate = new ToolGate($this->authorizer(), $this->identity, $trusted);
        $events = new EventDispatcher();
        $events->listen(ResponseEvent::class, new ToolListFilter($gate));
        // The guard goes first: the SDK asks request handlers in the order they were added.
        $builder = Server::builder()->addRequestHandler(new ToolCallGuard($gate));
        $audit = AuditSettings::fromConfig();
        if ($audit->enabled) {
            // The audit needs to know which tools exist, to tell a denied tool from an unknown one: it keeps
            // the registry. Set before the host hook, which may still replace it (the audit then falls back
            // to asking the gate only).
            $registry = new Registry($events, new McpLogger());
            $builder->setRegistry($registry);
            $listener = new AuditListener(
                $audit,
                new AuditWriter($audit),
                $gate,
                $this->identity,
                toolExists: fn(string $name): ?bool => $registry->hasTools() ? $registry->hasTool($name) : null,
            );
            $events->listen(RequestEvent::class, $listener->onRequest(...));
            $events->listen(ResponseEvent::class, $listener->onResponse(...));
            $events->listen(ErrorEvent::class, $listener->onError(...));
        }
        $this->configure($builder);
        // Set after the host hook, so the hook cannot drop the tool list filter or replace these.
        $builder
            ->setServerInfo(
                (string)McpConfig::get('server.name'),
                (string)McpConfig::get('server.version'),
                $this->optionalString('server.description'),
                websiteUrl: $this->optionalString('server.websiteUrl'),
                title: $this->optionalString('server.title'),
            )
            ->setContainer(new ContainerBridge($this->container))
            ->setLogger(new McpLogger())
            ->setEventDispatcher($events)
            ->setSession(new Psr16SessionStore(
                Cache::pool((string)McpConfig::get('session.cache')),
                'mcp-',
                (int)McpConfig::get('session.ttl'),
            ));

        $instructions = McpConfig::get('server.instructions');
        if (is_string($instructions) && $instructions !== '') {
            $builder->setInstructions($instructions);
        }

        $scanDirs = $this->existingDirs((array)McpConfig::get('discovery.scanDirs'));
        if ($scanDirs !== []) {
            $cache = McpConfig::discoveryCache();
            $builder->setDiscovery(
                rtrim(ROOT, DS),
                $scanDirs,
                array_values(array_filter((array)McpConfig::get('discovery.excludeDirs'), 'is_string')),
                $cache !== null ? Cache::pool($cache) : null,
            );
        }

        return $builder->build();
    }

    /**
     * Runs the `Mcp.server.configure` hook, which may use anything the SDK
     * builder offers (tools added in code, request state, cache policy...).
     *
     * @param \Mcp\Server\Builder $builder The SDK builder.
     * @return void
     */
    private function configure(Builder $builder): void
    {
        $configure = McpConfig::get('server.configure');
        if ($configure === null) {
            return;
        }
        if (!is_callable($configure)) {
            throw new InvalidArgumentException(sprintf(
                '`Mcp.server.configure` must be a callable that receives the SDK `%s`.',
                Builder::class,
            ));
        }
        $configure($builder);
    }

    /**
     * @param string $path Dot path inside the `Mcp` config.
     * @return string|null The value, or null when it is not a non-empty string.
     */
    private function optionalString(string $path): ?string
    {
        $value = McpConfig::get($path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Keeps only the scan directories that exist, so a host that has not
     * created its tools directory yet gets an empty tool list, not an error.
     *
     * @param array<mixed> $dirs Directories relative to ROOT.
     * @return list<string>
     */
    private function existingDirs(array $dirs): array
    {
        $existing = [];
        foreach ($dirs as $dir) {
            if (is_string($dir) && is_dir(rtrim(ROOT, DS) . DS . $dir)) {
                $existing[] = $dir;
            }
        }

        return $existing;
    }

    /**
     * The names of the tools the server offers, as a trusted client lists them (no authorizer, every page).
     * Builds the server the same way the endpoint does, without network calls.
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return list<string>
     * @throws \RuntimeException When the server cannot list its tools.
     */
    public function toolNames(): array
    {
        $names = [];
        $cursor = null;
        for ($page = 0; $page < 100; $page++) {
            $list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
            if ($cursor !== null) {
                $list['params'] = ['cursor' => $cursor];
            }
            $transport = new RecordingTransport([
                (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                    'protocolVersion' => ProtocolVersion::latestHandshake()->value,
                    'capabilities' => new stdClass(),
                    'clientInfo' => ['name' => 'cakephp-mcp', 'version' => '1.0'],
                ]]),
                (string)json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
                (string)json_encode($list),
            ]);
            $this->create(true)->run($transport);
            $result = $transport->responseFor(2)['result'] ?? null;
            if (!is_array($result) || !is_array($result['tools'] ?? null)) {
                throw new RuntimeException(
                    'The server could not list its tools: ' . json_encode($transport->responseFor(2)),
                );
            }
            foreach ($result['tools'] as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
                    $names[] = $tool['name'];
                }
            }
            $cursor = $result['nextCursor'] ?? null;
            if (!is_string($cursor)) {
                break;
            }
        }

        return $names;
    }

    /**
     * The configured tool authorizer (`Mcp.authorization.authorizer`), built as the server builds it.
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return \CakeDC\Mcp\Authorization\ToolAuthorizerInterface
     */
    public function authorizer(): ToolAuthorizerInterface
    {
        $authorizer = McpConfig::get('authorization.authorizer');
        if ($authorizer instanceof ToolAuthorizerInterface) {
            return $authorizer;
        }
        if ($authorizer === RbacToolAuthorizer::class) {
            return new RbacToolAuthorizer((array)McpConfig::get('authorization.rbac'));
        }
        if (is_string($authorizer) && is_subclass_of($authorizer, ToolAuthorizerInterface::class)) {
            $instance = (new ContainerBridge($this->container))->get($authorizer);
            if ($instance instanceof ToolAuthorizerInterface) {
                return $instance;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '`Mcp.authorization.authorizer` must be an instance or class name of `%s`.',
            ToolAuthorizerInterface::class,
        ));
    }
}
