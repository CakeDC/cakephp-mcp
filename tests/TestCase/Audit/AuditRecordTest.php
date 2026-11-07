<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Audit;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Audit\AuditRecord;

class AuditRecordTest extends TestCase
{
    public function testToArrayKeepsTheFieldOrder(): void
    {
        $record = new AuditRecord(
            'tools/call',
            'echo',
            AuditRecord::OK,
            7,
            'client-1',
            'session-1',
            2,
            ['text' => 'hi'],
            null,
            12,
        );

        $this->assertSame(
            ['method', 'name', 'outcome', 'identity', 'client', 'session', 'request', 'arguments', 'error', 'durationMs'],
            array_keys($record->toArray()),
        );
        $this->assertSame('echo', $record->toArray()['name']);
    }
}
