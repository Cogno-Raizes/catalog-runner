FROM php:8.2-apache
RUN apt-get update && apt-get install -y git unzip libzip-dev && docker-php-ext-install zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . /var/www/html
RUN composer install --no-dev --prefer-dist --optimize-autoloader
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf
EXPOSE 80
CMD ["apache2-foreground"]
