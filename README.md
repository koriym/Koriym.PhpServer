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

