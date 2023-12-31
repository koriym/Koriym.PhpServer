<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use CurlHandle;
use PHPUnit\Framework\TestCase;

use function curl_exec;
use function curl_init;
use function curl_setopt;

use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_URL;

class PhpServerTest extends TestCase
{
    private CurlHandle $ch;

    public function setUp(): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http:/127.0.0.1:8099/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
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

    public function testStartWithDirectory(): PhpServer
    {
        $server = new PhpServer('127.0.0.1:8099', __DIR__ . '/Fake');
        $server->start();
        $output = curl_exec($this->ch);
        $this->assertSame('Hello World', $output);

        return $server;
    }
}
