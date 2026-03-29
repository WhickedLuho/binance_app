<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Market\MarketAnalyzer;
use Throwable;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly MarketAnalyzer $analyzer,
        private readonly Config $config
    ) {
    }

    public function index(Request $request): Response
    {
        $refreshSeconds = max(2, min(300, (int) ($request->query['refresh'] ?? $this->config->get('pairs.refresh_seconds', 5))));
        $analysisTimeframes = array_values(array_unique(array_map(
            static fn (mixed $timeframe): string => (string) $timeframe,
            $this->config->get('pairs.analysis_timeframes', [])
        )));
        $decisionTimeframe = (string) $this->config->get('pairs.decision_timeframe', '1m');

        try {
            $analysis = $this->analyzer->analyzeConfiguredPairs();
            $error = null;
        } catch (Throwable $throwable) {
            $analysis = [];
            $error = $throwable->getMessage();
        }

        return Response::html($this->view->render('home/index', [
            'appName' => $this->config->get('app.name', 'Binance Watcher'),
            'pairs' => array_values(array_unique($this->config->get('pairs.pairs', []))),
            'analysis' => $analysis,
            'error' => $error,
            'refreshSeconds' => $refreshSeconds,
            'analysisTimeframes' => $analysisTimeframes,
            'decisionTimeframe' => $decisionTimeframe,
            'updatedAt' => date('Y-m-d H:i:s'),
        ]));
    }
}