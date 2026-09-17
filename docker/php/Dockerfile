# syntax=docker/dockerfile:1
# ===========================================================================
#  Shared PHP image for both Sulu AND Sylius (FrankenPHP)
#  Multi-arch: builds identically on linux/arm64 (M5) and linux/amd64 (server).
# ===========================================================================
ARG FRANKENPHP_VERSION=1-php8.5

# Rolling tag instead of "1.2-php8.5": patch tags occasionally disappear
# from the registry and break reproducible builds when they do.
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}

LABEL org.opencontainers.image.title="Sulu & Sylius Kickstarter - PHP"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    NODE_OPTIONS=--max-old-space-size=2048

WORKDIR /app

# --- System packages --------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl ca-certificates gnupg make procps \
        libjpeg-dev libpng-dev libfreetype6-dev libwebp-dev \
        libzip-dev libicu-dev libxml2-dev libxslt1-dev \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions (spec + Sulu/Sylius requirements) -----------------------
# install-php-extensions is included in the FrankenPHP image and compiles
# identically for arm64 and amd64.
RUN install-php-extensions \
        pdo_mysql \
        opcache \
        intl \
        xml \
        zip \
        exif \
        bcmath \
        soap \
        xsl \
        gd \
        redis \
        apcu \
        sockets \
        pcntl \
        imagick

# --- Composer ----------------------------------------------------------------
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# --- Node.js 24 + Yarn (Sylius assets) ---------------------------------------
# 24 is the highest Node version both Sylius and Sulu test in their
# upstream CI (version rule: docs/decisions.md, ADR-11).
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g yarn@1.22.22 \
    && npm cache clean --force \
    && rm -rf /var/lib/apt/lists/*

# --- Configuration -------------------------------------------------------------
COPY docker/php/conf.d/app.ini  /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/Caddyfile       /etc/caddy/Caddyfile

# FrankenPHP runs here in classic request mode, NOT worker mode. Reason:
# Sulu's PHPCR session and parts of the Sylius admin hold state that doesn't
# reset cleanly between requests. For a demo, stability matters more than
# throughput. For production, see CLAUDE.md, section 2.3.

EXPOSE 80

HEALTHCHECK --interval=20s --timeout=5s --start-period=90s --retries=5 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1
