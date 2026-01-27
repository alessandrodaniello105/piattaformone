#!/bin/bash

# Script per aggiornare automaticamente l'URL ngrok nel file .env
# Utile perché ngrok cambia URL ad ogni riavvio con account free

echo "============================================"
echo "AGGIORNA URL NGROK NEL FILE .env"
echo "============================================"
echo ""

# Colori
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Check se siamo nella directory corretta
if [ ! -f "artisan" ]; then
    echo -e "${RED}❌ Errore: devi eseguire questo script dalla directory root di Laravel${NC}"
    exit 1
fi

# Check se .env esiste
if [ ! -f ".env" ]; then
    echo -e "${RED}❌ Errore: file .env non trovato${NC}"
    exit 1
fi

# Prova a ottenere l'URL ngrok dall'API locale di ngrok
echo "Recupero URL ngrok dall'API locale (http://localhost:4040)..."
NGROK_URL=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null | grep -o '"public_url":"https://[^"]*' | head -1 | cut -d'"' -f4 | sed 's|https://||')

if [ -z "$NGROK_URL" ]; then
    echo -e "${YELLOW}⚠️  Non riesco a recuperare l'URL ngrok automaticamente${NC}"
    echo -e "${YELLOW}   Assicurati che ngrok sia in esecuzione${NC}"
    echo ""
    echo -e "${BLUE}Inserisci manualmente il tuo URL ngrok (senza https://):${NC}"
    read -p "> " NGROK_URL
    
    if [ -z "$NGROK_URL" ]; then
        echo -e "${RED}❌ Nessun URL inserito. Uscita.${NC}"
        exit 1
    fi
fi

echo ""
echo -e "${GREEN}✅ URL ngrok trovato: $NGROK_URL${NC}"
echo ""

# Backup del file .env
cp .env .env.backup
echo "📋 Backup del file .env creato: .env.backup"
echo ""

# Aggiorna le variabili nel file .env
echo "Aggiornamento variabili nel file .env..."

# Funzione per aggiornare o aggiungere una variabile
update_or_add_env_var() {
    local key=$1
    local value=$2
    
    if grep -q "^${key}=" .env; then
        # Variabile esiste, aggiornala
        sed -i "s|^${key}=.*|${key}=${value}|" .env
        echo -e "${GREEN}  ✓ Aggiornato ${key}${NC}"
    else
        # Variabile non esiste, aggiungila
        echo "${key}=${value}" >> .env
        echo -e "${GREEN}  ✓ Aggiunto ${key}${NC}"
    fi
}

# Aggiorna le variabili
update_or_add_env_var "APP_URL" "https://${NGROK_URL}"
update_or_add_env_var "REVERB_HOST" "$NGROK_URL"
update_or_add_env_var "REVERB_PORT" "443"
update_or_add_env_var "REVERB_SCHEME" "https"
update_or_add_env_var "VITE_REVERB_HOST" "$NGROK_URL"
update_or_add_env_var "VITE_REVERB_PORT" "443"
update_or_add_env_var "VITE_REVERB_SCHEME" "https"
update_or_add_env_var "VITE_HMR_HOST" "$NGROK_URL"
update_or_add_env_var "VITE_HMR_CLIENT_PORT" "443"

echo ""
echo -e "${GREEN}✅ File .env aggiornato con successo!${NC}"
echo ""

# Nota: Il file public/hot viene gestito automaticamente da Vite
# con la configurazione corretta in vite.config.js (porta 5173)

echo ""
echo "============================================"
echo "PROSSIMI PASSI:"
echo "============================================"
echo ""
echo "1. Riavvia i container Docker:"
echo -e "   ${BLUE}vendor/bin/sail down && vendor/bin/sail up -d${NC}"
echo ""
echo "2. Riavvia Reverb (in un terminale separato):"
echo -e "   ${BLUE}vendor/bin/sail artisan reverb:start${NC}"
echo ""
echo "3. Riavvia Vite (in un altro terminale):"
echo -e "   ${BLUE}vendor/bin/sail npm run dev${NC}"
echo ""
echo "4. Ricarica la pagina nel browser"
echo ""
echo -e "${YELLOW}💡 Suggerimento: Esegui questo script ogni volta che riavvii ngrok${NC}"
echo ""
