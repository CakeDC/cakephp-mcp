<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Container;

use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Container\ContainerBridge;
use CakeDC\Mcp\Container\ContainerException;
use CakeDC\Mcp\Container\NotFoundException;
use TestApp\Service\Clock;
use TestApp\Service\CycleA;
use TestApp\Service\Greeter;
use TestApp\Service\NeedsScalar;

class ContainerBridgeTest extends TestCase
{
    public function testReturnsWhatTheHostContainerHasRegistered(): void
    {
        $container = new Container();
        $clock = new Clock();
        $container->addShared(Clock::class, $clock);

        $this->assertSame($clock, (new ContainerBridge($container))->get(Clock::class));
    }

    public function testAutowiresUnregisteredClassesWithDefaults(): void
    {
        $greeter = (new ContainerBridge(new Container()))->get(Greeter::class);

        $this->assertInstanceOf(Greeter::class, $greeter);
        $this->assertInstanceOf(Clock::class, $greeter->clock);
        $this->assertSame('Hello', $greeter->greeting);
    }

    public function testAutowiredClassesReceiveRegisteredDependencies(): void
    {
        $container = new Container();
        $clock = new Clock();
        $container->addShared(Clock::class, $clock);

        $greeter = (new ContainerBridge($container))->get(Greeter::class);

        $this->assertSame($clock, $greeter->clock);
    }

    public function testAutowiredInstancesAreReusedByTheSameBridge(): void
    {
        $bridge = new ContainerBridge(new Container());

        $this->assertSame($bridge->get(Greeter::class), $bridge->get(Greeter::class));
    }

    public function testUnknownIdIsNotFound(): void
    {
        $bridge = new ContainerBridge(new Container());

        $this->assertFalse($bridge->has('No\\Such\\Thing'));
        $this->expectException(NotFoundException::class);
        $bridge->get('No\\Such\\Thing');
    }

    public function testUnresolvableScalarFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$required');
        (new ContainerBridge(new Container()))->get(NeedsScalar::class);
    }

    public function testCircularDependencyFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular');
        (new ContainerBridge(new Container()))->get(CycleA::class);
    }
}
