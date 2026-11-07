<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Audit;

use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Audit\AuditRecord;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

class AuditWriterTest extends TestCase
{
    /**
     * @var list<\Cake\Event\EventInterface<null>>
     */
    private array $events = [];

    /**
     * @var callable
     */
    private $listener;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::delete('Mcp');
        Log::setConfig('audit_test', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Log::setConfig('mcp_test', ['className' => ArrayLog::class, 'scopes' => ['mcp'], 'levels' => ['error']]);
        $this->events = [];
        $this->listener = function (EventInterface $event): void {
            $this->events[] = $event;
        };
        EventManager::instance()->on('Mcp.audit', $this->listener);
    }

    protected function tearDown(): void
    {
        EventManager::instance()->off('Mcp.audit', $this->listener);
        Log::drop('audit_test');
        Log::drop('mcp_test');
        if (in_array('failing', Log::configured(), true)) {
            Log::drop('failing');
        }
        parent::tearDown();
    }

    private function writer(): AuditWriter
    {
        return new AuditWriter(AuditSettings::fromConfig());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function record(array $overrides = []): AuditRecord
    {
        $fields = $overrides + [
            'method' => 'tools/call',
            'name' => 'find_order',
            'outcome' => AuditRecord::OK,
            'identity' => 12,
            'client' => '62938fb0',
            'session' => 's-1',
            'request' => 2,
            'arguments' => ['number' => 'A-1042'],
            'error' => null,
            'durationMs' => 34,
        ];

        return new AuditRecord(...$fields);
    }

    /**
     * @return list<string>
     */
    private function lines(string $config = 'audit_test'): array
    {
        $engine = Log::engine($config);
        $this->assertInstanceOf(ArrayLog::class, $engine);

        return array_values(array_map('strval', $engine->read()));
    }

    public function testWritesOneLineWithTheRecord(): void
    {
        $this->writer()->write($this->record());

        $this->assertSame(
            ['info: MCP tools/call find_order ok 34ms identity=12 client=62938fb0 session=s-1 request=2 args={"number":"A-1042"}'],
            $this->lines(),
        );
    }

    public function testClientTextCannotForgeLinesOrPlaceholders(): void
    {
        $writer = $this->writer();
        $writer->write($this->record([
            'name' => "echo ok 1ms\ninfo: MCP tools/call delete_all ok 3ms identity=1 {scope}",
            'request' => "r\n1 {scope}",
            'outcome' => AuditRecord::INVALID,
            'arguments' => $writer->arguments(['text' => '{scope}', 'q' => "a\nb"]),
            'error' => "Tool not found: \"x\\\"\ninfo: forged",
        ]));

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertStringNotContainsString("\n", $lines[0]);
        $this->assertStringNotContainsString('mcp_audit', $lines[0]);
        // The name is quoted and escaped, so the forged record stays inside it.
        $this->assertStringStartsWith('notice: MCP tools/call "echo ok 1ms\\ninfo: MCP', $lines[0]);
        preg_match('/ args=(\{.*\}) error=/', $lines[0], $match);
        $this->assertSame(['text' => '{scope}', 'q' => "a\nb"], json_decode($match[1] ?? '', true));
    }

    public function testNullsAreDashes(): void
    {
        $this->writer()->write($this->record(['identity' => null, 'client' => null, 'session' => null, 'durationMs' => null]));

        $this->assertStringContainsString('ok -ms identity=- client=- session=- request=2', $this->lines()[0]);
    }

    public function testErrorIsAppended(): void
    {
        $this->writer()->write($this->record(['outcome' => AuditRecord::TOOL_ERROR, 'error' => 'Course 9 does not exist.']));

        $line = $this->lines()[0];
        $this->assertStringStartsWith('notice: ', $line);
        $this->assertStringEndsWith(' error="Course 9 does not exist."', $line);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function levels(): array
    {
        return [
            'ok' => [AuditRecord::OK, 'info'],
            'tool error' => [AuditRecord::TOOL_ERROR, 'notice'],
            'invalid' => [AuditRecord::INVALID, 'notice'],
            'denied' => [AuditRecord::DENIED, 'warning'],
            'error' => [AuditRecord::ERROR, 'warning'],
            'unauthorized' => [AuditRecord::UNAUTHORIZED, 'warning'],
            'insufficient scope' => [AuditRecord::INSUFFICIENT_SCOPE, 'warning'],
        ];
    }

    #[DataProvider('levels')]
    public function testLevels(string $outcome, string $level): void
    {
        $this->writer()->write($this->record(['outcome' => $outcome]));

        $this->assertStringStartsWith($level . ': ', $this->lines()[0]);
    }

    public function testDispatchesTheCakeEvent(): void
    {
        $record = $this->record();
        $this->writer()->write($record);

        $this->assertCount(1, $this->events);
        $this->assertSame('Mcp.audit', $this->events[0]->getName());
        $this->assertSame($record->toArray(), $this->events[0]->getData());
    }

    public function testLogFalseKeepsOnlyTheEvent(): void
    {
        Configure::write('Mcp.audit.log', false);
        $this->writer()->write($this->record());

        $this->assertSame([], $this->lines());
        $this->assertCount(1, $this->events);
    }

    public function testAFailingListenerDoesNotThrow(): void
    {
        $failing = function (): void {
            throw new RuntimeException('listener exploded');
        };
        EventManager::instance()->on('Mcp.audit', $failing);
        try {
            $this->writer()->write($this->record());
        } finally {
            EventManager::instance()->off('Mcp.audit', $failing);
        }

        $this->assertCount(1, $this->lines());
        $this->assertStringContainsString('listener exploded', implode("\n", $this->lines('mcp_test')));
    }

    public function testAFailingLogEngineDoesNotThrow(): void
    {
        Log::setConfig('failing', new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('disk full');
            }
        });

        $this->writer()->write($this->record());

        $this->assertCount(1, $this->events);
    }

