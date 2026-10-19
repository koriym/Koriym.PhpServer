<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use CurlHandle;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function assert;
use function chmod;
use function copy;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function unlink;
use function usleep;

use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;

final class PhpServerTest extends TestCase
{
    private CurlHandle $ch;

    public function setUp(): void
    {
        $ch = curl_init();
        assert($ch instanceof CurlHandle);
        curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8099/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $this->ch = $ch;
        // With workers, /die kills a worker and the server itself keeps running.
        putenv('PHP_CLI_SERVER_WORKERS');

        parent::setUp();
    }

    public function testStart(): PhpServer
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php');
        $server->start();
        $output = curl_exec($this->ch);
        $this->assertSame('Hello World', $output);

        return $server;
    }

    /** @depends testStart */
    public function testStop(PhpServer $server): void
    {
        $server->stop();
        $output = curl_exec($this->ch);
        $this->assertFalse($output);
    }

    public function testStartWithDirectory(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake');
        $server->start();
        $output = curl_exec($this->ch);
        $this->assertSame('Hello World', $output);
        $server->stop();
    }

    /** With output left in an undrained pipe the server blocked on the seventh request. */
    public function testServerKeepsAnsweringWhileLoggingHeavily(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/noisy.php');
        $server->start();
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame('ok', curl_exec($this->ch), "request #{$i}");
        }

        $server->stop();
    }

    public function testServerOutputIsWrittenToLogFile(): void
    {
        $logFile = sys_get_temp_dir() . '/php-server-test-' . uniqid() . '.log';
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php', null, $logFile);
        $server->start();
        curl_exec($this->ch);
        $server->stop();

        $this->assertSame($logFile, $server->getLogFile());
        $this->assertStringContainsString('Development Server', (string) file_get_contents($logFile));
        $this->assertStringContainsString('Accepted', (string) file_get_contents($logFile));
    }

    public function testStartFailsWithLogWhenServerExits(): void
    {
        $server = new PhpServer('127.0.0.1:99999', __DIR__ . '/Fake/index.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid address: 127.0.0.1:99999');
        $server->start();
    }

    public function testStartFailsFastWhenPortIsInUse(): void
    {
        $first = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php');
        $first->start();
        $second = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('127.0.0.1:8099 is already in use');
            $second->start();
        } finally {
            $first->stop();
        }
    }

    public function testStartTwiceFails(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php');
        $server->start();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Server on 127.0.0.1:8099 is already running');
            $server->start();
        } finally {
            $server->stop();
        }
    }

    public function testStopIsIdempotent(): void
    {
        $this->expectNotToPerformAssertions();
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php');
        $server->start();
        $server->stop();
        $server->stop();
    }

    public function testStopBeforeStartDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php');
        $server->stop();
    }

    public function testLogDirectoryMustExist(): void
    {
        $logFile = sys_get_temp_dir() . '/php-server-missing-' . uniqid() . '/server.log';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Log file is not writable');
        new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php', null, $logFile);
    }

    /** Appending only needs write access to the file itself. */
    public function testExistingLogFileInReadOnlyDirectoryIsAccepted(): void
    {
        $dir = sys_get_temp_dir() . '/php-server-readonly-' . uniqid();
        $logFile = $dir . '/server.log';
        mkdir($dir);
        touch($logFile);
        chmod($dir, 0555);

        try {
            $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php', null, $logFile);
            $this->assertSame($logFile, $server->getLogFile());
        } finally {
            chmod($dir, 0755);
            unlink($logFile);
            rmdir($dir);
        }
    }

    public function testServerEnvironmentIsLeftAlone(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/env.php');
        $server->start();
        $output = curl_exec($this->ch);
        $server->stop();

        $this->assertSame('clean', $output);
    }

    /** @requires function posix_kill */
    public function testStopReportsAServerThatDiedOnItsOwn(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/die.php');
        $server->start();
        $this->killServer();

        $message = '';
        try {
            $server->stop();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringStartsWith('Server exited with code 137', $message);
        $server->stop();
    }

    /** @requires function posix_kill */
    public function testStartAgainAfterTheServerDied(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/die.php');
        $server->start();
        $this->killServer();
        $server->start();
        $output = curl_exec($this->ch);
        $server->stop();

        $this->assertSame('alive', $output);
    }

    private function killServer(): void
    {
        curl_setopt($this->ch, CURLOPT_URL, 'http://127.0.0.1:8099/die');
        curl_exec($this->ch);
        curl_setopt($this->ch, CURLOPT_URL, 'http://127.0.0.1:8099/');
        // The connection drops before the process has finished exiting.
        usleep(500_000);
    }

    /** The command is passed as an argument list, so the path is never parsed by a shell. */
    public function testDocrootWithShellCharacters(): void
    {
        $dir = sys_get_temp_dir() . '/php-server-' . uniqid() . " a b%PATH%!\$'";
        mkdir($dir);
        copy(__DIR__ . '/Fake/index.php', $dir . '/index.php');

        try {
            $server = new PhpServer('127.0.0.1:8099', $dir);
            $server->start();
            $output = curl_exec($this->ch);
            $server->stop();
            $this->assertSame('Hello World', $output);
        } finally {
            unlink($dir . '/index.php');
            rmdir($dir);
        }
    }

    /** The tail is read from a file that another process writes, so it can be gone by then. */
    public function testLogTailIsEmptyWhenTheLogFileIsGone(): void
    {
        $logFile = sys_get_temp_dir() . '/php-server-gone-' . uniqid() . '.log';
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/index.php', null, $logFile);
        file_put_contents($logFile, 'an earlier run');
        unlink($logFile);

        $logTail = new ReflectionMethod($server, 'logTail');
        $logTail->setAccessible(true);

        $this->assertSame('', $logTail->invoke($server));
    }

    /** A wildcard bind address is not connectable on every platform; the probe uses loopback. */
    public function testStartWithWildcardAddress(): void
    {
        $server = new PhpServer('0.0.0.0:8099', __DIR__ . '/Fake/index.php');
        $server->start();
        $output = curl_exec($this->ch);
        $server->stop();

        $this->assertSame('Hello World', $output);
    }

    /** start() already reported why the server died; stopping it again must not throw. */
    public function testStopAfterFailedStartIsHarmless(): void
    {
        $server = new PhpServer('127.0.0.1:99999', __DIR__ . '/Fake/index.php');

        $thrown = false;
        try {
            $server->start();
        } catch (RuntimeException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $server->stop();
    }
}
