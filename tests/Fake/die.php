<?php

// The built-in server runs the script in its own process, so this kills the server.
posix_kill(posix_getpid(), 9);
