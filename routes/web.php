<?php

use App\Controllers\HomeController;

return [
    ['GET', '/', [HomeController::class, 'index']],
];

