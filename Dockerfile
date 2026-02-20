FROM php:8.2-apache

# Enable Apache modules (rewrite is optional but commonly useful)
RUN a2enmod rewrite

# Install PostgreSQL PDO driver for Supabase Postgres
RUN apt-get update && apt-get install -y libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy your app into Apache web root
WORKDIR /var/www/html
COPY . /var/www/html

# Fix permissions (safe default)
RUN chown -R www-data:www-data /var/www/html

# Copy startup script that makes Apache listen on Render's $PORT
COPY start.sh /start.sh
RUN chmod +x /start.sh

# Render provides PORT env; default for local Docker can be 10000
ENV PORT=10000

# Expose the same port (Render routes to $PORT internally)
EXPOSE 10000

CMD ["/start.sh"]
