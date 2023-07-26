<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use RuntimeException;
use Symfony\Component\Process\Process;

use function error_log;
use function register_shutdown_function;
use function sprintf;
use function str_contains;
use function strpos;

use const PHP_BINARY;

final class PhpServer
{
    private const SIGTERM = 143;

    private Process $process;

    public function __construct(private string $host, string $index, string|null $phpBinary = null)
    {
        $phpBinary ??= PHP_BINARY;
        $this->process = new Process([
            $phpBinary,
            '-S',
            $host,
            $index,
        ]);
        register_shutdown_function(function (): void {
            // @codeCoverageIgnoreStart
            $this->process->stop();
            // @codeCoverageIgnoreEnd
        });
    }

    public function start(): void
    {
        $this->process->start();
        $this->process->waitUntil(function (string $type, string $output): bool {
            if ($type === 'err' && ! str_contains($output, 'started')) {
                // @codeCoverageIgnoreStart
                error_log($output);
                // @codeCoverageIgnoreEnd
            }

            return (bool) strpos($output, $this->host);
        });
    }

    public function stop(): void
    {
        $exitCode = $this->process->stop();
        if ($exitCode !== self::SIGTERM) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException(sprintf('code:%s msg:%s', (string) $exitCode, $this->process->getErrorOutput()));
            // @codeCoverageIgnoreEnd
        }
    }
}
