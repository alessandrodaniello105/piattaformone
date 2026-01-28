#!/bin/bash

echo "============================================"
echo "CONTROLLO CONFIGURAZIONE REVERB"
echo "============================================"
echo ""

# Colori
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check se siamo nella directory corretta
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ Errore: devi eseguire questo script dalla directory root di Laravel${NC}"
    exit 1
fi

# Check variabili d'ambiente nel .env
echo "1. Controllo variabili d'ambiente nel .env..."
missing_vars=()

required_vars=("BROADCAST_CONNECTION" "REVERB_APP_ID" "REVERB_APP_KEY" "REVERB_APP_SECRET" "REVERB_HOST" "REVERB_PORT" "REVERB_SCHEME" "VITE_REVERB_APP_KEY" "VITE_REVERB_HOST" "VITE_REVERB_PORT" "VITE_REVERB_SCHEME")

for var in "${required_vars[@]}"; do
    if ! grep -q "^${var}=" .env 2>/dev/null; then
        missing_vars+=("$var")
    fi
done

if [ ${#missing_vars[@]} -eq 0 ]; then
    echo -e "${GREEN}✅ Tutte le variabili richieste sono presenti nel .env${NC}"
else
    echo -e "${RED}❌ Variabili mancanti nel .env:${NC}"
    for var in "${missing_vars[@]}"; do
        echo -e "${RED}   - $var${NC}"
    done
    echo ""
    echo -e "${YELLOW}Aggiungi queste variabili al tuo file .env (vedi REVERB_SETUP.md)${NC}"
fi

echo ""

# Check se Reverb è in ascolto
echo "2. Controllo se Reverb è in ascolto sulla porta 8080..."
if vendor/bin/sail exec laravel.test curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/ 2>/dev/null | grep -q "200\|400\|403"; then
    echo -e "${GREEN}✅ Reverb è in ascolto sulla porta 8080${NC}"
else
    echo -e "${RED}❌ Reverb non risponde sulla porta 8080${NC}"
    echo -e "${YELLOW}   Avvialo con: vendor/bin/sail artisan reverb:start${NC}"
fi

echo ""

# Check configurazione broadcasting
echo "3. Controllo configurazione broadcasting..."
broadcast_driver=$(vendor/bin/sail exec laravel.test php artisan tinker --execute="echo config('broadcasting.default');" 2>/dev/null | tr -d '\n' | grep -o '[a-z]*')

if [ "$broadcast_driver" = "reverb" ]; then
    echo -e "${GREEN}✅ Broadcasting configurato su 'reverb'${NC}"
else
    echo -e "${RED}❌ Broadcasting configurato su '$broadcast_driver' invece di 'reverb'${NC}"
    echo -e "${YELLOW}   Imposta BROADCAST_CONNECTION=reverb nel .env${NC}"
fi

echo ""

# Mostra configurazione Reverb attuale
echo "4. Configurazione Reverb attuale:"
echo ""
vendor/bin/sail exec laravel.test sh -c 'echo "   REVERB_HOST: $(printenv REVERB_HOST)"'
vendor/bin/sail exec laravel.test sh -c 'echo "   REVERB_PORT: $(printenv REVERB_PORT)"'
vendor/bin/sail exec laravel.test sh -c 'echo "   REVERB_SCHEME: $(printenv REVERB_SCHEME)"'
vendor/bin/sail exec laravel.test sh -c 'echo "   VITE_REVERB_HOST: $(printenv VITE_REVERB_HOST)"'
vendor/bin/sail exec laravel.test sh -c 'echo "   VITE_REVERB_PORT: $(printenv VITE_REVERB_PORT)"'

echo ""
echo "============================================"
echo "PROSSIMI PASSI:"
echo "============================================"

if [ ${#missing_vars[@]} -gt 0 ]; then
    echo -e "${YELLOW}1. Aggiungi le variabili mancanti al file .env${NC}"
    echo -e "${YELLOW}2. Riavvia i container: vendor/bin/sail down && vendor/bin/sail up -d${NC}"
    echo -e "${YELLOW}3. Avvia Reverb: vendor/bin/sail artisan reverb:start${NC}"
    echo -e "${YELLOW}4. Avvia Vite: vendor/bin/sail npm run dev${NC}"
else
    echo -e "${GREEN}La configurazione sembra corretta!${NC}"
    echo ""
    echo "Se Echo ancora non si connette:"
    echo "1. Riavvia Vite: vendor/bin/sail npm run dev"
    echo "2. Riavvia Reverb: vendor/bin/sail artisan reverb:start"
    echo "3. Verifica nel browser (DevTools > Console) che import.meta.env.VITE_REVERB_HOST sia impostato"
fi

echo ""
