<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Server\ServerFactory;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server\Builder;
use ReflectionProperty;

/**
 * The plugin reads a few private properties of the SDK's Builder (and one of CakePHP's CSRF middleware). A release
 * that renames or retypes one fails here, in CI, instead of on every request.
 */
class SdkInternalsTest extends TestCase
{
    public function testEveryBuilderPropertyThePluginReadsIsThereWithTheTypeItExpects(): void
    {
        foreach (ServerFactory::BUILDER_PROPERTIES as $name => $type) {
            $this->assertTrue(property_exists(Builder::class, $name), sprintf('Builder::$%s is gone.', $name));
            $this->assertSame(
                $type,
                (string)(new ReflectionProperty(Builder::class, $name))->getType(),
                sprintf('Builder::$%s changed type.', $name),
            );
        }
    }

    public function testTheBuilderKeepsToolsAndExtensionsInTheShapeThePluginReads(): void
    {
        $builder = (new Builder())
            ->addTool(fn(): string => 'ok', 'echo')
            ->enableExtension(new McpApps());

        $tools = ServerFactory::builderValue($builder, 'tools');
        $this->assertIsArray($tools);
        $this->assertSame('echo', $tools[0]['name'] ?? null);
        $extensions = ServerFactory::builderValue($builder, 'extensions');
        $this->assertIsArray($extensions);
        $this->assertArrayHasKey(McpApps::EXTENSION_ID, $extensions);
        $this->assertSame([], ServerFactory::builderValue($builder, 'explicitTools'));
        $this->assertSame([], ServerFactory::builderValue($builder, 'requestHandlers'));
        $this->assertNull(ServerFactory::builderValue($builder, 'notificationBus'));
    }

    public function testTheCsrfMiddlewareStillKeepsItsSkipCallback(): void
    {
        $callback = fn(): bool => true;
        $middleware = (new CsrfProtectionMiddleware())->skipCheckCallback($callback);

        $this->assertSame(
            $callback,
            (new ReflectionProperty(CsrfProtectionMiddleware::class, 'skipCheckCallback'))->getValue($middleware),
        );
    }
}
