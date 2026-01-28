#!/bin/bash

# Script per controllare i log dei webhook FIC
# Uso: ./check-webhook-logs.sh [numero_righe]

LINES=${1:-50}

echo "=== Ultimi $LINES log relativi ai webhook FIC ==="
echo ""

# Cerca nei log Laravel
if [ -f "storage/logs/laravel.log" ]; then
    echo "📋 Log Laravel (webhook FIC):"
    tail -n $LINES storage/logs/laravel.log | grep -i "webhook\|fic" | tail -20
    echo ""
fi

# Cerca richieste POST ai webhook
echo "📥 Richieste POST ai webhook:"
tail -n $LINES storage/logs/laravel.log | grep -i "POST.*webhooks/fic" | tail -10
echo ""

# Cerca errori
echo "❌ Errori nei webhook:"
tail -n $LINES storage/logs/laravel.log | grep -i "error.*webhook\|webhook.*error" | tail -10
echo ""

# Cerca broadcast
echo "📡 Broadcast events:"
tail -n $LINES storage/logs/laravel.log | grep -i "broadcast\|event broadcasted" | tail -10
echo ""

# Statistiche
echo "📊 Statistiche:"
echo "  - Webhook ricevuti (ultimi $LINES righe):"
tail -n $LINES storage/logs/laravel.log | grep -c "FIC Webhook: CloudEvents notification received" || echo "    0"
echo "  - Webhook processati:"
tail -n $LINES storage/logs/laravel.log | grep -c "FIC Webhook: Notification queued for processing" || echo "    0"
echo "  - Errori:"
tail -n $LINES storage/logs/laravel.log | grep -c "ERROR.*webhook\|webhook.*ERROR" || echo "    0"
