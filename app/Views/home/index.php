<?php
$enabledTimeframes = array_values(array_unique(array_map(
    static fn (mixed $timeframe): string => (string) $timeframe,
    $analysisTimeframes ?? []
)));
$enabledTimeframesLabel = $enabledTimeframes !== [] ? implode(', ', $enabledTimeframes) : 'none';
$decisionTimeframeLabel = (string) ($decisionTimeframe ?? 'n/a');
?>
<div id="dashboard-root" data-refresh-seconds="<?= (int) $refreshSeconds ?>" data-configured-pairs="<?= htmlspecialchars(json_encode(array_values($pairs ?? [])), ENT_QUOTES, 'UTF-8') ?>">
    <section class="hero">
        <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>
            Binance market watcher with active analysis timeframes:
            <?= htmlspecialchars($enabledTimeframesLabel, ENT_QUOTES, 'UTF-8') ?>.
            Decision timeframe: <?= htmlspecialchars($decisionTimeframeLabel, ENT_QUOTES, 'UTF-8') ?>.
            Each pair is classified into LONG, SHORT, SPOT_BUY, SPOT_SELL or NO_TRADE.
            Raw API endpoint: <a href="/api/signals">/api/signals</a>
        </p>
        <p class="meta">
            Refresh interval: <strong><?= (int) $refreshSeconds ?> sec</strong>
            | Last refresh: <strong id="last-updated"><?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></strong>
            | Quick links:
            <a href="/?refresh=5">5 sec</a>
            <a href="/?refresh=10">10 sec</a>
        </p>
    </section>

    <?php if ($error !== null): ?>
        <div class="error" id="dashboard-error">
            Binance connection or analysis error: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <div class="error" id="dashboard-error" hidden></div>
    <?php endif; ?>

    <section class="card automation-panel" id="automation-panel">
        <div class="prediction-header automation-header">
            <div>
                <div class="prediction-kicker">Auto paper trading</div>
                <h2>Prediction execution control</h2>
                <p class="meta">Prepare the capital plan, reward trigger and volatility guardrails here. When enabled, the dashboard heartbeat can open and manage paper positions from prediction output.</p>
            </div>
            <div class="prediction-actions">
                <button type="button" class="prediction-button alt-button" id="automation-toggle" aria-expanded="true" aria-controls="automation-content">Collapse</button>
                <button type="button" class="prediction-button" id="automation-refresh">Reload settings</button>
            </div>
        </div>

        <div class="automation-content" id="automation-content">
            <div class="prediction-status" id="automation-status" aria-live="polite">Loading auto trade settings...</div>

            <form class="automation-form" id="automation-form">
                <div class="automation-grid">
                    <div class="field field-toggle">
                        <span>Automation</span>
                        <label class="switch">
                            <input type="checkbox" id="automation-enabled">
                            <span class="switch-slider"></span>
                            <span class="switch-label">Enable prediction-based auto paper trading</span>
                        </label>
                    </div>
                    <label class="field">
                        <span>Max capital (USDT)</span>
                        <input type="number" id="automation-total-capital" min="10" step="0.01" value="100">
                    </label>
                    <label class="field">
                        <span>Max open positions</span>
                        <input type="number" id="automation-max-open-positions" min="1" step="1" value="3">
                    </label>
                    <label class="field">
                        <span>Default position type</span>
                        <select id="automation-position-type">
                            <option value="SPOT">Spot only</option>
                            <option value="FUTURES_LONG">Futures long</option>
                            <option value="FUTURES_SHORT">Futures short</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Default margin type</span>
                        <select id="automation-margin-type">
                            <option value="ISOLATED">Isolated</option>
                            <option value="CROSS">Cross</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Default leverage</span>
                        <input type="number" id="automation-leverage" min="1" max="20" step="1" value="5">
                    </label>
                </div>

                <div class="automation-grid automation-grid-secondary">
                    <label class="field">
                        <span>Min reward % (spot)</span>
                        <input type="number" id="automation-min-profit-spot" min="0" step="0.1" value="2.5">
                    </label>
                    <label class="field">
                        <span>Min reward % (long)</span>
                        <input type="number" id="automation-min-profit-long" min="0" step="0.1" value="2.5">
                    </label>
                    <label class="field">
                        <span>Min reward % (short)</span>
                        <input type="number" id="automation-min-profit-short" min="0" step="0.1" value="2.5">
                    </label>
                    <label class="field">
                        <span>Max prediction ATR %</span>
                        <input type="number" id="automation-max-prediction-atr" min="0.1" step="0.1" value="3.5">
                    </label>
                    <label class="field">
                        <span>Max last candle move %</span>
                        <input type="number" id="automation-max-candle-change" min="0.1" step="0.1" value="2.5">
                    </label>
                    <label class="field">
                        <span>Cooldown (minutes)</span>
                        <input type="number" id="automation-cooldown-minutes" min="0" step="1" value="30">
                    </label>
                    <div class="field field-toggle">
                        <span>Auto exits</span>
                        <div class="automation-switch-row">
                            <label class="switch switch-compact">
                                <input type="checkbox" id="automation-close-on-take-profit" checked>
                                <span class="switch-slider"></span>
                                <span class="switch-label">Close on take profit</span>
                            </label>
                            <label class="switch switch-compact">
                                <input type="checkbox" id="automation-close-on-stop-loss" checked>
                                <span class="switch-slider"></span>
                                <span class="switch-label">Close on stop loss</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="automation-summary" id="automation-summary"></div>

                <div class="automation-pairs-head">
                    <div>
                        <h3>Auto-managed open positions</h3>
                        <p class="meta">Prediction-opened paper positions show up here first. Click any card to jump straight into the regular paper position editor.</p>
                    </div>
                </div>
                <div class="automation-open-positions" id="automation-open-positions"></div>

                <div class="automation-pairs-head">
                    <div>
                        <h3>Pair allocation plan</h3>
                        <p class="meta">Turn pairs on or off. If you manually assign one or more percentages, the remaining enabled pairs will split the leftover capital equally.</p>
                    </div>
                </div>
                <div class="automation-pairs" id="automation-pairs"></div>

                <div class="prediction-actions">
                    <button type="submit" class="prediction-button" id="automation-save">Save automation settings</button>
                </div>
            </form>
        </div>
    </section>

    <section class="grid" id="signal-grid">
        <?php foreach ($analysis as $row): ?>
            <?php
            $action = $row['action'] ?? ($row['direction'] ?? 'NO_TRADE');
            $badgeClass = match ($action) {
                'LONG', 'SPOT_BUY' => 'long',
                'SHORT', 'SPOT_SELL' => 'short',
                default => 'neutral',
            };
            $hasError = isset($row['error']) && $row['error'] !== '';
            ?>
            <article class="card" data-symbol="<?= htmlspecialchars((string) $row['symbol'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="signal-card-head">
                    <div>
                        <div class="signal-symbol"><?= htmlspecialchars($row['symbol'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="meta">Decision timeframe: <?= htmlspecialchars($row['interval'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="meta signal-meta-block">
                    Price: <?= number_format((float) $row['price'], 4, '.', ' ') ?><br>
                    Market regime: <?= htmlspecialchars((string) ($row['market_regime'] ?? 'UNKNOWN'), ENT_QUOTES, 'UTF-8') ?><br>
                    Confidence: <?= (int) $row['confidence'] ?> / 100
                </div>

                <div class="reasons">
                    <strong>Scores</strong>
                    <div class="metric"><span>Bull</span><span><?= (int) ($row['bull_score'] ?? 0) ?></span></div>
                    <div class="metric"><span>Bear</span><span><?= (int) ($row['bear_score'] ?? 0) ?></span></div>
                    <div class="metric"><span>Risk penalty</span><span><?= (int) ($row['risk_penalty'] ?? 0) ?></span></div>
                    <div class="metric"><span>RSI 14</span><span><?= number_format((float) ($row['metrics']['rsi14'] ?? 0), 2) ?></span></div>
                    <div class="metric"><span>ATR %</span><span><?= number_format((float) ($row['metrics']['atr_percent'] ?? 0), 2) ?></span></div>
                    <div class="metric"><span>Volume ratio</span><span><?= number_format((float) ($row['metrics']['volume_ratio'] ?? 0), 2) ?></span></div>
                </div>

                <div class="reasons">
                    <strong>Timeframes</strong><br>
                    <?php if (!empty($row['timeframes'])): ?>
                        <?php foreach ($row['timeframes'] as $timeframe => $tf): ?>
                            <div class="metric"><span><?= htmlspecialchars((string) $timeframe, ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars((string) ($tf['bias'] ?? 'NEUTRAL'), ENT_QUOTES, 'UTF-8') ?></span></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        No timeframe data.
                    <?php endif; ?>
                </div>

                <div class="reasons">
                    <strong>Reasons</strong><br>
                    <?= htmlspecialchars(implode(', ', $row['reasons']) ?: 'Indicators do not agree strongly enough.', ENT_QUOTES, 'UTF-8') ?>
                </div>

                <div class="risk">
                    <strong>Risk filter</strong><br>
                    <?= $row['risk']['allowed'] ? 'No blocking condition.' : htmlspecialchars(implode(', ', $row['risk']['flags']), ENT_QUOTES, 'UTF-8') ?>
                </div>

                <div class="prediction-actions">
                    <button type="button" class="prediction-button" data-action="prediction" data-symbol="<?= htmlspecialchars((string) $row['symbol'], ENT_QUOTES, 'UTF-8') ?>">
                        Prediction
                    </button>
                </div>

                <?php if ($hasError): ?>
                    <div class="risk pair-error">
                        <strong>Pair error</strong><br>
                        <?= htmlspecialchars($row['error'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($analysis === [] && $error === null): ?>
            <article class="card">
                <div class="signal-symbol">No data</div>
                <div class="meta">Configured pairs: <?= htmlspecialchars(implode(', ', $pairs), ENT_QUOTES, 'UTF-8') ?></div>
            </article>
        <?php endif; ?>
    </section>

    <section class="card prediction-panel" id="prediction-panel" hidden>
        <div class="prediction-header">
            <div>
                <div class="prediction-kicker">Detailed prediction</div>
                <h2 id="prediction-title">Select a pair</h2>
                <p class="meta" id="prediction-summary">Choose a pair card and the system will build a more detailed scenario from current market data.</p>
            </div>
            <div class="prediction-actions">
                <button type="button" class="prediction-button" id="prediction-refresh" disabled>Refresh prediction</button>
            </div>
        </div>

        <div class="prediction-tabs" id="prediction-tabs" role="tablist" aria-label="Prediction views" hidden>
            <button type="button" class="prediction-tab is-active" id="prediction-tab-prediction" data-tab="prediction" role="tab" aria-controls="prediction-content" aria-selected="true">
                Prediction
            </button>
            <button type="button" class="prediction-tab" id="prediction-tab-paper" data-tab="paper" role="tab" aria-controls="paper-panel" aria-selected="false">
                Open position
            </button>
        </div>

        <section class="tab-panel" id="prediction-content" data-tab-panel="prediction" role="tabpanel" aria-labelledby="prediction-tab-prediction">
            <div class="prediction-status" id="prediction-status" aria-live="polite">Prediction is currently idle.</div>

            <div class="prediction-grid" id="prediction-grid" hidden>
                <article class="prediction-block">
                    <div class="prediction-label">Market bias</div>
                    <div class="prediction-value" id="prediction-bias">-</div>
                    <div class="meta" id="prediction-confidence">Confidence: -</div>
                </article>
                <article class="prediction-block">
                    <div class="prediction-label">Current price</div>
                    <div class="prediction-value" id="prediction-price">-</div>
                    <div class="meta" id="prediction-generated">Generated: -</div>
                </article>
                <article class="prediction-block">
                    <div class="prediction-label">Support zone</div>
                    <div class="prediction-value" id="prediction-support">-</div>
                    <div class="meta">Nearest downside target area</div>
                </article>
                <article class="prediction-block">
                    <div class="prediction-label">Resistance zone</div>
                    <div class="prediction-value" id="prediction-resistance">-</div>
                    <div class="meta">Nearest upside invalidation area</div>
                </article>
            </div>

            <div class="prediction-scenarios" id="prediction-scenarios" hidden></div>
            <div class="prediction-timeframes" id="prediction-timeframes" hidden></div>
        </section>

        <section class="paper-panel tab-panel" id="paper-panel" data-tab-panel="paper" role="tabpanel" aria-labelledby="prediction-tab-paper" hidden>
            <div class="prediction-header paper-header">
                <div>
                    <div class="prediction-kicker">Paper trading cockpit</div>
                    <h3 class="paper-title">Simulated execution</h3>
                    <p class="meta">Open, manage and close simulated spot or futures positions from the current prediction context.</p>
                </div>
            </div>

            <div class="prediction-status" id="paper-status" aria-live="polite">Select a prediction to prepare a simulated trade.</div>

            <form class="paper-form" id="paper-form">
                <div class="paper-form-grid">
                    <label class="field">
                        <span>Symbol</span>
                        <input type="text" id="paper-symbol" readonly>
                    </label>
                    <label class="field">
                        <span>Position type</span>
                        <select id="paper-position-type">
                            <option value="SPOT">Spot buy and hold</option>
                            <option value="FUTURES_LONG">Futures long</option>
                            <option value="FUTURES_SHORT">Futures short</option>
                        </select>
                    </label>
                    <label class="field futures-only">
                        <span>Margin type</span>
                        <select id="paper-margin-type">
                            <option value="ISOLATED">Isolated</option>
                            <option value="CROSS">Cross</option>
                        </select>
                    </label>
                    <label class="field futures-only">
                        <span>Leverage</span>
                        <input type="number" id="paper-leverage" min="1" max="20" step="1" value="5">
                    </label>
                    <label class="field">
                        <span>Capital used</span>
                        <input type="number" id="paper-capital" min="10" step="0.01" value="250">
                    </label>
                    <label class="field">
                        <span>Entry price</span>
                        <input type="number" id="paper-entry-price" min="0.00000001" step="0.00000001">
                    </label>
                    <label class="field">
                        <span>Stop loss</span>
                        <input type="number" id="paper-stop-loss" min="0.00000001" step="0.00000001">
                    </label>
                    <label class="field">
                        <span>Take profit</span>
                        <input type="number" id="paper-take-profit" min="0.00000001" step="0.00000001">
                    </label>
                    <label class="field field-wide">
                        <span>Notes</span>
                        <input type="text" id="paper-notes" maxlength="140" placeholder="Optional context for this simulated trade">
                    </label>
                </div>
                <div class="paper-preview" id="paper-preview"></div>
                <div class="prediction-actions">
                    <button type="submit" class="prediction-button">Open paper position</button>
                </div>
            </form>

            <div class="paper-account-grid" id="paper-account"></div>

            <div class="paper-columns">
                <section class="paper-column">
                    <div class="paper-section-head">
                        <h3>Open positions</h3>
                    </div>
                    <div class="paper-open-list" id="paper-open-positions"></div>
                </section>
                <section class="paper-column">
                    <div class="paper-section-head">
                        <h3>Trade history</h3>
                    </div>
                    <div class="paper-history-list" id="paper-history"></div>
                </section>
            </div>
        </section>
    </section>
</div>
<script src="/assets/js/dashboard.view.js" defer></script>
<script src="/assets/js/dashboard.js" defer></script>
