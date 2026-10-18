<?php

// The library passes its values as namespaced environment variables, so the plain names it
// used before must not reach the application.
echo getenv('KORIYM_PHP_SERVER_HOST'), '|', (getenv('DOCROOT') === __FILE__ ? 'leaked' : 'clean');
