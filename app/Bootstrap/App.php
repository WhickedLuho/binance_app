<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Controllers\Api\PaperTradeController;
use App\Controllers\Api\PredictionController;
use App\Controllers\Api\SignalController;
use App\Controllers\HomeController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Services\Binance\BinanceApiClient;
use App\Services\Market\MarketAnalyzer;
use App\Services\PaperTrading\PaperTradeRepository;
use App\Services\PaperTrading\PaperTradeService;
use App\Services\Prediction\PairPredictionService;
use App\Services\Strategy\IndicatorService;
use App\Services\Strategy\RiskFilterService;
use App\Services\Strategy\SignalEngine;

final class App
{
    public function __construct(
        private readonly string $configPath,
        private readonly string $viewPath,
        private readonly string $basePath
    ) {
    }

    public function run(): void
    {
        $container = new Container();
        $config = new Config($this->configPath);
        $timezone = (string) $config->get('app.timezone', 'UTC');
        date_default_timezone_set($timezone !== '' ? $timezone : 'UTC');

        $container->set(Config::class, fn (): Config => $config);
        $container->set(View::class, fn (): View => new View($this->viewPath));
        $container->set(BinanceApiClient::class, fn (): BinanceApiClient => new BinanceApiClient());
        $container->set(IndicatorService::class, fn (): IndicatorService => new IndicatorService());
        $container->set(RiskFilterService::class, fn (Container $c): RiskFilterService => new RiskFilterService(
            $c->get(Config::class)->get('strategy')
        ));
        $container->set(SignalEngine::class, fn (Container $c): SignalEngine => new SignalEngine(
            $c->get(Config::class)->get('strategy'),
            $c->get(Config::class)->get('pairs'),
            $c->get(IndicatorService::class),
            $c->get(RiskFilterService::class)
        ));
        $container->set(PairPredictionService::class, fn (Container $c): PairPredictionService => new PairPredictionService(
            $c->get(Config::class),
            $c->get(BinanceApiClient::class),
            $c->get(IndicatorService::class)
        ));
        $container->set(PaperTradeRepository::class, fn (Container $c): PaperTradeRepository => new PaperTradeRepository(
            $this->basePath . '/' . ltrim((string) $c->get(Config::class)->get('paper.storage_file', 'storage/cache/paper_trades.json'), '/')
        ));
        $container->set(PaperTradeService::class, fn (Container $c): PaperTradeService => new PaperTradeService(
            $c->get(Config::class),
            $c->get(BinanceApiClient::class),
            $c->get(PaperTradeRepository::class)
        ));
        $container->set(MarketAnalyzer::class, fn (Container $c): MarketAnalyzer => new MarketAnalyzer(
            $c->get(Config::class),
            $c->get(BinanceApiClient::class),
            $c->get(SignalEngine::class)
        ));
        $container->set(HomeController::class, fn (Container $c): HomeController => new HomeController(
            $c->get(View::class),
            $c->get(MarketAnalyzer::class),
            $c->get(Config::class)
        ));
        $container->set(SignalController::class, fn (Container $c): SignalController => new SignalController(
            $c->get(MarketAnalyzer::class),
            $c->get(Config::class)
        ));
        $container->set(PredictionController::class, fn (Container $c): PredictionController => new PredictionController(
            $c->get(PairPredictionService::class),
            $c->get(Config::class)
        ));
        $container->set(PaperTradeController::class, fn (Container $c): PaperTradeController => new PaperTradeController(
            $c->get(PaperTradeService::class)
        ));

        $router = new Router($container);
        foreach (require $this->basePath . '/routes/web.php' as [$method, $uri, $handler]) {
            $router->add($method, $uri, $handler);
        }
        foreach (require $this->basePath . '/routes/api.php' as [$method, $uri, $handler]) {
            $router->add($method, $uri, $handler);
        }

        $request = Request::capture();
        $response = $router->dispatch($request);
        $response->send();
    }
}
