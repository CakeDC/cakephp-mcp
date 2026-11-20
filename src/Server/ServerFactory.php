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
use CakeDC\Mcp\Catalog\CatalogPatterns;
use CakeDC\Mcp\Catalog\ToolCatalog;
use CakeDC\Mcp\Container\ContainerBridge;
use CakeDC\Mcp\Event\AuditListener;
use CakeDC\Mcp\Event\EventDispatcher;
use CakeDC\Mcp\Event\ToolListFilter;
use CakeDC\Mcp\Event\ToolSchemaNormalizer;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use InvalidArgumentException;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Stringable;

/**
 * Builds the MCP server from the `Mcp` config: attribute discovery, Cake cache
 * pools for discovery and sessions, Cake's container (autowired through
 * ContainerBridge), Cake Log, and the per-tool authorization gate.
 */
final class ServerFactory
{
    /**
     * What #[McpWhen] removed in the last build.
     *
     * @var list<string>
     */
    private array $skipped = [];

    /**
     * @param \Psr\Container\ContainerInterface $container The host container.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity The shared current identity.
     * @param string $server The MCP server this factory builds: `default` or a key of `Mcp.servers`.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly CurrentIdentity $identity,
        private readonly string $server = McpConfig::DEFAULT_SERVER,
    ) {
    }

    /**
     * A factory for another MCP server, with the same container and current identity.
     *
     * @param string $server `default` or a key of `Mcp.servers`.
     * @return self
     * @throws \InvalidArgumentException When the server is not configured.
     */
    public function forServer(string $server): self
    {
        McpConfig::get('route', $server);

        return new self($this->container, $this->identity, $server);
    }

    /**
     * @return string The MCP server this factory builds.
     */
    public function server(): string
    {
        return $this->server;
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
        return $this->build($trusted, new McpLogger());
    }

