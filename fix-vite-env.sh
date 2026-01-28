#!/bin/bash

echo "============================================"
echo "FIX VARIABILI VITE_* NEL .env"
echo "============================================"
echo ""

# Colori
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Leggi i valori attuali
REVERB_APP_KEY=$(grep "^REVERB_APP_KEY=" .env | cut -d'=' -f2)
REVERB_HOST=$(grep "^REVERB_HOST=" .env | cut -d'=' -f2)
REVERB_PORT=$(grep "^REVERB_PORT=" .env | cut -d'=' -f2)
REVERB_SCHEME=$(grep "^REVERB_SCHEME=" .env | cut -d'=' -f2)

echo "Valori trovati nel .env:"
echo "  REVERB_APP_KEY: $REVERB_APP_KEY"
echo "  REVERB_HOST: $REVERB_HOST"
echo "  REVERB_PORT: $REVERB_PORT"
echo "  REVERB_SCHEME: $REVERB_SCHEME"
echo ""

# Funzione per aggiornare o aggiungere una variabile
update_vite_var() {
    local key=$1
    local value=$2
    
    if grep -q "^${key}=" .env; then
        # Aggiorna
        sed -i "s|^${key}=.*|${key}=${value}|" .env
        echo -e "${GREEN}✅ Aggiornato ${key}=${value}${NC}"
    else
        # Aggiungi
        echo "${key}=${value}" >> .env
        echo -e "${GREEN}✅ Aggiunto ${key}=${value}${NC}"
    fi
}

# Backup
cp .env .env.backup.vite
echo "📋 Backup creato: .env.backup.vite"
echo ""

# Aggiorna le variabili VITE_* con i valori letterali
echo "Aggiornamento variabili VITE_*..."
update_vite_var "VITE_REVERB_APP_KEY" "$REVERB_APP_KEY"
update_vite_var "VITE_REVERB_HOST" "$REVERB_HOST"
update_vite_var "VITE_REVERB_PORT" "$REVERB_PORT"
update_vite_var "VITE_REVERB_SCHEME" "$REVERB_SCHEME"

echo ""
echo -e "${GREEN}✅ File .env aggiornato!${NC}"
echo ""
echo "============================================"
echo "PROSSIMI PASSI:"
echo "============================================"
echo ""
echo "1. Ferma Vite (Ctrl+C)"
echo "2. Riavvia Vite:"
echo -e "   ${YELLOW}vendor/bin/sail npm run dev${NC}"
echo ""
echo "3. Ricarica la pagina nel browser"
echo ""
echo "4. Verifica nella console che VITE_REVERB_APP_KEY sia corretto:"
echo -e "   ${YELLOW}console.log(import.meta.env.VITE_REVERB_APP_KEY)${NC}"
echo "   Dovrebbe mostrare: $REVERB_APP_KEY"
echo ""
