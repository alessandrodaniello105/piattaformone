FROM php:8.4-apache

ARG WWWGROUP=1000
ARG WWWUSER=1000

WORKDIR /var/www/html

# Install system dependencies (incluso bash)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    bash \
    && docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js 22.x using NodeSource repository
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure Apache
RUN a2enmod rewrite
RUN a2enmod headers

# Copy Apache configuration
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Set permissions - usa nome utente "sail" e variabili per UID/GID
RUN groupadd --force -g ${WWWGROUP:-1000} sail && \
    useradd -ms /bin/bash --no-user-group -g ${WWWGROUP:-1000} -u ${WWWUSER:-1000} sail

# Change ownership
RUN chown -R sail:sail /var/www/html

USER sail

EXPOSE 80