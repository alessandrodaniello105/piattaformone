#!/bin/bash

echo "============================================"
echo "VERIFICA CONFIGURAZIONE BROADCASTING"
echo "============================================"
echo ""

# Colori
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Verifica BROADCAST_CONNECTION
echo "1. Verifica BROADCAST_CONNECTION..."
broadcast_conn=$(grep "^BROADCAST_CONNECTION=" .env | head -1 | cut -d'=' -f2 | tr -d ' ' | tr -d '"' | tr -d "'")

if [ "$broadcast_conn" = "reverb" ]; then
    echo -e "${GREEN}✅ BROADCAST_CONNECTION=reverb${NC}"
else
    echo -e "${RED}❌ BROADCAST_CONNECTION=$broadcast_conn (dovrebbe essere 'reverb')${NC}"
    echo -e "${YELLOW}   Modifica il file .env e imposta BROADCAST_CONNECTION=reverb${NC}"
fi

echo ""

# Verifica configurazione Laravel
echo "2. Verifica configurazione Laravel..."
if [ -f "vendor/bin/sail" ] || command -v sail &> /dev/null; then
    SAIL_CMD="vendor/bin/sail"
    if command -v sail &> /dev/null; then
        SAIL_CMD="sail"
    fi
    
    laravel_config=$($SAIL_CMD exec laravel.test php artisan tinker --execute="echo config('broadcasting.default');" 2>/dev/null | tr -d '\n' | tr -d ' ' | tail -1)
    
    if [ "$laravel_config" = "reverb" ]; then
        echo -e "${GREEN}✅ Laravel broadcasting configurato su 'reverb'${NC}"
    else
        echo -e "${RED}❌ Laravel broadcasting configurato su '$laravel_config' invece di 'reverb'${NC}"
        echo -e "${YELLOW}   Riavvia l'applicazione Laravel per caricare la nuova configurazione${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  Sail non trovato, salto verifica Laravel${NC}"
    echo -e "${YELLOW}   (Usa 'vendor/bin/sail' o 'sail' se disponibile)${NC}"
fi

echo ""

# Verifica Reverb in esecuzione
echo "3. Verifica se Reverb è in esecuzione..."
if [ -f "vendor/bin/sail" ] || command -v sail &> /dev/null; then
    SAIL_CMD="vendor/bin/sail"
    if command -v sail &> /dev/null; then
        SAIL_CMD="sail"
    fi
    
    if $SAIL_CMD exec laravel.test ps aux 2>/dev/null | grep -q "[r]everb:start"; then
        echo -e "${GREEN}✅ Reverb è in esecuzione${NC}"
    else
        echo -e "${RED}❌ Reverb NON è in esecuzione${NC}"
        echo -e "${YELLOW}   Avvialo con: $SAIL_CMD artisan reverb:start${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  Sail non trovato, salto verifica Reverb${NC}"
    echo -e "${YELLOW}   (Usa 'vendor/bin/sail' o 'sail' se disponibile)${NC}"
fi

echo ""

# Verifica variabili VITE_REVERB
echo "4. Verifica variabili VITE_REVERB..."
if grep -q "^VITE_REVERB_APP_KEY=" .env && grep -q "^VITE_REVERB_HOST=" .env; then
    echo -e "${GREEN}✅ Variabili VITE_REVERB configurate${NC}"
    echo "   VITE_REVERB_HOST=$(grep "^VITE_REVERB_HOST=" .env | cut -d'=' -f2)"
    echo "   VITE_REVERB_PORT=$(grep "^VITE_REVERB_PORT=" .env | cut -d'=' -f2)"
else
    echo -e "${RED}❌ Variabili VITE_REVERB mancanti${NC}"
    echo -e "${YELLOW}   Aggiungi VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT al .env${NC}"
fi

echo ""
echo "============================================"
echo "PROSSIMI PASSI:"
echo "============================================"

# Determina il comando sail da usare
SAIL_CMD="vendor/bin/sail"
if command -v sail &> /dev/null; then
    SAIL_CMD="sail"
fi

echo -e "${YELLOW}1. Se hai modificato .env, riavvia l'applicazione:${NC}"
echo "   $SAIL_CMD restart"
echo "   # oppure"
echo "   $SAIL_CMD down && $SAIL_CMD up -d"
echo ""
echo -e "${YELLOW}2. Avvia Reverb (se non è già in esecuzione):${NC}"
echo "   $SAIL_CMD artisan reverb:start"
echo ""
echo -e "${YELLOW}3. Riavvia Vite per caricare le nuove variabili:${NC}"
echo "   $SAIL_CMD npm run dev"
echo ""
echo -e "${YELLOW}4. Testa creando/modificando un'entità in FIC${NC}"
echo ""
