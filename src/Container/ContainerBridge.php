<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The PSR-11 container handed to the MCP SDK.
 *
 * Cake's container does not autowire, so tool classes would have to be
 * registered one by one. The bridge returns whatever the host registered and
 * autowires everything else, resolving constructor parameters through itself.
 */
final class ContainerBridge implements ContainerInterface
{
    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, true>
     */
    private array $building = [];

    /**
     * @param \Psr\Container\ContainerInterface $container The host container.
     */
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @inheritDoc
     */
    public function has(string $id): bool
    {
        return $this->container->has($id) || class_exists($id);
    }

    /**
     * @inheritDoc
     */
    public function get(string $id): mixed
    {
        if ($this->container->has($id)) {
            return $this->container->get($id);
        }
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!class_exists($id)) {
            throw new NotFoundException(sprintf('`%s` is not registered in the container and is not a class.', $id));
        }

        return $this->instances[$id] = $this->build($id);
    }

    /**
     * @param class-string $class The class to build.
     * @return object
     */
    private function build(string $class): object
    {
        if (isset($this->building[$class])) {
            throw new ContainerException(sprintf('Circular dependency while resolving `%s`.', $class));
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('`%s` is not instantiable.', $class));
        }

        $this->building[$class] = true;
        try {
            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                return $reflection->newInstance();
            }
            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                $arguments[] = $this->resolveParameter($parameter, $class);
            }

            return $reflection->newInstanceArgs($arguments);
        } finally {
            unset($this->building[$class]);
        }
    }

    /**
     * @param \ReflectionParameter $parameter The constructor parameter.
     * @param string $class The class being built (for error messages).
     * @return mixed
     */
    private function resolveParameter(ReflectionParameter $parameter, string $class): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();
            if ($this->container->has($name) || isset($this->building[$name]) || $this->isInstantiable($name)) {
                return $this->get($name);
            }
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot resolve parameter `$%s` of `%s`.',
            $parameter->getName(),
            $class,
        ));
    }

    /**
     * @param string $name A class or interface name.
     * @return bool
     */
    private function isInstantiable(string $name): bool
    {
        return class_exists($name) && (new ReflectionClass($name))->isInstantiable();
    }
}
