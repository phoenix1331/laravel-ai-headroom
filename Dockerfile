FROM dunglas/frankenphp:php8.4

RUN install-php-extensions \
    intl \
    pcntl \
    pcov \
    zip

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /app

CMD ["sleep", "infinity"]
