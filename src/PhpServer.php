<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use RuntimeException;

use function array_slice;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function filesize;
use function fsockopen;
use function implode;
use function is_dir;
use function is_file;
use function is_resource;
use function is_writable;
use function max;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function register_shutdown_function;
use function sprintf;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;
use const PHP_EOL;

final class PhpServer
{
    private const START_TIMEOUT_SECONDS = 5.0;
    private const LOG_TAIL_LINES = 20;
    private const LOG_TAIL_BYTES = 64 * 1024;
    private const STOP_TIMEOUT_SECONDS = 5.0;
    private const SIGKILL = 9;

    /** @var list<string> */
    private array $command;

    /** @var resource|null */
    private $process = null;
    private int|null $exitCode = null;
    private string $logFile;
    private bool $ownsLogFile;

    /**
     * Server output goes to $logFile rather than to a pipe. A pipe that nobody reads fills up
     * after about 64 KB, after which the server blocks on its next write and stops answering.
     */
    public function __construct(
        private string $host,
        string $docroot,
        string|null $phpBinary = null,
        string|null $logFile = null,
    ) {
        $this->ownsLogFile = $logFile === null;
        $this->logFile = $logFile ?? $this->tempLogFile();
        $this->assertLogFileIsWritable();
        // An argument list bypasses the shell, so nothing needs quoting and stop() signals PHP itself.
        $this->command = is_dir($docroot)
            ? [$phpBinary ?? PHP_BINARY, '-S', $host, '-t', $docroot]
            : [$phpBinary ?? PHP_BINARY, '-S', $host, $docroot];
        register_shutdown_function(function (): void {
            // @codeCoverageIgnoreStart
            if ($this->process !== null) {
                $this->terminate($this->process);
            }

            // @codeCoverageIgnoreEnd
        });
    }

    public function __destruct()
    {
        // @codeCoverageIgnoreStart
        // The destructor runs after coverage collection has stopped, once the shutdown
        // function has stopped the server and closed the file.
        if ($this->ownsLogFile && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        // @codeCoverageIgnoreEnd
    }

    public function start(): void
    {
        if ($this->isListening()) {
            throw new RuntimeException(sprintf('%s is already in use', $this->host));
        }

        $process = proc_open($this->command, [
            0 => ['pipe', 'r'],
            1 => ['file', $this->logFile, 'a'],
            2 => ['redirect', 1],
        ], $pipes);
        if ($process === false) {
            throw new RuntimeException(sprintf('Could not start the server on %s', $this->host)); // @codeCoverageIgnore
        }

        fclose($pipes[0]);
        $this->process = $process;
        $this->exitCode = null;
        $this->waitUntilListening($process);
    }

    /**
     * Stopping twice is harmless, and a server that was never started is left alone.
     *
     * @throws RuntimeException When the server died on its own before being stopped.
     */
    public function stop(): void
    {
        $process = $this->process;
        if ($process === null) {
            return;
        }

        if (! $this->isRunning($process)) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException(sprintf(
                'Server exited with code %s: %s',
                (string) $this->exitCode,
                $this->logTail(),
            ));
            // @codeCoverageIgnoreEnd
        }

        $this->terminate($process);
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /** @param resource $process */
    private function waitUntilListening($process): void
    {
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if (! $this->isRunning($process)) {
                // The failure is reported here, so a later stop() from cleanup has nothing to add.
                $this->terminate($process);

                throw new RuntimeException(sprintf('Server exited during start-up: %s', $this->logTail()));
            }

            if ($this->isListening()) {
                return;
            }

            usleep(20_000);
        }

        // @codeCoverageIgnoreStart
        $this->terminate($process);

        throw new RuntimeException(sprintf('Server did not start listening on %s: %s', $this->host, $this->logTail()));
        // @codeCoverageIgnoreEnd
    }

    /**
     * proc_get_status() reports the exit code only on the first call after the exit, so it is kept.
     *
     * @param resource $process
     *
     * @phpstan-impure
     */
    private function isRunning($process): bool
    {
        $status = proc_get_status($process);
        if (! $status['running'] && $this->exitCode === null) {
            $this->exitCode = $status['exitcode'];
        }

        return $status['running'];
    }

    /**
     * SIGTERM first, SIGKILL if the server does not exit in time. Windows terminates at once.
     *
     * @param resource $process
     */
    private function terminate($process): void
    {
        if ($this->isRunning($process)) {
            proc_terminate($process);
            $deadline = microtime(true) + self::STOP_TIMEOUT_SECONDS;
            while ($this->isRunning($process)) {
                if (microtime(true) >= $deadline) {
                    // @codeCoverageIgnoreStart
                    proc_terminate($process, self::SIGKILL);
                    break;
                    // @codeCoverageIgnoreEnd
                }

                usleep(20_000);
            }
        }

        proc_close($process);
        $this->process = null;
    }

    private function isListening(): bool
    {
        $colon = strrpos($this->host, ':');
        $address = $colon === false ? $this->host : substr($this->host, 0, $colon);
        $port = $colon === false ? 80 : (int) substr($this->host, $colon + 1);
        // A wildcard bind address is not a connectable target on every platform, so probe loopback.
        $address = match ($address) {
            '0.0.0.0' => '127.0.0.1',
            '[::]' => '[::1]',
            default => $address,
        };

        $socket = @fsockopen($address, $port, timeout: 0.1);
        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** Appending to an existing file needs write access to the file only, not to its directory. */
    private function assertLogFileIsWritable(): void
    {
        $writable = is_file($this->logFile)
            ? is_writable($this->logFile)
            : ! is_dir($this->logFile) && is_dir(dirname($this->logFile)) && is_writable(dirname($this->logFile));
        if (! $writable) {
            throw new RuntimeException(sprintf('Log file is not writable: %s', $this->logFile));
        }
    }

    private function tempLogFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'php-server-');
        if ($file === false) {
            throw new RuntimeException('Could not create a log file for the server'); // @codeCoverageIgnore
        }

        return $file;
    }

    private function logTail(): string
    {
        if (! is_file($this->logFile)) {
            return '';
        }

        $size = filesize($this->logFile);
        if ($size === false || $size === 0) {
            return ''; // @codeCoverageIgnore
        }

        $log = file_get_contents($this->logFile, false, null, max(0, $size - self::LOG_TAIL_BYTES));
        if ($log === false) {
            return ''; // @codeCoverageIgnore
        }

        $lines = explode(PHP_EOL, trim($log));

        return implode(PHP_EOL, array_slice($lines, -self::LOG_TAIL_LINES));
    }
}