    /**
     * @param bool $trusted Skip per-tool authorization.
     * @param \Psr\Log\LoggerInterface $logger What the SDK logs.
     * @return \Mcp\Server
     */
    private function build(bool $trusted, LoggerInterface $logger): Server
    {
        $catalog = (array)McpConfig::get('catalog', $this->server);
        $catalogOn = ($catalog['enabled'] ?? false) === true;
        $patterns = array_values(array_filter((array)($catalog['tools'] ?? []), 'is_string'));
        // The catalog's own tools are always allowed: each tool they run goes through the gate instead.
        $gate = new ToolGate(
            $this->authorizer(),
            $this->identity,
            $trusted,
            $catalogOn ? [ToolCatalog::FIND, ToolCatalog::CALL] : [],
        );
        $events = new EventDispatcher();
        $events->listen(ResponseEvent::class, new ToolListFilter(
            $gate,
            $catalogOn ? fn(string $tool): bool => CatalogPatterns::matches($patterns, $tool) : null,
        ));
        if (McpConfig::get('schema.normalize', $this->server) === true) {
            // After the filter, on the tools the caller may see.
            $events->listen(ResponseEvent::class, new ToolSchemaNormalizer());
        }
        // The guard goes first: the SDK asks request handlers in the order they were added.
        $builder = Server::builder()->addRequestHandler(new ToolCallGuard($gate));
        // Our own registry, set before the host hook (which may still replace it): #[McpWhen] prunes it after
        // the build, and the audit asks it whether a tool exists, to tell a denied tool from an unknown one.
        $registry = new Registry($events, $logger);
        $builder->setRegistry($registry);
        if ($catalogOn) {
            $this->addCatalog($builder, new ToolCatalog(
                $registry,
                $gate,
                $patterns,
                $this->maxCalls($catalog['maxCalls'] ?? 5),
                new ReferenceHandler(new ContainerBridge($this->container)),
                $logger,
            ));
        }
        $audit = AuditSettings::fromConfig($this->server);
        if ($audit->enabled) {
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
                (string)McpConfig::get('server.name', $this->server),
                (string)McpConfig::get('server.version', $this->server),
                $this->optionalString('server.description'),
                websiteUrl: $this->optionalString('server.websiteUrl'),
                title: $this->optionalString('server.title'),
            )
            ->setContainer(new ContainerBridge($this->container))
            ->setLogger($logger)
            ->setEventDispatcher($events)
            ->setSession(new Psr16SessionStore(
                Cache::pool((string)McpConfig::get('session.cache', $this->server)),
                $this->server === McpConfig::DEFAULT_SERVER ? 'mcp-' : 'mcp-' . $this->server . '-',
                (int)McpConfig::get('session.ttl', $this->server),
            ));

        $cachePolicy = CacheHints::fromConfig(McpConfig::get('server.cache', $this->server));
        if ($cachePolicy !== null) {
            $builder->setCachePolicy($cachePolicy);
        }

        $instructions = McpConfig::get('server.instructions', $this->server);
        if (is_string($instructions) && $instructions !== '') {
            $builder->setInstructions($instructions);
        }

        $scanDirs = $this->existingDirs((array)McpConfig::get('discovery.scanDirs', $this->server));
        if ($scanDirs !== []) {
            $cache = McpConfig::discoveryCache($this->server);
            $builder->setDiscovery(
                rtrim(ROOT, DS),
                $scanDirs,
                array_values(array_filter((array)McpConfig::get('discovery.excludeDirs', $this->server), 'is_string')),
                $cache !== null ? Cache::pool($cache) : null,
            );
        }

        $server = $builder->build();
        $this->skipped = Conditions::prune($registry, $this->server);

        return $server;
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
        $configure = McpConfig::get('server.configure', $this->server);
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
        $value = McpConfig::get($path, $this->server);

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
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return list<string>
     * @throws \RuntimeException When the server cannot list its tools.
     */
    public function toolNames(): array
    {
        return array_map(fn(array $tool): string => $tool['name'], $this->toolDefinitions());
    }

    /**
     * The tools the server offers, as a trusted client lists them (no authorizer, every page), decoded from JSON:
     * the schemas as clients see them, after `Mcp.schema.normalize` when it is on. Builds the server the same way
     * the endpoint does, without network calls.
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return list<array<string, mixed>> Each tool has a string `name`.
     * @throws \RuntimeException When the server cannot list its tools.
     */
    public function toolDefinitions(): array
    {
        $tools = [];
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
            $this->build(true, $this->quietLogger())->run($transport);
            $result = $transport->responseFor(2)['result'] ?? null;
            if (!is_array($result) || !is_array($result['tools'] ?? null)) {
                throw new RuntimeException(
                    'The server could not list its tools: ' . json_encode($transport->responseFor(2)),
                );
            }
            foreach ($result['tools'] as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
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
     * Registers `find_tools` and `call_tools`.
     *
     * @param \Mcp\Server\Builder $builder The builder.
     * @param \CakeDC\Mcp\Catalog\ToolCatalog $catalog The catalog.
     * @return void
     */
    private function addCatalog(Builder $builder, ToolCatalog $catalog): void
    {
        $schemas = $catalog->inputSchemas();
        $builder->addTool(
            [$catalog, 'find'],
            ToolCatalog::FIND,
            'Find tools',
            'Finds more tools of this server by what they do. Returns their names, descriptions and input schemas; '
            . 'run them with call_tools.',
            new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
            $schemas[ToolCatalog::FIND],
        );
        $builder->addTool(
            [$catalog, 'call'],
            ToolCatalog::CALL,
            'Call tools',
            'Runs tools found with find_tools, in order, and returns each result. Stops at the first error.',
            null,
            $schemas[ToolCatalog::CALL],
        );
    }

    /**
     * @param mixed $value `Mcp.catalog.maxCalls`.
     * @return int
     * @throws \InvalidArgumentException When it is not a positive integer.
     */
    private function maxCalls(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException('"Mcp.catalog.maxCalls" must be a positive integer.');
        }

        return $value;
    }

    /**
     * What `#[McpWhen]` leaves out of this server, as a trusted client would see it.
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return list<string> `<kind> <name> (<why>)`.
     */
    public function skippedElements(): array
    {
        $this->build(true, $this->quietLogger());

        return $this->skipped;
    }

    /**
     * Only warnings and worse: the SDK's info lines would land among the output of `mcp check`.
     *
     * @return \Psr\Log\LoggerInterface
     */
    private function quietLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /**
             * @inheritDoc
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if (!in_array($level, [LogLevel::INFO, LogLevel::DEBUG, LogLevel::NOTICE], true)) {
                    (new McpLogger())->log($level, $message, $context);
                }
            }
        };
    }

    /**
     * The configured tool authorizer (`Mcp.authorization.authorizer`), built as the server builds it.
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return \CakeDC\Mcp\Authorization\ToolAuthorizerInterface
     */
    public function authorizer(): ToolAuthorizerInterface
    {
        $authorizer = McpConfig::get('authorization.authorizer', $this->server);
        if ($authorizer instanceof ToolAuthorizerInterface) {
            return $authorizer;
        }
        if ($authorizer === RbacToolAuthorizer::class) {
            return new RbacToolAuthorizer((array)McpConfig::get('authorization.rbac', $this->server));
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
