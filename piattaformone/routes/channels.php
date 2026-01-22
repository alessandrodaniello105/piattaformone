<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canali pubblici per webhook (non richiedono autenticazione)
Broadcast::channel('webhooks', function ($user = null) {
    return true; // Canale pubblico
});

Broadcast::channel('webhooks.account.{accountId}', function ($user, $accountId) {
    return true; // Anche questo è pubblico per semplicità
    // In futuro puoi aggiungere controlli di autorizzazione qui
});