<?php

use App\Controllers\Api\PaperTradeController;
use App\Controllers\Api\PredictionController;
use App\Controllers\Api\SignalController;

return [
    ['GET', '/api/signals', [SignalController::class, 'index']],
    ['GET', '/api/prediction', [PredictionController::class, 'show']],
    ['GET', '/api/paper-trades', [PaperTradeController::class, 'index']],
    ['POST', '/api/paper-trades', [PaperTradeController::class, 'store']],
    ['POST', '/api/paper-trades/update', [PaperTradeController::class, 'update']],
    ['POST', '/api/paper-trades/close', [PaperTradeController::class, 'close']],
];
