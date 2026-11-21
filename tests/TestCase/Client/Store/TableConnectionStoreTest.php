<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Store;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use Cake\Utility\Security;
use CakeDC\Mcp\Client\Store\Registration;
use CakeDC\Mcp\Client\Store\StoredConnection;
use CakeDC\Mcp\Client\Store\TableConnectionStore;

class TableConnectionStoreTest extends TestCase
{
    private TableConnectionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new TableConnectionStore();
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
    }

    private static function connection(string $userId = '7', string $access = 'access-abc'): StoredConnection
    {
        return new StoredConnection(
            client: 'linear',
            userId: $userId,
            accessToken: $access,
            refreshToken: 'refresh-def',
            expiresAt: 1_900_000_000,
            scope: 'read write',
            issuer: 'https://auth.example.com',
            tokenUrl: 'https://auth.example.com/token',
            revocationUrl: 'https://auth.example.com/revoke',
            clientId: 'client-1',
            clientSecret: 'client-secret-ghi',
            authMethod: 'client_secret_basic',
            resource: 'https://mcp.example.com/mcp',
        );
    }

    public function testSavesAndReadsAConnection(): void
    {
        $this->store->save(self::connection());

        $this->assertEquals(self::connection(), $this->store->get('linear', '7'));
        $this->assertNull($this->store->get('linear', '8'));
        $this->assertNull($this->store->get('github', '7'));
    }

    public function testSecretsAreEncryptedAtRest(): void
    {
        $this->store->save(self::connection());
        $this->store->saveRegistration(new Registration(
            'linear',
            'https://auth.example.com',
            'https://app.example.com/cb',
            'client-1',
            'registration-secret-jkl',
            'client_secret_post',
        ));

        $db = ConnectionManager::get('test');
        $rows = json_encode([
            $db->execute('SELECT * FROM mcp_client_connections')->fetchAll('assoc'),
            $db->execute('SELECT * FROM mcp_client_registrations')->fetchAll('assoc'),
        ]);
        foreach (['access-abc', 'refresh-def', 'client-secret-ghi', 'registration-secret-jkl'] as $secret) {
            $this->assertStringNotContainsString($secret, (string)$rows);
        }
        $this->assertStringContainsString('client-1', (string)$rows);
    }

    public function testSaveReplacesTheUsersConnection(): void
    {
        $this->store->save(self::connection());
        $this->store->save(self::connection('7', 'access-new')->withTokens('access-new', null, null, null));

        $stored = $this->store->get('linear', '7');
        $this->assertNotNull($stored);
        $this->assertSame('access-new', $stored->accessToken);
        $this->assertNull($stored->refreshToken);
        $this->assertNull($stored->expiresAt);
        $this->assertSame(1, ConnectionManager::get('test')
            ->execute('SELECT COUNT(*) AS n FROM mcp_client_connections')->fetch('assoc')['n'] ?? null);
    }

    public function testDeleteRemovesOnlyThatUser(): void
    {
        $this->store->save(self::connection('7'));
        $this->store->save(self::connection('8'));

        $this->store->delete('linear', '7');

        $this->assertNull($this->store->get('linear', '7'));
        $this->assertNotNull($this->store->get('linear', '8'));
    }

    public function testRegistrationsRoundTrip(): void
    {
        $registration = new Registration('linear', 'https://auth.example.com', 'https://app/cb', 'c-1', null, 'none');
        $this->store->saveRegistration($registration);

        $this->assertEquals($registration, $this->store->registration('linear', 'https://auth.example.com'));
        $this->assertNull($this->store->registration('linear', 'https://other.example.com'));

        $replaced = new Registration('linear', 'https://auth.example.com', 'https://app/cb', 'c-2', 's', 'client_secret_basic');
        $this->store->saveRegistration($replaced);
        $this->assertEquals($replaced, $this->store->registration('linear', 'https://auth.example.com'));

        $this->store->deleteRegistration('linear', 'https://auth.example.com');
        $this->assertNull($this->store->registration('linear', 'https://auth.example.com'));
    }

    public function testAnotherKeyCannotReadTheTokens(): void
    {
        $this->store->save(self::connection());

        $other = new TableConnectionStore(str_repeat('another-key-', 4));

        $this->assertNull($other->get('linear', '7'));
    }

    public function testAnOwnKeySurvivesASaltChange(): void
    {
        $key = str_repeat('own-key-', 5);
        (new TableConnectionStore($key))->save(self::connection());
        $salt = Security::getSalt();
        Security::setSalt(str_repeat('another-salt-', 4));
        try {
            $this->assertSame('access-abc', (new TableConnectionStore($key))->get('linear', '7')?->accessToken);
        } finally {
            Security::setSalt($salt);
        }
    }

    public function testOnlyOneRequestClaimsTheRefresh(): void
    {
        $this->store->save(self::connection());

        $this->assertTrue($this->store->claimRefresh('linear', '7', 'claim-a', 60));
        $this->assertFalse($this->store->claimRefresh('linear', '7', 'claim-b', 60));
        // Releasing someone else's claim changes nothing.
        $this->store->releaseRefresh('linear', '7', 'claim-b');
        $this->assertFalse($this->store->claimRefresh('linear', '7', 'claim-b', 60));
        $this->store->releaseRefresh('linear', '7', 'claim-a');
        $this->assertTrue($this->store->claimRefresh('linear', '7', 'claim-b', 60));
    }

    public function testAnExpiredClaimCanBeTakenOver(): void
    {
        $this->store->save(self::connection());
        DateTime::setTestNow(new DateTime('2026-11-20 10:00:00'));
        try {
            $this->assertTrue($this->store->claimRefresh('linear', '7', 'claim-a', 60));
            DateTime::setTestNow(new DateTime('2026-11-20 10:01:01'));
            $this->assertTrue($this->store->claimRefresh('linear', '7', 'claim-b', 60));
        } finally {
            DateTime::setTestNow(null);
        }
    }

    public function testARefreshedGrantIsStoredOnlyUnderItsClaim(): void
    {
        $this->store->save(self::connection());
        $refreshed = self::connection()->withTokens('at-new', 'rt-new', null, null);

        $this->assertFalse($this->store->saveRefreshed($refreshed, 'claim-a'), 'Stored without a claim.');
        $this->assertTrue($this->store->claimRefresh('linear', '7', 'claim-a', 60));
        $this->assertFalse($this->store->saveRefreshed($refreshed, 'claim-b'), 'Stored under another claim.');
        $this->assertTrue($this->store->saveRefreshed($refreshed, 'claim-a'));
        $this->assertSame('at-new', $this->store->get('linear', '7')?->accessToken);

        $this->store->delete('linear', '7');
        $this->assertFalse($this->store->saveRefreshed($refreshed, 'claim-a'), 'A deleted grant came back.');
        $this->assertNull($this->store->get('linear', '7'));
    }

    public function testGrantsAndRegistrationsAreKeyedByAHash(): void
    {
        $this->store->save(self::connection());
        $this->store->saveRegistration(new Registration('linear', 'https://auth.example.com', 'https://app/cb', 'c', null, 'none'));
        $db = ConnectionManager::get('test');

        $row = $db->execute('SELECT connection_key FROM mcp_client_connections')->fetch('assoc');
        $this->assertSame(hash('sha256', "linear\0" . '7'), $row['connection_key'] ?? null);
        $row = $db->execute('SELECT registration_key FROM mcp_client_registrations')->fetch('assoc');
        $this->assertSame(hash('sha256', "linear\0" . 'https://auth.example.com'), $row['registration_key'] ?? null);

        // The unique index is on the hash only, so no collation of the database can merge two keys.
        foreach (['mcp_client_connections' => 'connection_key', 'mcp_client_registrations' => 'registration_key'] as $table => $key) {
            $schema = $db->getSchemaCollection()->describe($table);
            $unique = [];
            foreach ($schema->constraints() as $name) {
                $constraint = $schema->getConstraint($name);
                if (($constraint['type'] ?? null) === 'unique') {
                    $unique[] = $constraint['columns'];
                }
            }
            foreach ($schema->indexes() as $name) {
                $index = $schema->getIndex($name);
                if (($index['type'] ?? null) === 'unique') {
                    $unique[] = $index['columns'];
                }
            }
            $this->assertSame([[$key]], $unique, $table);
        }
    }

    public function testKeysThatDifferOnlyInCaseOrAccentsAreDifferentGrants(): void
    {
        $this->store->save(self::connection('Alice', 'the-token-of-Alice'));
        $this->store->save(self::connection('jose@example.com', 'the-token-of-jose'));

        $this->assertNull($this->store->get('linear', 'alice'));
        $this->assertNull($this->store->get('linear', 'josé@example.com'));
        $this->assertNull($this->store->get('Linear', 'Alice'));
        $this->assertSame('the-token-of-Alice', $this->store->get('linear', 'Alice')?->accessToken);
    }

    public function testLongUserIdsAndIssuersAreStored(): void
    {
        $userId = 'did:web:id.example.com:' . str_repeat('u', 400);
        $issuer = 'https://auth.example.com/' . str_repeat('tenant-', 80);
        $this->store->save(self::connection($userId));
        $this->store->saveRegistration(new Registration('linear', $issuer, 'https://app/cb', 'c', null, 'none'));

        $this->assertSame('access-abc', $this->store->get('linear', $userId)?->accessToken);
        $this->assertSame('c', $this->store->registration('linear', $issuer)?->clientId);
        $columns = ConnectionManager::get('test')->getSchemaCollection()->describe('mcp_client_connections');
        $this->assertSame('text', $columns->getColumnType('user_id'), 'Any user id fits, on every database.');
        $columns = ConnectionManager::get('test')->getSchemaCollection()->describe('mcp_client_registrations');
        $this->assertSame('text', $columns->getColumnType('issuer'), 'Any issuer fits, on every database.');
    }

    public function testNoGrantNoClaim(): void
    {
        $this->assertFalse($this->store->claimRefresh('linear', '7', 'claim-a', 60));
    }

    public function testExpiresWithin(): void
    {
        $connection = self::connection();

        $this->assertFalse($connection->expiresWithin(60, 1_900_000_000 - 61));
        $this->assertTrue($connection->expiresWithin(60, 1_900_000_000 - 60));
        $this->assertFalse($connection->withTokens('a', null, null, null)->expiresWithin(60, 2_000_000_000));
    }
}
