#!/bin/bash

echo "============================================"
echo "DEBUG BROADCASTING - DIAGNOSTICA COMPLETA"
echo "============================================"
echo ""

# Colori
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

SAIL_CMD="vendor/bin/sail"
if command -v sail &> /dev/null; then
    SAIL_CMD="sail"
fi

echo "1. Verifica configurazione Broadcasting..."
broadcast_default=$($SAIL_CMD exec laravel.test php artisan tinker --execute="echo config('broadcasting.default');" 2>/dev/null | tail -1 | tr -d ' ')
echo "   Broadcasting default: $broadcast_default"

if [ "$broadcast_default" = "reverb" ]; then
    echo -e "   ${GREEN}✅ OK${NC}"
else
    echo -e "   ${RED}❌ Dovrebbe essere 'reverb'${NC}"
fi

echo ""
echo "2. Verifica configurazione Reverb in broadcasting.php..."
reverb_host=$($SAIL_CMD exec laravel.test php artisan tinker --execute="echo config('broadcasting.connections.reverb.options.host');" 2>/dev/null | tail -1 | tr -d ' ')
reverb_port=$($SAIL_CMD exec laravel.test php artisan tinker --execute="echo config('broadcasting.connections.reverb.options.port');" 2>/dev/null | tail -1 | tr -d ' ')
echo "   Reverb Host (broadcasting): $reverb_host"
echo "   Reverb Port (broadcasting): $reverb_port"

if [ "$reverb_host" = "127.0.0.1" ] || [ "$reverb_host" = "localhost" ]; then
    echo -e "   ${GREEN}✅ OK (localhost per connessione interna)${NC}"
else
    echo -e "   ${YELLOW}⚠️  Verifica che sia corretto${NC}"
fi

echo ""
echo "3. Verifica variabili .env per Broadcasting..."
echo "   REVERB_BROADCAST_HOST: $(grep "^REVERB_BROADCAST_HOST=" .env | cut -d'=' -f2 || echo 'NON CONFIGURATO')"
echo "   REVERB_BROADCAST_PORT: $(grep "^REVERB_BROADCAST_PORT=" .env | cut -d'=' -f2 || echo 'NON CONFIGURATO')"
echo "   REVERB_BROADCAST_SCHEME: $(grep "^REVERB_BROADCAST_SCHEME=" .env | cut -d'=' -f2 || echo 'NON CONFIGURATO')"

echo ""
echo "4. Verifica configurazione Echo (frontend)..."
echo "   VITE_REVERB_HOST: $(grep "^VITE_REVERB_HOST=" .env | cut -d'=' -f2 || echo 'NON CONFIGURATO')"
echo "   VITE_REVERB_PORT: $(grep "^VITE_REVERB_PORT=" .env | cut -d'=' -f2 || echo 'NON CONFIGURATO')"
echo "   VITE_REVERB_SCHEME: $(grep "^VITE_REVERB_SCHEME=" .env | cut -d'=' -f2 || echo 'NON CONFIGURATO')"
vite_key=$(grep "^VITE_REVERB_APP_KEY=" .env | cut -d'=' -f2)
if [ -n "$vite_key" ]; then
    echo "   VITE_REVERB_APP_KEY: ${vite_key:0:10}... (troncato)"
else
    echo "   VITE_REVERB_APP_KEY: NON CONFIGURATO"
fi

echo ""
echo "5. Verifica se Reverb è in esecuzione..."
if $SAIL_CMD exec laravel.test ps aux 2>/dev/null | grep -q "[r]everb:start"; then
    echo -e "   ${GREEN}✅ Reverb è in esecuzione${NC}"
    
    # Verifica porta
    if $SAIL_CMD exec laravel.test curl -s -o /dev/null -w "%{http_code}" http://localhost:6001/ 2>/dev/null | grep -q "200\|400\|404"; then
        echo -e "   ${GREEN}✅ Reverb risponde sulla porta 6001${NC}"
    else
        echo -e "   ${RED}❌ Reverb NON risponde sulla porta 6001${NC}"
    fi
else
    echo -e "   ${RED}❌ Reverb NON è in esecuzione${NC}"
    echo -e "   ${YELLOW}   Avvialo con: $SAIL_CMD artisan reverb:start${NC}"
fi

echo ""
echo "6. Test broadcast manuale..."
echo "   Eseguendo test broadcast..."
$SAIL_CMD exec laravel.test php artisan tinker --execute="
\$event = new \App\Events\WebhookReceived(
    accountId: 1,
    eventGroup: 'test',
    eventType: 'test.event',
    data: ['test' => true],
    ceId: 'test-123',
    ceTime: now()->toIso8601String(),
    ceSubject: 'company:1'
);
broadcast(\$event);
echo 'Broadcast inviato';
" 2>/dev/null | tail -1

echo ""
echo "============================================"
echo "PROBLEMI COMUNI:"
echo "============================================"
echo ""
echo "1. Se Echo non si connette:"
echo "   - Verifica che VITE_REVERB_* siano configurate"
echo "   - Riavvia Vite: $SAIL_CMD npm run dev"
echo "   - Controlla la console del browser per errori WebSocket"
echo ""
echo "2. Se i broadcast non arrivano:"
echo "   - Verifica che REVERB_BROADCAST_HOST sia '127.0.0.1' o 'localhost'"
echo "   - Verifica che REVERB_BROADCAST_PORT sia '6001'"
echo "   - Verifica che Reverb sia in esecuzione"
echo ""
echo "3. Se il WebSocket fallisce:"
echo "   - Verifica che Apache faccia proxy a /app/"
echo "   - Controlla i log di Apache: docker logs apache"
echo "   - Verifica che ngrok sia attivo"
echo ""
