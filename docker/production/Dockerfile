# Production image. Local development uses Sail (compose.yaml), not this file.
# Build from the repository root: docker build -f docker/production/Dockerfile .
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pcntl intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && npm run build \
    && rm -rf node_modules

COPY --chmod=755 docker/production/start.sh /usr/local/bin/start

CMD ["start"]
