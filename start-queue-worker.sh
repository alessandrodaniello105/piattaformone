#!/bin/bash

# Script to start Laravel queue worker for Redis
# This should be run in the background or via a process manager

echo "Starting Laravel queue worker for Redis..."
echo "Press Ctrl+C to stop"

cd "$(dirname "$0")"

# Use Sail if available, otherwise use direct artisan
if command -v vendor/bin/sail &> /dev/null; then
    vendor/bin/sail artisan queue:work redis --verbose --tries=3 --timeout=120
else
    php artisan queue:work redis --verbose --tries=3 --timeout=120
fi
