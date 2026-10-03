FROM richarvey/nginx-php-fpm:3.1.6

WORKDIR /var/www/html

ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache \
    nodejs \
    npm \
    bash \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    postgresql-dev \
    libxml2-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

RUN docker-php-ext-install -j$(nproc) \
    bcmath \
    gd \
    intl \
    mbstring \
    pdo_pgsql \
    pgsql \
    zip \
    exif \
    pcntl

RUN docker-php-ext-enable opcache

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload --optimize --no-dev

RUN npm install
RUN npm run build

RUN chmod +x start.sh

EXPOSE 80

CMD ["/var/www/html/start.sh"]