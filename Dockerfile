FROM php:8.0-fpm

LABEL maintainer="Maintainer - Lei Shi"

RUN apt-get update && apt-get install -y zlib1g-dev libpng-dev libjpeg62-turbo-dev git unzip

RUN docker-php-source extract \
    && docker-php-ext-install mysqli gd bcmath pdo_mysql \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && pecl install redis-5.3.4 \
    && docker-php-ext-enable redis \
    && docker-php-source delete

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php -r "if (hash_file('sha384', 'composer-setup.php') === '756890a4488ce9024fc62c56153228907f1545c228516cbf63f885e036d37e9a59d27d63f46af1d4d07ee0f76181c7d3') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" \
    && php composer-setup.php \
    && php -r "unlink('composer-setup.php');"

RUN mv composer.phar /usr/local/bin/composer

COPY ./nexusphp /var/www/html

WORKDIR /var/www/html

RUN composer install

RUN cp -R nexus/Install/install public/

RUN chmod -R 0777 /var/www/html