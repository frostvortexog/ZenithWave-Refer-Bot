FROM php:8.2-cli

WORKDIR /app
COPY . /app

# Render provides $PORT. We must bind to it.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]
