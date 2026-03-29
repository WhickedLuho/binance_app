<?php

use App\Controllers\Api\PredictionController;
use App\Controllers\Api\SignalController;

return [
    ['GET', '/api/signals', [SignalController::class, 'index']],
    ['GET', '/api/prediction', [PredictionController::class, 'show']],
];
