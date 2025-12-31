# MATA Dashboard - Multi-Stage Production Build
# Stage 1: Builder - Install dependencies
FROM php:8.3-apache AS builder

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
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for better layer caching)
COPY composer.json composer.lock* ./

# Install PHP dependencies (production only, no dev dependencies)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist

# Copy application source code
COPY . .

# Run post-install scripts if needed
RUN composer dump-autoload --optimize --no-dev

# ============================================================
# Stage 2: Production Runtime - Clean, minimal image
FROM php:8.3-apache AS production

# Install only runtime dependencies (no build tools)
RUN apt-get update && apt-get install -y \
    libpng16-16 \
    libjpeg62-turbo \
    libfreetype6 \
    libonig5 \
    libxml2 \
    libzip4 \
    cron \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (same as builder)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl bcmath gd zip opcache

# Set working directory
WORKDIR /var/www/html

# Copy application files from builder (includes vendor/ with optimized autoloader)
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

# Create required directories with proper permissions
RUN mkdir -p \
    /var/www/html/temp/api_cache \
    /var/www/html/temp/sessions \
    /var/www/html/temp/templates_cache \
    /var/www/html/logs \
    /var/www/html/config \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/temp \
    && chmod -R 775 /var/www/html/logs \
    && chmod -R 755 /var/www/html/config

# Copy Apache configuration
COPY --chown=root:root docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy production PHP configuration
COPY --chown=root:root docker/php/php-prod.ini /usr/local/etc/php/conf.d/99-custom.ini

# Copy cron configuration
COPY --chown=root:root docker/cron/crontab /etc/cron.d/mata-cron
RUN chmod 0644 /etc/cron.d/mata-cron \
    && crontab /etc/cron.d/mata-cron

# Enable Apache modules
RUN a2enmod rewrite headers ssl

# Create health check script
RUN echo '#!/bin/bash\ncurl -f http://localhost/ || exit 1' > /usr/local/bin/healthcheck.sh \
    && chmod +x /usr/local/bin/healthcheck.sh

# Health check configuration
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh

# Expose port 80 (HTTPS would be handled by reverse proxy)
EXPOSE 80

# Start cron daemon and Apache in foreground
CMD ["sh", "-c", "cron && apache2-foreground"]

# Labels for image metadata
LABEL maintainer="MATA Dashboard Team"
LABEL description="MATA Dashboard - PHP 8.3 Application with Apache"
LABEL version="1.0"
