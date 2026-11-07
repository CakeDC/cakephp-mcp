<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Http;

use Cake\Http\Response;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Http\ChallengeStatus;

class ChallengeStatusTest extends TestCase
{
    /**
     * @var list<callable>
     */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registered = [];
        ChallengeStatus::$register = function (callable $callback): void {
            $this->registered[] = $callback;
        };
    }

    protected function tearDown(): void
    {
        ChallengeStatus::$register = null;
        parent::tearDown();
    }

    public function testA403ChallengeKeepsItsStatus(): void
    {
        ChallengeStatus::keep((new Response())->withStatus(403)->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"'));

        $this->assertCount(1, $this->registered);
        ($this->registered[0])();
        $this->assertSame(403, http_response_code());
    }

    public function testNothingForA401OrWithoutAChallenge(): void
    {
        ChallengeStatus::keep((new Response())->withStatus(401)->withHeader('WWW-Authenticate', 'Bearer'));
        ChallengeStatus::keep((new Response())->withStatus(403));
        ChallengeStatus::keep((new Response())->withStatus(200));

        $this->assertSame([], $this->registered);
    }

    /**
     * Through a real PHP server and Cake's ResponseEmitter: without the fix PHP answers 401.
     */
    public function testARealServerAnswers403(): void
    {
        ChallengeStatus::$register = null;
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            $this->markTestSkipped('Needs proc_open() and curl.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $router = dirname(__DIR__, 2) . '/Support/Emit/challenge-403.php';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        try {
            $status = fn(string $query): int => $this->httpStatus('http://127.0.0.1:' . $port . '/' . $query);
            $deadline = microtime(true) + 5;
            while ($status('') === 0 && microtime(true) < $deadline) {
                usleep(50000);
            }

            $this->assertSame(401, $status(''), 'PHP no longer rewrites the status: the fix may be obsolete');
            $this->assertSame(403, $status('?keep=1'));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    private function httpStatus(string $url): int
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($curl);

        return (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    }
}
