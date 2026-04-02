<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Market\MarketAnalyzer;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class SignalController
{
    public function __construct(
        private readonly MarketAnalyzer $analyzer,
        private readonly Config $config
    ) {
    }

    public function index(Request $request): Response
    {
        try {
            return Response::json([
                'status' => 'ok',
                'generated_at' => $this->nowAtom(),
                'signals' => $this->analyzer->analyzeConfiguredPairs(),
            ]);
        } catch (Throwable $throwable) {
            return Response::json([
                'status' => 'error',
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    private function nowAtom(): string
    {
        $timezone = (string) $this->config->get('app.timezone', 'UTC');

        return (new DateTimeImmutable('now', new DateTimeZone($timezone !== '' ? $timezone : 'UTC')))->format(DATE_ATOM);
    }
}
