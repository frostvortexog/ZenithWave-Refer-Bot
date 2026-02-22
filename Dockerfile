# Use official PHP image
FROM php:8.2-cli

# Install PDO PostgreSQL driver (required for Supabase Postgres)
RUN docker-php-ext-install pdo pdo_pgsql

# App directory
WORKDIR /var/www/html

# Copy all files into container
COPY . .

# Render uses $PORT
ENV PORT=10000

# Expose port (not required but ok)
EXPOSE 10000

# Start PHP built-in server
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /var/www/html"]
