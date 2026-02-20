#!/usr/bin/env bash
set -e

PORT="${PORT:-10000}"

# Make Apache listen on $PORT instead of 80
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# (Optional) Ensure DocumentRoot is correct
# sed -i "s|DocumentRoot /var/www/html|DocumentRoot /var/www/html|g" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
