FROM php:8.2-fpm-alpine

LABEL maintainer="Nebo #15 support@nebo15.com"

ENV HOME=/app
WORKDIR ${HOME}

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        unzip \
        zip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_mysql \
        zip \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apk del .build-deps \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Setup project structure
RUN mkdir -p ${HOME}/storage/app \
    ${HOME}/storage/logs \
    ${HOME}/storage/framework/cache \
    ${HOME}/storage/framework/sessions \
    ${HOME}/storage/framework/views \
    ${HOME}/public/dump

# Prefetch dependencies
COPY composer.* ${HOME}/

RUN composer --no-ansi --no-dev --no-interaction --no-progress --no-scripts --no-autoloader install

# Add project sources.
# To skip some files add them to .dockerignore file
COPY . ${HOME}/

# Install dependencies and generate autoloader
RUN composer --no-ansi --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader install

# Fix paths access rights
RUN chmod 777 -Rf ${HOME}/storage/ ${HOME}/public/dump/

RUN cp ${HOME}/.env.example ${HOME}/.env

CMD ["php-fpm", "-F"]
