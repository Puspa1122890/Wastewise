FROM php:8.2-apache

# PDO driver for MySQL
RUN docker-php-ext-install pdo_mysql

# Apache: allow .htaccess files and send extra headers if needed
RUN a2enmod rewrite headers
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Hide error details by default in the container. Override with -e APP_ENV=development
ENV APP_ENV=production

EXPOSE 80
