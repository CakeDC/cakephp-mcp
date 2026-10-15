<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use Cake\Cache\Cache;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolCallGuard;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Container\ContainerBridge;
use CakeDC\Mcp\Event\EventDispatcher;
use CakeDC\Mcp\Event\ToolListFilter;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use InvalidArgumentException;
use Mcp\Event\ResponseEvent;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Container\ContainerInterface;

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

        $builder = Server::builder()
            ->setServerInfo((string)McpConfig::get('server.name'), (string)McpConfig::get('server.version'))
            ->setContainer(new ContainerBridge($this->container))
            ->setLogger(new McpLogger())
            ->setEventDispatcher($events)
            ->setSession(new Psr16SessionStore(
                Cache::pool((string)McpConfig::get('session.cache')),
                'mcp-',
                (int)McpConfig::get('session.ttl'),
            ))
            ->addRequestHandler(new ToolCallGuard($gate));

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
     * @return \CakeDC\Mcp\Authorization\ToolAuthorizerInterface
     */
    private function authorizer(): ToolAuthorizerInterface
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
