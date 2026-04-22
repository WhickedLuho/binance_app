<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Automation\AutoTradeExecutionService;
use Throwable;

final class AutoTradeExecutionController
{
    public function __construct(private readonly AutoTradeExecutionService $automation)
    {
    }

    public function heartbeat(Request $request): Response
    {
        try {
            $result = $this->automation->heartbeat();

            return Response::json([
                'status' => 'ok',
                'message' => 'Automation heartbeat completed.',
                'automation' => $result,
                'automation_status' => $this->automation->status(),
                'paper_trading' => $result['paper_trading'] ?? null,
            ]);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function status(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'message' => 'Automation runtime status loaded.',
                'automation_status' => $this->automation->status(),
            ]);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }
}