    public function testRedactsAtAnyDepthIgnoringCase(): void
    {
        $arguments = $this->writer()->arguments(['user' => ['Password' => 'x', 'name' => 'Ann'], 'token' => 'y']);

        $this->assertSame(['user' => ['Password' => '[redacted]', 'name' => 'Ann'], 'token' => '[redacted]'], $arguments);
    }

    public function testLongStringsAreCut(): void
    {
        $arguments = $this->writer()->arguments(['q' => str_repeat('a', 250)]);

        $this->assertSame(str_repeat('a', 200) . '...', $arguments['q']);
    }

    public function testOversizedArgumentsAreReplaced(): void
    {
        $big = [];
        for ($i = 0; $i < 30; $i++) {
            $big['k' . $i] = str_repeat('b', 199);
        }

        $this->assertSame(['truncated' => true], $this->writer()->arguments($big));
    }

    public function testArgumentsFalseGivesNone(): void
    {
        Configure::write('Mcp.audit.arguments', false);
        $writer = $this->writer();

        $this->assertSame([], $writer->arguments(['q' => 'x']));
        $writer->write($this->record(['arguments' => []]));
        $this->assertStringNotContainsString('args=', $this->lines()[0]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notObjects(): array
    {
        return ['null' => [null], 'list' => [['a', 'b']], 'string' => ['text']];
    }

    #[DataProvider('notObjects')]
    public function testNonObjectArgumentsBecomeEmpty(mixed $arguments): void
    {
        $this->assertSame([], $this->writer()->arguments($arguments));
    }

    public function testInvalidUtf8IsSubstituted(): void
    {
        $writer = $this->writer();
        $writer->write($this->record(['arguments' => $writer->arguments(['q' => "\xB1\x31"])]));

        $this->assertStringContainsString('args={"q":', $this->lines()[0]);
    }

    public function testDeepNestingIsCut(): void
    {
        $nested = 'bottom';
        for ($i = 0; $i < 12; $i++) {
            $nested = ['n' => $nested];
        }

        $arguments = $this->writer()->arguments($nested);

        $value = $arguments;
        for ($depth = 1; $depth < 9; $depth++) {
            $this->assertIsArray($value);
            $value = $value['n'];
        }
        $this->assertSame(['n' => '[...]'], $value);
    }

    public function testMultibyteCutKeepsCharacters(): void
    {
        $arguments = $this->writer()->arguments(['q' => str_repeat('ñ', 250)]);

        $this->assertSame(str_repeat('ñ', 200) . '...', $arguments['q']);
        $this->assertTrue(mb_check_encoding($arguments['q'], 'UTF-8'));
    }
}
