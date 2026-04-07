<?php

use App\Controllers\Api\AutoTradeSettingsController;
use App\Controllers\Api\PaperTradeController;
use App\Controllers\Api\PredictionController;
use App\Controllers\Api\SignalController;

return [
    ['GET', '/api/signals', [SignalController::class, 'index']],
    ['GET', '/api/prediction', [PredictionController::class, 'show']],
    ['GET', '/api/auto-trade-settings', [AutoTradeSettingsController::class, 'show']],
    ['POST', '/api/auto-trade-settings', [AutoTradeSettingsController::class, 'update']],
    ['GET', '/api/paper-trades', [PaperTradeController::class, 'index']],
    ['POST', '/api/paper-trades', [PaperTradeController::class, 'store']],
    ['POST', '/api/paper-trades/update', [PaperTradeController::class, 'update']],
    ['POST', '/api/paper-trades/close', [PaperTradeController::class, 'close']],
];