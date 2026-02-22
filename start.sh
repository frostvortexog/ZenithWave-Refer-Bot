#!/usr/bin/env bash
set -e
PORT="${PORT:-10000}"

echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

sed -i "s/^Listen 80$/Listen ${PORT}/g" /etc/apache2/ports.conf || true
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/000-default.conf || true
sed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf || true

exec apache2-foreground
