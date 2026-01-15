#!/bin/bash

# Copia .env se non esiste
if [ ! -f .env ]; then
    cp .env.example .env
    echo ".env creato da .env.example"
fi

# Genera APP_KEY se mancante
php artisan key:generate

# Installa dipendenze
composer install
npm install

# Avvia i container
./vendor/bin/sail up -d

# Attendi che PostgreSQL sia pronto
echo "Attendo che PostgreSQL sia pronto..."
sleep 10

# Esegui migrazioni
./vendor/bin/sail artisan migrate

echo "Setup completato! Visita http://localhost"
