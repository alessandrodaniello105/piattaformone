#!/bin/bash

echo "============================================"
echo "TEST CONNESSIONE REVERB"
echo "============================================"
echo ""

# Colori
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: Verifica processo Reverb
echo "1. Verifica processo Reverb..."
if vendor/bin/sail exec laravel.test ps aux | grep -q "[r]everb:start"; then
    echo -e "${GREEN}✅ Processo Reverb in esecuzione${NC}"
else
    echo -e "${RED}❌ Processo Reverb NON in esecuzione${NC}"
    echo -e "${YELLOW}   Avvialo con: vendor/bin/sail artisan reverb:start${NC}"
    exit 1
fi

echo ""

# Test 2: Verifica porta 6001
echo "2. Verifica risposta sulla porta 6001..."
response=$(vendor/bin/sail exec laravel.test curl -s -o /dev/null -w "%{http_code}" http://localhost:6001/ 2>/dev/null)

if [ "$response" = "404" ] || [ "$response" = "400" ] || [ "$response" = "200" ]; then
    echo -e "${GREEN}✅ Reverb risponde sulla porta 6001 (HTTP code: $response)${NC}"
else
    echo -e "${RED}❌ Reverb NON risponde sulla porta 6001 (HTTP code: $response)${NC}"
fi

echo ""

# Test 3: Verifica proxy Apache per /app/
echo "3. Verifica proxy Apache..."
response=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/app/test 2>/dev/null)

if [ "$response" = "404" ] || [ "$response" = "400" ] || [ "$response" = "500" ]; then
    echo -e "${GREEN}✅ Apache fa proxy a Reverb (HTTP code: $response)${NC}"
    echo -e "${YELLOW}   Nota: 404/400/500 è normale, significa che la connessione arriva${NC}"
else
    echo -e "${RED}❌ Apache NON fa proxy a Reverb (HTTP code: $response)${NC}"
fi

echo ""

# Test 4: Verifica configurazione .env
echo "4. Verifica configurazione .env..."
port=$(vendor/bin/sail exec laravel.test printenv REVERB_SERVER_PORT)

if [ "$port" = "6001" ]; then
    echo -e "${GREEN}✅ REVERB_SERVER_PORT configurato correttamente: $port${NC}"
else
    echo -e "${YELLOW}⚠️  REVERB_SERVER_PORT: $port (dovrebbe essere 6001)${NC}"
    echo -e "${YELLOW}   Aggiungi REVERB_SERVER_PORT=6001 al file .env e riavvia i container${NC}"
fi

echo ""
echo "============================================"
echo "RISULTATO"
echo "============================================"
echo ""
echo "Se tutti i test sono ✅, la configurazione è corretta!"
echo ""
echo "Per testare la connessione WebSocket:"
echo "1. Apri https://lesa-stripier-elena.ngrok-free.dev/dashboard"
echo "2. Apri DevTools > Console"
echo "3. Cerca 'Echo connected!'"
echo ""
