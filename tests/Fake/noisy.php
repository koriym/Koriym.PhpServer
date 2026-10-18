<?php

// Logs 10 KB per request, standing in for deprecations, Xdebug traces or a verbose access log.
error_log(str_repeat('x', 10 * 1024));
echo 'ok';
