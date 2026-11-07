<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Audit;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Audit\AuditSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class AuditSettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        Configure::delete('Mcp');

        $settings = AuditSettings::fromConfig();

        $this->assertFalse($settings->enabled);
        $this->assertSame(['tools/call'], $settings->methods);
        $this->assertTrue($settings->log);
        $this->assertTrue($settings->arguments);
        $this->assertSame(['password', 'token', 'secret'], $settings->redact);
        $this->assertSame(200, $settings->maxLength);
    }

    public function testRedactIsLowerCasedAndReplacesTheDefault(): void
    {
        Configure::write('Mcp.audit.redact', ['ApiKey']);

        $this->assertSame(['apikey'], AuditSettings::fromConfig()->redact);
    }

    public function testAudits(): void
    {
        Configure::delete('Mcp');
        $settings = AuditSettings::fromConfig();

        $this->assertTrue($settings->audits('tools/call'));
        $this->assertFalse($settings->audits('tools/list'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'unknown key' => [['foo' => true], 'Mcp.audit.foo'],
            'enabled not a bool' => [['enabled' => 'yes'], 'Mcp.audit.enabled'],
            'method not audited' => [['methods' => ['tools/list']], 'Mcp.audit.methods'],
            'methods not a list' => [['methods' => 'tools/call'], 'Mcp.audit.methods'],
            'maxLength zero' => [['maxLength' => 0], 'Mcp.audit.maxLength'],
            'redact not strings' => [['redact' => [1]], 'Mcp.audit.redact'],
        ];
    }

    /**
     * @param array<string, mixed> $audit
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsNameTheKey(array $audit, string $key): void
    {
        Configure::write('Mcp.audit', $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"' . $key . '"');
        AuditSettings::fromConfig();
    }
}
