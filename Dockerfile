FROM php:8.2-apache
RUN apt-get update && apt-get install -y git unzip libzip-dev && docker-php-ext-install zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . /var/www/html
RUN composer install --no-dev --prefer-dist --optimize-autoloader
RUN mkdir -p /var/www/html/output/csv /var/www/html/output/logs /var/www/html/output/dashboard \
    && chown -R www-data:www-data /var/www/html/output \
    && chmod -R 775 /var/www/html/output
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf
EXPOSE 80
CMD ["apache2-foreground"]
