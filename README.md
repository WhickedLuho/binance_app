# Binance Watcher

Custom PHP MVC dashboard for Binance market monitoring, multi-timeframe signals, detailed prediction views, manual paper trading, and prediction-driven trade automation.

## Current Scope

The project currently focuses on:

- live Binance market snapshots for a configured symbol list
- multi-timeframe signal generation
- detailed prediction views for a selected pair
- manual paper trading with editable TP/SL levels
- automated paper trading driven by saved automation settings
- scheduler-backed automation heartbeat monitoring

Important: the automation layer currently opens and closes **paper positions**, not real Binance orders.

## Main Features

- Configurable symbol universe from `config/pairs.php`
- Signal dashboard with compact and detailed market views
- Prediction engine with support/resistance zones and long/short scenarios
- Manual paper trading account, open positions, and trade history
- Automation settings panel for:
  - capital allocation by pair
  - enabled entry types (`FUTURES_LONG`, `FUTURES_SHORT`, `SPOT`)
  - reward thresholds
  - ATR and candle-move safety limits
  - cooldown handling
  - auto take-profit / auto stop-loss exits
- Background scheduler container that triggers automation heartbeats
- Runtime status view for automation health, last heartbeat, and recent open/close counts

## Project Structure

- `public/`: web entrypoint and frontend assets
- `app/Core/`: lightweight MVC/framework layer
- `app/Controllers/`: web and API controllers
- `app/Services/Market/`: market snapshot and signal orchestration
- `app/Services/Strategy/`: indicators, risk filters, and signal engine
- `app/Services/Prediction/`: detailed prediction generation
- `app/Services/PaperTrading/`: paper account, position state, and history
- `app/Services/Automation/`: auto-trade settings and automation execution
- `app/Views/`: dashboard templates
- `config/`: app, pair, paper, and strategy settings
- `scripts/automation_scheduler.sh`: automation heartbeat loop
- `storage/cache/`: persisted paper trade and automation state

## HTTP Endpoints

### Web

- `GET /` - dashboard

### API

- `GET /api/signals` - live signals for configured pairs
- `GET /api/prediction?symbol=BTCUSDT` - detailed prediction for a symbol
- `GET /api/auto-trade-settings` - load automation settings
- `POST /api/auto-trade-settings` - save automation settings
- `GET /api/automation/status` - automation runtime and heartbeat health
- `POST /api/automation/heartbeat` - execute one automation cycle
- `GET /api/paper-trades` - paper account overview, open positions, history
- `POST /api/paper-trades` - open a manual paper position
- `POST /api/paper-trades/update` - update TP/SL/notes on an open paper position
- `POST /api/paper-trades/close` - close an open paper position

## Running the Project

Start the full stack with Docker:

```bash
docker compose up --build
```

Default local URL:

- `http://localhost:8080`

This starts:

- `app`: PHP application container
- `nginx`: web server exposed on port `8080`
- `scheduler`: background automation heartbeat worker

## Configuration

The most important configuration files are:

- `config/pairs.php`
  Defines the watched symbols, signal timeframes, and prediction timeframes.
- `config/strategy.php`
  Controls signal confidence, weighting, ATR limits, spike limits, and cooldown rules.
- `config/paper.php`
  Controls paper account defaults, leverage limits, and automation storage files.

## Automation Notes

The current automation flow is:

1. scheduler calls `/api/automation/heartbeat`
2. automation service loads saved settings
3. existing auto-managed paper positions are checked for TP/SL exits
4. configured pairs are evaluated for new entries
5. qualifying entries are opened as paper positions
6. heartbeat status is written to cache for the dashboard runtime panel

Automation state is persisted locally in:

- `storage/cache/auto_trade_settings.json`
- `storage/cache/auto_trade_heartbeat_status.json`
- `storage/cache/paper_trades.json`

## Notes

- The UI and backend are currently designed around paper trading and automation prototyping.
- The repository is configured for PHP `8.2`.
- Frontend assets are plain JS/CSS organized by dashboard area.
