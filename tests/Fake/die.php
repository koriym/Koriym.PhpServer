<?php

// The built-in server runs scripts inside the server process, so /die kills the server.
if (($_SERVER['REQUEST_URI'] ?? '') === '/die') {
    posix_kill(posix_getpid(), 9);
}

echo 'alive';
