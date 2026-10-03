FROM richarvey/nginx-php-fpm:3.1.6

WORKDIR /var/www/html

ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy Composer manifest files first, so Docker can cache dependencies
COPY composer.json composer.lock ./

# Install PHP libraries while avoiding Laravel Composer scripts at build time
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# Copy the rest of the Laravel/SnapBuy source code
COPY . .

# The frontend Vite build was already generated locally and committed
# in public/build, so npm is not required in this container.

RUN chmod +x /var/www/html/start.sh

EXPOSE 80

CMD ["/var/www/html/start.sh"]