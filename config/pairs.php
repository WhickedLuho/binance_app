<?php

return [
    'default_interval' => '15m',
    'decision_timeframe' => '15m',
    'confirmation_timeframe' => '15m',
    'trigger_timeframe' => '15m',
    'analysis_timeframes' => [
        '15m',
        '1h',
        '2h'
    ],
    'prediction_timeframes' => [
        '15m',
        '1h',
        '4h',
    ],
    'default_limit' => 200,
    'refresh_seconds' => 5,
    'pairs' => [
        'BTCUSDT',
        'ETHUSDT',
        'BNBUSDT',
    ],
];