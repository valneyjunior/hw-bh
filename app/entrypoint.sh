#!/bin/sh
set -e
php /var/www/html/cli/seed.php
exec apache2-foreground
