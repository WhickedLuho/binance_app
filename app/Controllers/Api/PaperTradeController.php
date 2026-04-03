<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\PaperTrading\PaperTradeService;
use InvalidArgumentException;
use Throwable;

final class PaperTradeController
{
    public function __construct(private readonly PaperTradeService $paperTrades)
    {
    }

    public function index(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'paper_trading' => $this->paperTrades->overview(),
            ]);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'message' => 'Paper position opened.',
                'paper_trading' => $this->paperTrades->openPosition($request->body),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return Response::json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'message' => 'Paper position updated.',
                'paper_trading' => $this->paperTrades->updatePosition($request->body),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function close(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'message' => 'Paper position closed.',
                'paper_trading' => $this->paperTrades->closePosition($request->body),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }
}
