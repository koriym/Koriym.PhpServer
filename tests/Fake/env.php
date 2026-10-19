<?php

// The library passes nothing through the environment, so none of its names may reach the application.
echo getenv('KORIYM_PHP_SERVER_HOST') === false ? 'clean' : 'leaked';
