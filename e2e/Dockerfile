FROM php:8.3-cli-bookworm

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    libsqlite3-dev \
    libzip-dev \
  && docker-php-ext-install pdo_sqlite zip \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY entrypoint.sh /usr/local/bin/orcarail-laravel-entrypoint.sh
RUN chmod +x /usr/local/bin/orcarail-laravel-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/orcarail-laravel-entrypoint.sh"]
