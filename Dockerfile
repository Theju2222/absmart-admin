FROM richarvey/nginx-php-fpm:3.2.1

WORKDIR /var/www/html

ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy Composer files first so Docker can cache package installation
COPY composer.json composer.lock ./

# Install PHP dependencies without Laravel post-install scripts
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# Copy the remaining SnapBuy/Laravel source files
COPY . .

RUN chmod +x /var/www/html/start.sh

EXPOSE 80

CMD ["/var/www/html/start.sh"]