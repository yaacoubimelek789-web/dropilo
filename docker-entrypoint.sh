#!/bin/bash
set -e
# Windows dev php.ini must never load inside Linux Apache (breaks pdo_mysql).
rm -f /var/www/html/php.ini /var/www/html/start-php.ps1
exec "$@"
