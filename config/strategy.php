<?php

return [
    'min_confidence' => 70,
    'spot_confidence' => 58,
    'min_volume_ratio' => 0.9,
    'max_atr_percent' => 3.5,
    'max_spike_percent' => 2.5,
    'cooldown_seconds' => 30,
    'timeframe_weights' => [
        '1m' => 0.6,
        '5m' => 1.0,
        '15m' => 0.9,
    ],
    'weights' => [
        'trend' => 30,
        'momentum' => 20,
        'macd' => 15,
        'volume' => 15,
        'structure' => 20,
    ],
];
