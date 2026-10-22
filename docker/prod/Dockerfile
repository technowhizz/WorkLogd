# Reuse the exact upstream security release runtime, including patched PHP and Caddy.
# The fork application, dependencies and role-based startup remain its own.
FROM solidtime/solidtime@sha256:ea8cbb7aab29646098bcdcf050e6864e421907c6f5c36307a79bd7a6e34587c0

USER root
RUN rm -rf /var/www/html /etc/supervisor/conf.d/* \
    && mkdir -p /var/www/html \
    && chown 1000:1000 /var/www/html
WORKDIR /var/www/html

ARG APP_BUILD=unknown
LABEL org.opencontainers.image.title="WorkLogd" \
      org.opencontainers.image.source="https://github.com/technowhizz/WorkLogd" \
      org.opencontainers.image.revision="${APP_BUILD}" \
      org.opencontainers.image.version="0.22.1-worklogd"

ENV USER=laravel ROOT=/var/www/html OCTANE_SERVER=frankenphp \
    WITH_HORIZON=false WITH_SCHEDULER=false WITH_REVERB=false WITH_SSR=false

COPY --chown=1000:1000 . ./
COPY docker/prod/deployment/supervisord.conf /etc/supervisord.conf
COPY docker/prod/deployment/supervisord.*.conf /etc/supervisor/conf.d/
COPY docker/prod/deployment/octane/FrankenPHP/supervisord.frankenphp.conf /etc/supervisor/conf.d/
COPY docker/prod/deployment/start-container docker/prod/deployment/healthcheck /usr/local/bin/
COPY docker/prod/deployment/php.ini /usr/local/etc/php/conf.d/99-octane.ini

RUN chmod +x /usr/local/bin/start-container /usr/local/bin/healthcheck \
    && test -z "$(find . -name .git -print -quit)" \
    && rm -f bootstrap/cache/*.php

USER 1000:1000
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache/data storage/logs \
    && composer install --no-dev --no-interaction --no-ansi --prefer-dist \
        --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi

EXPOSE 8000
ENTRYPOINT ["start-container"]
HEALTHCHECK --start-period=30s --interval=30s --timeout=10s --retries=3 CMD healthcheck || exit 1
