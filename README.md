# koriym/php-server

## Simple PHP built-in server utility for testing

Do you ever need to test using a http server? This utility will fast launch a built-in PHP server just when you need it.

## Installation

    composer require koriym/php-server

## Usage

```php
$server = new PhpServer('127.0.0.1:8080', 'path/to/index.php');
$server->start();

// your http test here

$server->stop();
```

You can also specify a public web directory.

```php
$server = new PhpServer('127.0.0.1:8080', 'path/to/public');
```


Server output (access log, `error_log()`, notices) is written to a log file instead of a pipe, so a chatty
application never blocks the server. A temporary file is used by default and removed when the process ends;
pass your own path to keep the output.

```php
$server = new PhpServer('127.0.0.1:8080', 'path/to/public', logFile: sys_get_temp_dir() . '/php-server.log');
$server->start();

echo $server->getLogFile();
```

`start()` waits until the port accepts connections. It throws a `RuntimeException` when the port is already in
use, and when the server exits during start-up, where the message ends with the tail of the log file.
`stop()` is safe to call more than once and throws only when the server died before being stopped.
