#!/bin/bash
set -e

PORT=${PORT:-10000}

echo "Starting Apache on port $PORT"

sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

apache2-foreground
