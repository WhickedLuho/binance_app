<?php
$enabledTimeframes = array_values(array_unique(array_map(
    static fn (mixed $timeframe): string => (string) $timeframe,
    $analysisTimeframes ?? []
)));
$enabledTimeframesLabel = $enabledTimeframes !== [] ? implode(', ', $enabledTimeframes) : 'none';
$decisionTimeframeLabel = (string) ($decisionTimeframe ?? 'n/a');
$initialAnalysisJson = htmlspecialchars(json_encode(array_values($analysis ?? [])), ENT_QUOTES, 'UTF-8');
$configuredPairsJson = htmlspecialchars(json_encode(array_values($pairs ?? [])), ENT_QUOTES, 'UTF-8');
?>
<div
    id="dashboard-root"
    data-refresh-seconds="<?= (int) $refreshSeconds ?>"
    data-configured-pairs="<?= $configuredPairsJson ?>"
    data-initial-analysis="<?= $initialAnalysisJson ?>"
>
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
        <div class="panel-head automation-header">
            <div>
                <div class="prediction-kicker">Automation console</div>
                <h2>Auto paper trading</h2>
                <p class="meta">A compact control room for runtime health, entry settings and live auto-managed positions.</p>
            </div>
            <div class="prediction-actions">
                <button type="button" class="prediction-button alt-button" id="automation-toggle" aria-expanded="true" aria-controls="automation-content">
                    <span data-role="toggle-label">Collapse</span>
                </button>
                <button type="button" class="prediction-button" id="automation-refresh">Reload</button>
            </div>
        </div>

        <div class="automation-content" id="automation-content">
            <div class="prediction-status" id="automation-status" aria-live="polite">Loading auto trade settings...</div>

            <section class="console-block">
                <div class="section-mini-head">
                    <div>
                        <h3>Scheduler runtime</h3>
                        <p class="meta">Live health snapshot from the latest heartbeat.</p>
                    </div>
                </div>
                <div class="automation-runtime" id="automation-runtime">
                    <div class="paper-empty">Waiting for the first scheduler heartbeat...</div>
                </div>
            </section>

            <form class="automation-form" id="automation-form">
                <section class="console-section" id="automation-settings-section">
                    <button type="button" class="section-toggle" id="automation-settings-toggle" aria-expanded="true" aria-controls="automation-settings-body">
                        <span class="section-toggle-copy">
                            <strong>Settings</strong>
                            <span>Capital plan, entry types, safety rules and pair allocation.</span>
                        </span>
                        <span class="section-toggle-action" data-role="toggle-label">Collapse</span>
                    </button>
                    <div class="section-summary-grid" id="automation-summary"></div>
                    <div class="console-section-body" id="automation-settings-body">
                        <div class="automation-settings-shell">
                            <section class="automation-settings-section">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Capital plan</h3>
                                        <p class="meta">Keep concurrency, capital and the main switch together.</p>
                                    </div>
                                </div>
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
                                        <input type="number" id="automation-max-open-positions" min="1" step="1" value="2">
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Entry types</h3>
                                        <p class="meta">Choose the execution modes the engine should evaluate.</p>
                                    </div>
                                </div>
                                <div class="automation-entry-list">
                                    <label class="automation-entry-switch" for="automation-entry-futures-long">
                                        <span class="automation-entry-copy">
                                            <strong>Futures long</strong>
                                            <span>Bullish leveraged entry</span>
                                        </span>
                                        <span class="automation-entry-toggle">
                                            <input type="checkbox" id="automation-entry-futures-long">
                                            <span class="switch-slider"></span>
                                        </span>
                                    </label>
                                    <label class="automation-entry-switch" for="automation-entry-futures-short">
                                        <span class="automation-entry-copy">
                                            <strong>Futures short</strong>
                                            <span>Bearish leveraged entry</span>
                                        </span>
                                        <span class="automation-entry-toggle">
                                            <input type="checkbox" id="automation-entry-futures-short">
                                            <span class="switch-slider"></span>
                                        </span>
                                    </label>
                                    <label class="automation-entry-switch" for="automation-entry-spot">
                                        <span class="automation-entry-copy">
                                            <strong>Spot long</strong>
                                            <span>Unleveraged safer baseline</span>
                                        </span>
                                        <span class="automation-entry-toggle">
                                            <input type="checkbox" id="automation-entry-spot">
                                            <span class="switch-slider"></span>
                                        </span>
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section" id="automation-futures-settings">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Futures defaults</h3>
                                        <p class="meta">Only applied when a futures entry type is enabled.</p>
                                    </div>
                                </div>
                                <div class="automation-grid">
                                    <label class="field">
                                        <span>Default margin type</span>
                                        <select id="automation-margin-type">
                                            <option value="ISOLATED">Isolated</option>
                                            <option value="CROSS">Cross</option>
                                        </select>
                                    </label>
                                    <label class="field">
                                        <span>Default leverage</span>
                                        <input type="number" id="automation-leverage" min="1" max="20" step="1" value="4">
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section" id="automation-futures-long-settings">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Futures long trigger</h3>
                                        <p class="meta">Minimum expected reward for bullish futures entries.</p>
                                    </div>
                                </div>
                                <div class="automation-grid">
                                    <label class="field">
                                        <span>Min reward % (long)</span>
                                        <input type="number" id="automation-min-profit-long" min="0" step="0.1" value="1.2">
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section" id="automation-futures-short-settings">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Futures short trigger</h3>
                                        <p class="meta">Minimum expected reward for bearish futures entries.</p>
                                    </div>
                                </div>
                                <div class="automation-grid">
                                    <label class="field">
                                        <span>Min reward % (short)</span>
                                        <input type="number" id="automation-min-profit-short" min="0" step="0.1" value="1.2">
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section" id="automation-spot-settings">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Spot trigger</h3>
                                        <p class="meta">Minimum expected reward for spot entries.</p>
                                    </div>
                                </div>
                                <div class="automation-grid">
                                    <label class="field">
                                        <span>Min reward % (spot)</span>
                                        <input type="number" id="automation-min-profit-spot" min="0" step="0.1" value="0.8">
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Safety guardrails</h3>
                                        <p class="meta">Keep noisy and overextended setups out.</p>
                                    </div>
                                </div>
                                <div class="automation-grid automation-grid-secondary">
                                    <label class="field">
                                        <span>Max prediction ATR %</span>
                                        <input type="number" id="automation-max-prediction-atr" min="0.1" step="0.1" value="3.0">
                                    </label>
                                    <label class="field">
                                        <span>Max last candle move %</span>
                                        <input type="number" id="automation-max-candle-change" min="0.1" step="0.1" value="1.0">
                                    </label>
                                    <label class="field">
                                        <span>Cooldown (minutes)</span>
                                        <input type="number" id="automation-cooldown-minutes" min="0" step="1" value="20">
                                    </label>
                                </div>
                            </section>

                            <section class="automation-settings-section">
                                <div class="automation-settings-head">
                                    <div>
                                        <h3>Auto exits</h3>
                                        <p class="meta">Automatic take profit and stop loss handling.</p>
                                    </div>
                                </div>
                                <div class="automation-grid">
                                    <div class="field field-toggle">
                                        <span>Exit rules</span>
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
                            </section>
                        </div>

                        <section class="console-block pair-allocation-shell">
                            <div class="section-mini-head">
                                <div>
                                    <h3>Pair allocation plan</h3>
                                    <p class="meta">Manual percentages override the equal split for the remaining enabled pairs.</p>
                                </div>
                            </div>
                            <div class="automation-pairs" id="automation-pairs"></div>
                        </section>

                        <div class="prediction-actions compact-actions">
                            <button type="submit" class="prediction-button" id="automation-save">Save automation settings</button>
                        </div>
                    </div>
                </section>

                <section class="console-section" id="automation-auto-section">
                    <button type="button" class="section-toggle" id="automation-auto-toggle" aria-expanded="true" aria-controls="automation-auto-body">
                        <span class="section-toggle-copy">
                            <strong>Auto positions</strong>
                            <span id="automation-auto-summary-copy">Live auto-managed positions with quick health context.</span>
                        </span>
                        <span class="section-toggle-action" data-role="toggle-label">Collapse</span>
                    </button>
                    <div class="section-summary-grid" id="automation-auto-summary"></div>
                    <div class="console-section-body" id="automation-auto-body">
                        <div class="automation-open-positions" id="automation-open-positions"></div>
                    </div>
                </section>
            </form>
        </div>
    </section>

    <section class="card signal-panel" id="signal-panel">
        <div class="panel-head signal-header">
            <div>
                <div class="prediction-kicker">Market signals</div>
                <h2>Compact shortlist</h2>
                <p class="meta" id="signal-summary">Live pairs, bias and quick risk context. Expand only when you need the full reasoning.</p>
            </div>
            <div class="prediction-actions">
                <button type="button" class="prediction-button alt-button" id="signal-toggle" aria-expanded="false" aria-controls="signal-details">
                    <span data-role="toggle-label">Show details</span>
                </button>
            </div>
        </div>
        <div class="signal-compact-list" id="signal-compact-list"></div>
        <div class="signal-detail-shell" id="signal-details" hidden>
            <div class="signal-grid" id="signal-grid"></div>
        </div>
    </section>

    <section class="card prediction-panel" id="prediction-panel">
        <div class="panel-head">
            <div>
                <div class="prediction-kicker">Detailed prediction</div>
                <h2 id="prediction-title">Prediction</h2>
                <p class="meta" id="prediction-summary">Select a pair from the signals section and the system will build a deeper market scenario.</p>
            </div>
            <div class="prediction-actions">
                <button type="button" class="prediction-button" id="prediction-refresh" disabled>Refresh prediction</button>
            </div>
        </div>

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

    <section class="card manual-panel" id="manual-panel">
        <div class="panel-head paper-header">
            <div>
                <div class="prediction-kicker">Manual paper trading</div>
                <h2 id="manual-title">Manual position</h2>
                <p class="meta" id="manual-summary">Prediction defaults fill this trade form, while open simulated positions stay manageable below.</p>
            </div>
        </div>

        <div class="prediction-status" id="paper-status" aria-live="polite">Select a prediction to prepare a simulated trade. Existing open positions remain editable below.</div>

        <div class="manual-top-grid">
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
                <div class="prediction-actions compact-actions">
                    <button type="submit" class="prediction-button" id="paper-submit">Open paper position</button>
                </div>
            </form>

            <section class="console-block paper-account-shell">
                <div class="section-mini-head">
                    <div>
                        <h3>Account snapshot</h3>
                        <p class="meta">Live paper account balance and PnL.</p>
                    </div>
                </div>
                <div class="paper-account-grid" id="paper-account"></div>
            </section>
        </div>

        <section class="console-section manual-section" id="paper-open-section">
            <button type="button" class="section-toggle" id="paper-open-toggle" aria-expanded="true" aria-controls="paper-open-body">
                <span class="section-toggle-copy">
                    <strong>Open positions</strong>
                    <span id="paper-open-summary-copy">Live paper positions with inline management controls.</span>
                </span>
                <span class="section-toggle-action" data-role="toggle-label">Collapse</span>
            </button>
            <div class="section-summary-grid" id="paper-open-summary"></div>
            <div class="console-section-body" id="paper-open-body">
                <div class="paper-open-list" id="paper-open-positions"></div>
            </div>
        </section>

        <section class="console-section manual-section" id="paper-history-section">
            <button type="button" class="section-toggle" id="paper-history-toggle" aria-expanded="false" aria-controls="paper-history-body">
                <span class="section-toggle-copy">
                    <strong>Trade history</strong>
                    <span id="paper-history-summary-copy">Closed simulated trades and their exit outcomes.</span>
                </span>
                <span class="section-toggle-action" data-role="toggle-label">Show</span>
            </button>
            <div class="section-summary-grid" id="paper-history-summary"></div>
            <div class="console-section-body" id="paper-history-body" hidden>
                <div class="paper-history-list" id="paper-history"></div>
            </div>
        </section>
    </section>
</div>
<script src="/assets/js/dashboard.view.js" defer></script>
<script src="/assets/js/dashboard.js" defer></script>
