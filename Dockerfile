FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]
