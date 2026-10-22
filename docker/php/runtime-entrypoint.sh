#!/bin/sh
set -eu
php /usr/local/bin/runtime-check.php
exec docker-php-entrypoint "$@"
