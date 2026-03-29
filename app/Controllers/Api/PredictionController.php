<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Prediction\PairPredictionService;
use Throwable;

final class PredictionController
{
    public function __construct(
        private readonly PairPredictionService $predictions,
        private readonly Config $config
    ) {
    }

    public function show(Request $request): Response
    {
        $symbol = strtoupper(trim((string) ($request->query['symbol'] ?? '')));
        $allowedPairs = array_values(array_unique(array_map(
            static fn (mixed $pair): string => strtoupper((string) $pair),
            $this->config->get('pairs.pairs', [])
        )));

        if ($symbol === '' || !in_array($symbol, $allowedPairs, true)) {
            return Response::json([
                'status' => 'error',
                'message' => 'Ismeretlen vagy nem támogatott szimbólum.',
            ], 422);
        }

        try {
            return Response::json([
                'status' => 'ok',
                'prediction' => $this->predictions->predict($symbol),
            ]);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }
}