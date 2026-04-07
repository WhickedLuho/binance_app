<?php

return [
    'starting_balance' => 10000.0,
    'maintenance_margin_rate' => 0.005,
    'max_leverage' => 20,
    'max_open_positions' => 12,
    'default_margin_type' => 'ISOLATED',
    'default_leverage' => 5,
    'history_limit' => 30,
    'storage_file' => 'storage/cache/paper_trades.json',
    'automation_storage_file' => 'storage/cache/auto_trade_settings.json',
    'automation_lock_file' => 'storage/cache/auto_trade_heartbeat.lock',
];