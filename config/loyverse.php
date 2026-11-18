<?php

$connections = [];

foreach (explode(',', (string) env('LOYVERSE_TEAM_IDS', '')) as $id) {
    $id = trim($id);
    if (ctype_digit($id)) {
        $connections[$id] = [
            'token' => env("LOYVERSE_TEAM_{$id}_ACCESS_TOKEN"),
            'merchant' => env("LOYVERSE_TEAM_{$id}_MERCHANT_ID"),
            'collected_methods' => array_values(array_filter(array_map('trim', explode(',', (string) env("LOYVERSE_TEAM_{$id}_COLLECTED_METHOD_IDS", ''))))),
            'store' => env("LOYVERSE_TEAM_{$id}_STORE_ID"),
        ];
    }
}

return ['connections' => $connections];
