<?php

return [
    'default_interval' => '15m',
    'decision_timeframe' => '15m',
    'confirmation_timeframe' => '1h',
    'trigger_timeframe' => '5m',
    'analysis_timeframes' => [
        '15m',
        '1h',
        '4h',
    ],
    'prediction_timeframes' => [
        '15m',
        '1h',
        '4h',
    ],
    'analysis_limit' => 180,
    'prediction_limit' => 120,
    'default_limit' => 180,
    'refresh_seconds' => 10,
    'pairs' => [
        'BTCUSDT',
        'BNBUSDT',
        'ETHUSDT',
        'SOLUSDT',
        'BCHUSDT',
        'XRPUSDT',
        'LTCUSDT',
        'TRXUSDT'
    ],
];
