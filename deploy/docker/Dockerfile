# syntax=docker/dockerfile:1.7
#
# Imagem do Manager (Laravel). Contexto de build: ./manager; a pasta deploy/docker entra como contexto nomeado "docker" (ver compose.yaml).
# Alvos:
#   app  -> PHP-FPM + código (usado por app, migrate, queue e scheduler)
#   web  -> nginx sem privilégios + public/ (arquivos estáticos e proxy FastCGI para o app)
#
# NÃO VALIDADO: este ambiente de desenvolvimento não tem Docker. Primeiro build real = pendência L25.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------------------------------------------------------------- front-end (Vite + Tailwind)
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# Tailwind v4 varre o projeto atrás das classes usadas: views, JS e o PHP que monta classes.
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
RUN npm run build

# ---------------------------------------------------------------- base PHP (extensões + runtime)
FROM php:${PHP_VERSION}-fpm-alpine AS base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache su-exec tzdata \
    && install-php-extensions pdo_mysql intl zip opcache redis pcntl \
    && rm -f /usr/local/bin/install-php-extensions
WORKDIR /var/www/html

# ---------------------------------------------------------------- dependências PHP (só produção)
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts \
    && composer check-platform-reqs

# ---------------------------------------------------------------- alvo: app (PHP-FPM)
FROM base AS app
COPY --from=docker php/php.ini /usr/local/etc/php/conf.d/zz-toolit.ini
COPY --from=docker php/fpm.conf /usr/local/etc/php-fpm.d/zz-toolit.conf
COPY --from=docker entrypoint.sh /usr/local/bin/toolit-entrypoint
RUN chmod +x /usr/local/bin/toolit-entrypoint

COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /build/public/build /var/www/html/public/build
RUN rm -rf tests storage/logs/* storage/framework/cache/data/* \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && su-exec www-data php artisan package:discover --ansi

ENV APP_ENV=production APP_DEBUG=false
EXPOSE 9000
ENTRYPOINT ["toolit-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------- alvo: web (nginx sem privilégios)
FROM nginxinc/nginx-unprivileged:1.27-alpine AS web
COPY --from=docker nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
EXPOSE 8080 8443
