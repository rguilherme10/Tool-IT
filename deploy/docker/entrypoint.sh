#!/bin/sh
# Ponto de entrada da imagem do Manager. Papéis: php-fpm (padrão) | migrate | queue | scheduler | qualquer comando (ex.: php artisan ...)
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY ausente. Gere com: docker compose run --rm --no-deps -e APP_KEY=x app php artisan key:generate --show" >&2
    exit 1
fi

# Volume novo de storage/ chega vazio e pertencendo ao root.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

as_app() { su-exec www-data "$@"; }

# Configuração vem do ambiente do contêiner; compilar a cache aqui (e não no build) mantém a imagem sem segredos.
warm_caches() {
    as_app php artisan config:cache
    as_app php artisan route:cache
    as_app php artisan view:cache
}

role="${1:-php-fpm}"
case "$role" in
    php-fpm)
        warm_caches
        exec php-fpm
        ;;
    migrate)
        # Roda uma única vez por implantação, com o usuário de banco de migração (DB_USERNAME sobrescrito no compose).
        as_app php artisan migrate --force
        as_app php artisan db:seed --force   # só dados de referência (papéis/permissões e regras de alerta); idempotente
        ;;
    queue)
        as_app php artisan config:cache
        exec su-exec www-data php artisan queue:work redis --tries=3 --max-time=3600 --sleep=3
        ;;
    scheduler)
        as_app php artisan config:cache
        exec su-exec www-data php artisan schedule:work
        ;;
    *)
        exec su-exec www-data "$@"
        ;;
esac
