<?php

// The built-in server runs the script in its own process, so /die kills the server.
if ($_SERVER['REQUEST_URI'] === '/die') {
    posix_kill(posix_getpid(), 9);
}

echo 'alive';
