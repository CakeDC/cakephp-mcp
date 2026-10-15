<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Conformance;

use Cake\Cache\Cache;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\Test\Conformance\Sdk\Elements;
use CakeDC\Mcp\Test\Conformance\Sdk\MrtrElements;
use Mcp\Capability\Registry;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use Mcp\Server\Wire\CachePolicy;
use stdClass;

/**
 * Registers, through `Mcp.server.configure`, the elements the conformance suite
 * calls. They are the ones of the SDK's own conformance server
 * (modelcontextprotocol/php-sdk tests/Conformance/server.php at 82e19d1), so a
 * scenario that passes there and fails here points at the plugin.
 */
final class ConformanceFixture
{
    /**
     * @param \Mcp\Server\Builder $builder The SDK builder.
     * @return void
     */
    public static function configure(Builder $builder): void
    {
        $logger = new McpLogger();
        // Stored in a cache shared by the php-fpm workers: the one holding a listen
        // stream open and the one serving the tools/call that changes a list differ.
        $bus = new Psr16NotificationBus(Cache::pool('mcp_conformance_bus'), logger: $logger);
        $registry = new Registry(new PublishingEventDispatcher($bus), $logger);

        $builder
            ->setRegistry($registry)
            ->addTool(
                static fn() => 'This is a simple text response for testing.',
                name: 'test_simple_text',
                description: 'Tests simple text content response',
            )
            ->addTool(
                static fn() => new ImageContent(Elements::TEST_IMAGE_BASE64, 'image/png'),
                name: 'test_image_content',
                description: 'Tests image content response',
            )
            ->addTool(
                static fn() => new AudioContent(Elements::TEST_AUDIO_BASE64, 'audio/wav'),
                name: 'test_audio_content',
                description: 'Tests audio content response',
            )
            ->addTool(
                static fn() => EmbeddedResource::fromText(
                    'test://embedded-resource',
                    'This is an embedded resource content.',
                ),
                name: 'test_embedded_resource',
                description: 'Tests embedded resource content response',
            )
            ->addTool(
                [Elements::class, 'toolMultipleTypes'],
                name: 'test_multiple_content_types',
                description: 'Tests response with multiple content types',
            )
            ->addTool(
                static fn() => CallToolResult::error([
                    new TextContent('This tool intentionally returns an error for testing'),
                ]),
                name: 'test_error_handling',
                description: 'Tests error response handling',
            )
            ->addTool(
                static fn() => 'ok',
                name: 'json_schema_2020_12_tool',
                description: 'Tool with JSON Schema 2020-12 features',
                inputSchema: Elements::jsonSchema2020_12Fixture(),
            )
            ->addTool(
                static function (): never {
                    throw new MissingRequiredClientCapabilityException(
                        new ClientCapabilities(roots: false, sampling: true),
                        'test_missing_capability requires the sampling capability.',
                    );
                },
                name: 'test_missing_capability',
                description: 'Always reports a missing client capability, for testing -32021 handling',
            )
            ->addTool(
                static fn(): InputRequiredResult => new InputRequiredResult(
                    [
                        'conformance_probe' => new ElicitRequest(
                            'Please provide a value for the conformance probe.',
                            new ElicitationSchema(['value' => new StringSchemaDefinition('Value')], ['value']),
                        ),
                    ],
                    requestState: base64_encode(
                        json_encode(['tool' => 'test_streaming_elicitation'], JSON_THROW_ON_ERROR),
                    ),
                ),
                name: 'test_streaming_elicitation',
                description: 'Returns an InputRequiredResult asking for elicitation input',
            )
            ->addTool(
                static function () use ($logger): string {
                    $logger->info('test_logging_tool executed');

                    return 'Logged.';
                },
                name: 'test_logging_tool',
                description: 'Emits a server-side log message while returning normally',
            )
            ->addTool(
                static fn(string $region = '', int $retries = 0): string => sprintf(
                    'region=%s retries=%d',
                    $region,
                    $retries,
                ),
                name: 'test_custom_headers',
                description: 'Tests custom header mirroring via x-mcp-header',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                        'retries' => ['type' => 'integer', 'x-mcp-header' => 'Retries'],
                    ],
                    'required' => ['region'],
                ],
            )
            ->addTool(
                [Elements::class, 'toolWithProgress'],
                name: 'test_tool_with_progress',
                description: 'Tests tool that reports progress notifications',
            )
            ->addTool(
                [Elements::class, 'toolWithLogging'],
                name: 'test_tool_with_logging',
                description: 'Tests tool that emits log messages',
            )
            ->addTool(
                [Elements::class, 'toolWithSampling'],
                name: 'test_sampling',
                description: 'Tests server-initiated sampling',
            )
            ->addTool(
                [Elements::class, 'toolWithElicitation'],
                name: 'test_elicitation',
                description: 'Tests server-initiated elicitation',
            )
            ->addTool(
                [Elements::class, 'toolWithElicitationDefaults'],
                name: 'test_elicitation_sep1034_defaults',
                description: 'Tests elicitation with default values',
            )
            ->addTool(
                [Elements::class, 'toolWithElicitationEnums'],
                name: 'test_elicitation_sep1330_enums',
                description: 'Tests elicitation with enum schemas',
            )
            ->addTool(
                static function () use ($registry): string {
                    $registry->registerTool(
                        new Tool(
                            name: 'test_ephemeral_tool_' . bin2hex(random_bytes(4)),
                            title: null,
                            inputSchema: ['type' => 'object', 'properties' => new stdClass(), 'required' => null],
                            description: 'Registered to trigger a list change',
                            annotations: null,
                        ),
                        static fn(): string => 'ephemeral',
                    );

                    return 'Tool list mutated.';
                },
                name: 'test_trigger_tool_change',
                description: 'Registers a tool so the tool list changes',
            )
            ->addTool(
                static function () use ($registry): string {
                    $registry->registerPrompt(
                        new Prompt(
                            'test_ephemeral_prompt_' . bin2hex(random_bytes(4)),
                            null,
                            'Registered to trigger a list change',
                        ),
                        static fn(): array => [['role' => 'user', 'content' => 'ephemeral']],
                    );

                    return 'Prompt list mutated.';
                },
                name: 'test_trigger_prompt_change',
                description: 'Registers a prompt so the prompt list changes',
            )
            ->addTool(
                [MrtrElements::class, 'elicitation'],
                name: 'test_input_required_result_elicitation',
                description: 'MRTR: asks for a name via elicitation',
            )
            ->addTool(
                [MrtrElements::class, 'sampling'],
                name: 'test_input_required_result_sampling',
                description: 'MRTR: asks for a sampling completion',
            )
            ->addTool(
                [MrtrElements::class, 'listRoots'],
                name: 'test_input_required_result_list_roots',
                description: 'MRTR: asks for the client roots',
            )
            ->addTool(
                [MrtrElements::class, 'elicitation'],
                name: 'test_input_required_result_request_state',
                description: 'MRTR: exercises requestState round-tripping',
            )
            ->addTool(
                [MrtrElements::class, 'multipleInputs'],
                name: 'test_input_required_result_multiple_inputs',
                description: 'MRTR: asks for two inputs at once',
            )
            ->addTool(
                [MrtrElements::class, 'multiRound'],
                name: 'test_input_required_result_multi_round',
                description: 'MRTR: asks across two sequential rounds',
            )
            ->addTool(
                [MrtrElements::class, 'capabilities'],
                name: 'test_input_required_result_capabilities',
                description: 'MRTR: asks only for capabilities the client declared',
            )
            ->addTool(
                [MrtrElements::class, 'tamperedState'],
                name: 'test_input_required_result_tampered_state',
                description: 'MRTR: completes only when the echoed state verifies',
            )
            ->addResource(
                static fn() => 'This is the content of the static text resource.',
                'test://static-text',
                'static-text',
                'A static text resource for testing',
            )
            ->addResource(
                static fn() => fopen('data://image/png;base64,' . Elements::TEST_IMAGE_BASE64, 'r'),
                'test://static-binary',
                'static-binary',
                'A static binary resource (image) for testing',
            )
            ->addResourceTemplate(
                [Elements::class, 'resourceTemplate'],
                'test://template/{id}/data',
                'template',
                'A resource template with parameter substitution',
                'application/json',
            )
            ->addResource(
                static fn() => 'Watched resource content',
                'test://watched-resource',
                'watched-resource',
                'A resource that can be watched',
            )
            ->addPrompt(
                static fn() => [['role' => 'user', 'content' => 'This is a simple prompt for testing.']],
                name: 'test_simple_prompt',
                description: 'A simple prompt without arguments',
            )
            ->addPrompt(
                [Elements::class, 'promptWithArguments'],
                name: 'test_prompt_with_arguments',
                description: 'A prompt with required arguments',
            )
            ->addPrompt(
                [Elements::class, 'promptWithEmbeddedResource'],
                name: 'test_prompt_with_embedded_resource',
                description: 'A prompt that includes an embedded resource',
            )
            ->addPrompt(
                [Elements::class, 'promptWithImage'],
                name: 'test_prompt_with_image',
                description: 'A prompt that includes image content',
            )
            ->addPrompt(
                [MrtrElements::class, 'prompt'],
                name: 'test_input_required_result_prompt',
                description: 'MRTR: a prompt that asks for input first',
            )
            // Fixed, so a retry served by another worker still verifies.
            ->setRequestState(str_repeat('conformance-fixture-key-', 2))
            ->setNotificationBus($bus)
            // Short, so a listen stream does not hold a php-fpm worker for the whole run.
            ->setSubscriptionLifetime(5.0)
            ->setCachePolicy(
                CachePolicy::default(60_000)
                    ->withMethod('tools/list', 3_600_000, CacheScope::Public)
                    ->withMethod('prompts/list', 3_600_000, CacheScope::Public)
                    ->withMethod('resources/list', 3_600_000, CacheScope::Public)
                    ->withMethod('resources/templates/list', 3_600_000, CacheScope::Public)
                    ->withMethod('server/discover', 3_600_000, CacheScope::Public),
            );
    }
}
