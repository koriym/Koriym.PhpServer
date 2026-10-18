<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use CurlHandle;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function curl_exec;
use function curl_init;
use function curl_setopt;
use function file_get_contents;
use function sys_get_temp_dir;
use function uniqid;

use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;

class PhpServerTest extends TestCase
{
    private CurlHandle $ch;

    public function setUp(): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8099/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $this->ch = $ch;

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

    public function testServerEnvironmentIsNamespaced(): void
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake/env.php');
        $server->start();
        $output = curl_exec($this->ch);
        $server->stop();

        $this->assertSame('127.0.0.1:8099|clean', $output);
    }
}
