FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
  && docker-php-ext-install pdo_pgsql \
  && apt-get purge -y --auto-remove \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} router.php"]
