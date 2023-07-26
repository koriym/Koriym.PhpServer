<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use PHPUnit\Framework\TestCase;

class PhpServerTest extends TestCase
{
    protected PhpServer $phpServer;

    protected function setUp(): void
    {
        $this->phpServer = new PhpServer();
    }

    public function testIsInstanceOfPhpServer(): void
    {
        $actual = $this->phpServer;
        $this->assertInstanceOf(PhpServer::class, $actual);
    }
}
