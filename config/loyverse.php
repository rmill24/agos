<?php

$connections = [];

foreach (explode(',', (string) env('LOYVERSE_TEAM_IDS', '')) as $id) {
    $id = trim($id);
    if (ctype_digit($id)) {
        $connections[$id] = [
            'token' => env("LOYVERSE_TEAM_{$id}_ACCESS_TOKEN"),
            'merchant' => env("LOYVERSE_TEAM_{$id}_MERCHANT_ID"),
            'store' => env("LOYVERSE_TEAM_{$id}_STORE_ID"),
        ];
    }
}

return ['connections' => $connections];
