FROM php:8.2-apache

RUN apt-get update && apt-get install -y unzip git curl && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN docker-php-ext-install pdo pdo_mysql

# Instala dependências PHP (PHPMailer) antes de copiar o app (cache eficiente)
COPY composer.json /var/www/html/composer.json
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction

COPY . /var/www/html/
COPY entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh
RUN chown -R www-data:www-data /var/www/html

CMD ["/entrypoint.sh"]
