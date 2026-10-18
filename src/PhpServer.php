<?php

declare(strict_types=1);

namespace Koriym\PhpServer;

use RuntimeException;
use Symfony\Component\Process\Process;

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
use function register_shutdown_function;
use function sprintf;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;
use const PHP_EOL;

final class PhpServer
{
    private const START_TIMEOUT_SECONDS = 5.0;
    private const LOG_TAIL_LINES = 20;
    private const LOG_TAIL_BYTES = 64 * 1024;

    private Process $process;
    private string $logFile;
    private bool $ownsLogFile;
    private bool $stopped = false;

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
        $phpBinary ??= PHP_BINARY;
        $this->ownsLogFile = $logFile === null;
        $this->logFile = $logFile ?? $this->tempLogFile();
        $this->assertLogFileIsWritable();
        // Placeholders let Symfony Process quote each value for the platform; escapeshellarg()
        // would replace % and ! with spaces on Windows. The names are also exported to the
        // server process, so they carry the package prefix to keep them out of the application.
        $target = is_dir($docroot) ? '-t "${:KORIYM_PHP_SERVER_DOCROOT}"' : '"${:KORIYM_PHP_SERVER_DOCROOT}"';
        $command = sprintf(
            '"${:KORIYM_PHP_SERVER_BINARY}" -S "${:KORIYM_PHP_SERVER_HOST}" %s >> "${:KORIYM_PHP_SERVER_LOG_FILE}" 2>&1',
            $target,
        );
        // exec replaces the shell so that stop() signals PHP itself, not sh.
        $this->process = Process::fromShellCommandline(
            DIRECTORY_SEPARATOR === '\\' ? $command : 'exec ' . $command,
            null,
            [
                'KORIYM_PHP_SERVER_BINARY' => $phpBinary,
                'KORIYM_PHP_SERVER_HOST' => $host,
                'KORIYM_PHP_SERVER_DOCROOT' => $docroot,
                'KORIYM_PHP_SERVER_LOG_FILE' => $this->logFile,
            ],
        );
        register_shutdown_function(function (): void {
            // @codeCoverageIgnoreStart
            $this->process->stop();
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

        $this->process->start();
        $this->stopped = false;
        $this->waitUntilListening();
    }

    /**
     * Stopping twice is harmless: a stop requested by this class is remembered, and a server
     * that was never started is left alone.
     *
     * @throws RuntimeException When the server died on its own before being stopped.
     */
    public function stop(): void
    {
        if (! $this->process->isStarted() || $this->stopped) {
            return;
        }

        if (! $this->process->isRunning()) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException(sprintf(
                'Server exited with code %s: %s',
                (string) $this->process->getExitCode(),
                $this->logTail(),
            ));
            // @codeCoverageIgnoreEnd
        }

        $this->process->stop();
        $this->stopped = true;
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            if (! $this->process->isRunning()) {
                // The failure is reported here, so a later stop() from cleanup has nothing to add.
                $this->stopped = true;

                throw new RuntimeException(sprintf('Server exited during start-up: %s', $this->logTail()));
            }

            if ($this->isListening()) {
                return;
            }

            usleep(20_000);
        }

        // @codeCoverageIgnoreStart
        $this->process->stop();
        $this->stopped = true;

        throw new RuntimeException(sprintf('Server did not start listening on %s: %s', $this->host, $this->logTail()));
        // @codeCoverageIgnoreEnd
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

    private function assertLogFileIsWritable(): void
    {
        $dir = dirname($this->logFile);
        if (
            ! is_dir($dir)
            || ! is_writable($dir)
            || is_dir($this->logFile)
            || (is_file($this->logFile) && ! is_writable($this->logFile))
        ) {
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
