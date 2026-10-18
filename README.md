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
application never blocks the server. By default a temporary file is used; pass your own path to keep it.

```php
$server = new PhpServer('127.0.0.1:8080', 'path/to/public', null, '/tmp/php-server.log');
$server->start();

echo $server->getLogFile();
```

`start()` waits until the port accepts connections and throws a `RuntimeException` with the log tail if the
server exits or the port is already in use.
