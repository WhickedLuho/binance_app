<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Market\MarketAnalyzer;
use Throwable;

final class SignalController
{
    public function __construct(private readonly MarketAnalyzer $analyzer)
    {
    }

    public function index(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'generated_at' => gmdate(DATE_ATOM),
                'signals' => $this->analyzer->analyzeConfiguredPairs(),
            ]);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }
}

