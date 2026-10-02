<?php

/*
| Bootstrap for `composer test:pgsql` (phpunit.pgsql.xml).
| Loads DB_* values from the gitignored .env.testing.pgsql before Laravel boots.
| Real environment variables win (e.g. DB_HOST passed to a Docker container).
*/

use Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

$file = '.env.testing.pgsql';

if (! is_file(__DIR__.'/../'.$file)) {
    fwrite(STDERR, "Missing {$file}. Copy {$file}.example to {$file} and fill in your local PostgreSQL details.\n");
    exit(1);
}

Dotenv::createImmutable(dirname(__DIR__), $file)->load();

if (($_ENV['DB_DATABASE'] ?? '') === '' || str_contains($_ENV['DB_DATABASE'], 'prod')) {
    fwrite(STDERR, "Refusing to run: DB_DATABASE in {$file} must be a dedicated, non-production test database.\n");
    exit(1);
}
