# MATA Node - Multi-Stage Production Build
ARG PHP_VERSION=8.3.14
ARG COMPOSER_VERSION=2.7.9

# Composer stage (allows variable expansion)
FROM composer:${COMPOSER_VERSION} AS composer

# MATA Dashboard - Multi-Stage Production Build
# Stage 1: Builder - Install dependencies
FROM php:${PHP_VERSION}-apache AS builder

# Install system dependencies for building
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl bcmath gd zip opcache

# Install Composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for better layer caching)
COPY composer.json composer.lock* ./

# Install PHP dependencies (production only, no dev dependencies)
RUN composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist

# Copy application source code
COPY . .

# Run post-install scripts if needed
RUN composer dump-autoload --optimize --no-dev

# ============================================================
# Stage 2: Production Runtime - Clean, minimal image
ARG PHP_VERSION=8.3.14
FROM php:8.3-apache AS production

# Install dependencies, build extensions, and remove build deps in single layer
RUN apt-get update && apt-get install -y \
    # Build dependencies (will be removed after extension compilation)
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    # Runtime dependencies (will remain)
    libpng16-16 \
    libjpeg62-turbo \
    libfreetype6 \
    libonig5 \
    libxml2 \
    libzip4 \
    cron \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl bcmath gd zip opcache \
    && apt-get purge -y --auto-remove \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy application files from builder (includes vendor/ with optimized autoloader)
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

# Create required directories with proper permissions
RUN mkdir -p \
    /var/www/html/temp/api-cache \
    /var/www/html/temp/sessions \
    /var/www/html/temp/templates-cache \
    /var/www/html/logs/cron \
    /var/www/html/config \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 770 /var/www/html/temp \
    && chmod -R 770 /var/www/html/logs \
    && chmod -R 755 /var/www/html/config

# Copy Apache configuration
COPY --chown=root:root docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy production PHP configuration
COPY --chown=root:root docker/php/php-prod.ini /usr/local/etc/php/conf.d/99-custom.ini

# Install crontab for www-data user
COPY --chown=root:root docker/cron/crontab /tmp/crontab
RUN crontab -u www-data /tmp/crontab \
    && rm /tmp/crontab

# Enable Apache modules
RUN a2enmod rewrite headers

# Create health check script
RUN echo '#!/bin/bash\ncurl -f http://localhost/health || exit 1' > /usr/local/bin/healthcheck.sh \
    && chmod +x /usr/local/bin/healthcheck.sh

# Health check configuration
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

# Expose port 80 (HTTPS would be handled by reverse proxy)
EXPOSE 80

# Start cron daemon (www-data crontab will run) and Apache in foreground
CMD ["sh", "-c", "cron && apache2-foreground"]

# Labels for image metadata
LABEL maintainer="XarcDev (mata@xarc.dev | https://github.com/xarcdotdev)"
LABEL description="MATA Dashboard - Application with Apache"
