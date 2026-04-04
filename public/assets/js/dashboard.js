(() => {
    const root = document.getElementById('dashboard-root');
    if (!root) {
        return;
    }

    const refreshSeconds = Number(root.dataset.refreshSeconds || 5);
    const signalGrid = document.getElementById('signal-grid');
    const errorBox = document.getElementById('dashboard-error');
    const lastUpdated = document.getElementById('last-updated');
    const predictionPanel = document.getElementById('prediction-panel');
    const predictionTitle = document.getElementById('prediction-title');
    const predictionSummary = document.getElementById('prediction-summary');
    const predictionStatus = document.getElementById('prediction-status');
    const predictionGrid = document.getElementById('prediction-grid');
    const predictionScenarios = document.getElementById('prediction-scenarios');
    const predictionTimeframes = document.getElementById('prediction-timeframes');
    const predictionBias = document.getElementById('prediction-bias');
    const predictionConfidence = document.getElementById('prediction-confidence');
    const predictionPrice = document.getElementById('prediction-price');
    const predictionGenerated = document.getElementById('prediction-generated');
    const predictionSupport = document.getElementById('prediction-support');
    const predictionResistance = document.getElementById('prediction-resistance');
    const predictionRefresh = document.getElementById('prediction-refresh');
    const paperPanel = document.getElementById('paper-panel');
    const paperStatus = document.getElementById('paper-status');
    const paperForm = document.getElementById('paper-form');
    const paperSymbol = document.getElementById('paper-symbol');
    const paperPositionType = document.getElementById('paper-position-type');
    const paperMarginType = document.getElementById('paper-margin-type');
    const paperLeverage = document.getElementById('paper-leverage');
    const paperCapital = document.getElementById('paper-capital');
    const paperEntryPrice = document.getElementById('paper-entry-price');
    const paperStopLoss = document.getElementById('paper-stop-loss');
    const paperTakeProfit = document.getElementById('paper-take-profit');
    const paperNotes = document.getElementById('paper-notes');
    const paperPreview = document.getElementById('paper-preview');
    const paperAccount = document.getElementById('paper-account');
    const paperOpenPositions = document.getElementById('paper-open-positions');
    const paperHistory = document.getElementById('paper-history');

    let refreshInFlight = false;
    let refreshTimerId = null;
    let predictionInFlight = false;
    let paperInFlight = false;
    let selectedPredictionSymbol = null;
    let currentPrediction = null;
    let currentPaperTrading = null;

    const escapeHtml = (value) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const formatNumber = (value, decimals = 2) => {
        if (value === null || value === undefined || value === '') {
            return '-';
        }

        const number = Number(value);
        return Number.isFinite(number) ? number.toFixed(decimals) : '-';
    };

    const formatSigned = (value, decimals = 2, suffix = '') => {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '-';
        }

        const sign = number > 0 ? '+' : '';
        return `${sign}${number.toFixed(decimals)}${suffix}`;
    };

    const numberClass = (value) => {
        const number = Number(value);
        if (!Number.isFinite(number) || number === 0) {
            return 'muted-number';
        }

        return number > 0 ? 'positive-number' : 'negative-number';
    };

    const formatZone = (zone) => {
        if (!zone || zone.low == null || zone.high == null) {
            return '-';
        }

        const strength = zone.strength != null ? ` (${zone.strength}x)` : '';
        return `${formatNumber(zone.low, 4)} - ${formatNumber(zone.high, 4)}${strength}`;
    };

    const formatDate = (value) => {
        if (!value) {
            return '-';
        }

        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString();
    };

    const renderTimeframes = (timeframes) => {
        const entries = Object.entries(timeframes || {});
        if (!entries.length) {
            return 'No timeframe data.';
        }

        return entries.map(([label, payload]) => `
            <div class="metric"><span>${escapeHtml(label)}</span><span>${escapeHtml(payload.bias || 'NEUTRAL')}</span></div>
        `).join('');
    };

    const renderCard = (row) => {
        const action = row.action || row.direction || 'NO_TRADE';
        const badgeClass = (action === 'LONG' || action === 'SPOT_BUY') ? 'long' : ((action === 'SHORT' || action === 'SPOT_SELL') ? 'short' : 'neutral');
        const reasons = row.reasons && row.reasons.length ? row.reasons.join(', ') : 'Indicators do not agree strongly enough.';
        const riskText = row.risk && row.risk.allowed
            ? 'No blocking condition.'
            : ((row.risk && row.risk.flags && row.risk.flags.length) ? row.risk.flags.join(', ') : 'Unknown risk state.');
        const errorBlock = row.error
            ? `<div class="risk pair-error"><strong>Pair error</strong><br>${escapeHtml(row.error)}</div>`
            : '';

        return `
            <article class="card" data-symbol="${escapeHtml(row.symbol)}">
                <div class="signal-card-head">
                    <div>
                        <div class="signal-symbol">${escapeHtml(row.symbol)}</div>
                        <div class="meta">Decision timeframe: ${escapeHtml(row.interval)}</div>
                    </div>
                    <span class="badge ${badgeClass}">${escapeHtml(action)}</span>
                </div>

                <div class="meta signal-meta-block">
                    Price: ${formatNumber(row.price, 4)}<br>
                    Market regime: ${escapeHtml(row.market_regime || 'UNKNOWN')}<br>
                    Confidence: ${escapeHtml(row.confidence)} / 100
                </div>

                <div class="reasons">
                    <strong>Scores</strong>
                    <div class="metric"><span>Bull</span><span>${escapeHtml(row.bull_score ?? row.long_score ?? 0)}</span></div>
                    <div class="metric"><span>Bear</span><span>${escapeHtml(row.bear_score ?? row.short_score ?? 0)}</span></div>
                    <div class="metric"><span>Risk penalty</span><span>${escapeHtml(row.risk_penalty ?? 0)}</span></div>
                    <div class="metric"><span>RSI 14</span><span>${formatNumber(row.metrics?.rsi14, 2)}</span></div>
                    <div class="metric"><span>ATR %</span><span>${formatNumber(row.metrics?.atr_percent, 2)}</span></div>
                    <div class="metric"><span>Volume ratio</span><span>${formatNumber(row.metrics?.volume_ratio, 2)}</span></div>
                </div>

                <div class="reasons">
                    <strong>Timeframes</strong><br>
                    ${renderTimeframes(row.timeframes)}
                </div>

                <div class="reasons">
                    <strong>Reasons</strong><br>
                    ${escapeHtml(reasons)}
                </div>

                <div class="risk">
                    <strong>Risk filter</strong><br>
                    ${escapeHtml(riskText)}
                </div>

                <div class="prediction-actions">
                    <button type="button" class="prediction-button" data-action="prediction" data-symbol="${escapeHtml(row.symbol)}">Prediction</button>
                </div>

                ${errorBlock}
            </article>
        `;
    };

    const renderSignals = (signals) => {
        signalGrid.innerHTML = signals.map(renderCard).join('');
    };

    const renderScenarioCard = (title, tone, payload) => {
        const zone = payload.target_zone
            ? `<div class="metric"><span>Target zone</span><span>${escapeHtml(formatZone(payload.target_zone))}</span></div>`
            : '';
        const tp = payload.suggested_take_profit != null
            ? `<div class="metric"><span>Suggested TP</span><span>${escapeHtml(formatNumber(payload.suggested_take_profit, 4))}</span></div>`
            : '';
        const invalidation = payload.invalidation != null
            ? `<div class="metric"><span>Invalidation</span><span>${escapeHtml(formatNumber(payload.invalidation, 4))}</span></div>`
            : '';
        const reward = payload.reward_percent != null
            ? `<div class="metric"><span>Reward %</span><span>${escapeHtml(formatNumber(payload.reward_percent, 2))}</span></div>`
            : '';
        const risk = payload.risk_percent != null
            ? `<div class="metric"><span>Risk %</span><span>${escapeHtml(formatNumber(payload.risk_percent, 2))}</span></div>`
            : '';
        const range = payload.range_low != null && payload.range_high != null
            ? `<div class="metric"><span>Expected band</span><span>${escapeHtml(formatNumber(payload.range_low, 4))} - ${escapeHtml(formatNumber(payload.range_high, 4))}</span></div>`
            : '';

        return `
            <article class="prediction-block scenario-${tone}">
                <div class="prediction-label">${escapeHtml(title)}</div>
                <div class="meta">${escapeHtml(payload.summary || '')}</div>
                ${zone}
                ${tp}
                ${invalidation}
                ${reward}
                ${risk}
                ${range}
            </article>
        `;
    };

    const predictionDefaults = (prediction, positionType) => {
        const currentPrice = Number(prediction?.current_price || 0);
        const longScenario = prediction?.scenarios?.long || {};
        const shortScenario = prediction?.scenarios?.short || {};

        if (positionType === 'FUTURES_SHORT') {
            return {
                tradeType: 'FUTURES',
                side: 'SHORT',
                leverage: Number(paperLeverage.value || 5),
                stopLoss: shortScenario.invalidation ?? '',
                takeProfit: shortScenario.suggested_take_profit ?? '',
                entryPrice: currentPrice || shortScenario.entry || '',
                notes: `Short simulation based on ${prediction?.symbol || 'selected'} prediction`,
            };
        }

        if (positionType === 'FUTURES_LONG') {
            return {
                tradeType: 'FUTURES',
                side: 'LONG',
                leverage: Number(paperLeverage.value || 5),
                stopLoss: longScenario.invalidation ?? '',
                takeProfit: longScenario.suggested_take_profit ?? '',
                entryPrice: currentPrice || longScenario.entry || '',
                notes: `Long simulation based on ${prediction?.symbol || 'selected'} prediction`,
            };
        }

        return {
            tradeType: 'SPOT',
            side: 'LONG',
            leverage: 1,
            stopLoss: prediction?.zones?.support?.low ?? '',
            takeProfit: longScenario.suggested_take_profit ?? prediction?.zones?.resistance?.low ?? '',
            entryPrice: currentPrice || '',
            notes: `Spot simulation based on ${prediction?.symbol || 'selected'} prediction`,
        };
    };

    const syncPaperForm = ({ preserveManual = false } = {}) => {
        if (!currentPrediction) {
            return;
        }

        const positionType = paperPositionType.value;
        const defaults = predictionDefaults(currentPrediction, positionType);
        const isSpot = positionType === 'SPOT';

        paperForm.classList.toggle('is-spot', isSpot);
        paperMarginType.disabled = isSpot;
        paperLeverage.disabled = isSpot;

        if (isSpot) {
            paperMarginType.value = 'ISOLATED';
            paperLeverage.value = '1';
        }

        paperSymbol.value = currentPrediction.symbol || '';

        if (!preserveManual) {
            paperEntryPrice.value = defaults.entryPrice !== '' ? defaults.entryPrice : '';
            paperStopLoss.value = defaults.stopLoss !== '' ? defaults.stopLoss : '';
            paperTakeProfit.value = defaults.takeProfit !== '' ? defaults.takeProfit : '';
            paperNotes.value = defaults.notes || '';
        }

        renderPaperPreview();
    };

    const renderPrediction = (prediction) => {
        currentPrediction = prediction;
        predictionPanel.hidden = false;
        predictionGrid.hidden = false;
        predictionScenarios.hidden = false;
        predictionTimeframes.hidden = false;
        paperPanel.hidden = false;
        predictionTitle.textContent = `${prediction.symbol} prediction`;
        predictionSummary.textContent = prediction.summary || 'Prediction built from current market data.';
        predictionStatus.textContent = 'Prediction loaded.';
        predictionBias.textContent = prediction.bias || 'RANGE';
        predictionConfidence.textContent = `Confidence: ${prediction.confidence ?? '-'} / 100`;
        predictionPrice.textContent = formatNumber(prediction.current_price, 4);
        predictionGenerated.textContent = `Generated: ${formatDate(prediction.generated_at)}`;
        predictionSupport.textContent = formatZone(prediction.zones?.support);
        predictionResistance.textContent = formatZone(prediction.zones?.resistance);
        predictionScenarios.innerHTML = [
            renderScenarioCard('Short scenario', 'bearish', prediction.scenarios?.short || {}),
            renderScenarioCard('Long scenario', 'bullish', prediction.scenarios?.long || {}),
            renderScenarioCard('Neutral scenario', 'neutral', prediction.scenarios?.neutral || {}),
        ].join('');
        predictionTimeframes.innerHTML = Object.entries(prediction.timeframes || {}).map(([label, payload]) => `
            <article class="prediction-block">
                <div class="prediction-label">${escapeHtml(label)}</div>
                <div class="prediction-value">${escapeHtml(payload.bias || 'NEUTRAL')}</div>
                <div class="metric"><span>Price</span><span>${escapeHtml(formatNumber(payload.price, 4))}</span></div>
                <div class="metric"><span>EMA20</span><span>${escapeHtml(formatNumber(payload.ema20, 4))}</span></div>
                <div class="metric"><span>EMA50</span><span>${escapeHtml(formatNumber(payload.ema50, 4))}</span></div>
                <div class="metric"><span>RSI14</span><span>${escapeHtml(formatNumber(payload.rsi14, 2))}</span></div>
                <div class="metric"><span>ATR %</span><span>${escapeHtml(formatNumber(payload.atr_percent, 2))}</span></div>
                <div class="metric"><span>Support</span><span>${escapeHtml(formatNumber(payload.support, 4))}</span></div>
                <div class="metric"><span>Resistance</span><span>${escapeHtml(formatNumber(payload.resistance, 4))}</span></div>
            </article>
        `).join('');

        syncPaperForm();
    };

    const setPredictionLoading = (symbol) => {
        predictionPanel.hidden = false;
        predictionGrid.hidden = true;
        predictionScenarios.hidden = true;
        predictionTimeframes.hidden = true;
        paperPanel.hidden = true;
        predictionTitle.textContent = `${symbol} prediction`;
        predictionSummary.textContent = 'Building a detailed scenario from live Binance market data.';
        predictionStatus.textContent = 'Loading prediction...';
    };

    const apiJson = async (url, options = {}) => {
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(options.headers || {}),
            },
            cache: 'no-store',
            ...options,
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status === 'error') {
            throw new Error(payload.message || `HTTP ${response.status}`);
        }

        return payload;
    };

    const loadPrediction = async (symbol) => {
        if (!symbol || predictionInFlight) {
            return;
        }

        predictionInFlight = true;
        selectedPredictionSymbol = symbol;
        predictionRefresh.disabled = true;
        setPredictionLoading(symbol);

        try {
            const payload = await apiJson(`/api/prediction?symbol=${encodeURIComponent(symbol)}`);
            renderPrediction(payload.prediction);
            paperStatus.textContent = `Paper trading defaults prepared for ${symbol}.`;
        } catch (error) {
            predictionPanel.hidden = false;
            predictionGrid.hidden = true;
            predictionScenarios.hidden = true;
            predictionTimeframes.hidden = true;
            paperPanel.hidden = true;
            predictionStatus.textContent = `Prediction failed: ${error.message}`;
            predictionSummary.textContent = 'Try again on the next refresh or choose another pair.';
        } finally {
            predictionInFlight = false;
            predictionRefresh.disabled = !selectedPredictionSymbol;
        }
    };

    const renderPaperPreview = () => {
        const entryPrice = Number(paperEntryPrice.value || 0);
        const capital = Number(paperCapital.value || 0);
        const leverage = Number(paperLeverage.value || 1);
        const stopLoss = Number(paperStopLoss.value || 0);
        const takeProfit = Number(paperTakeProfit.value || 0);
        const effectiveLeverage = paperPositionType.value === 'SPOT' ? 1 : Math.max(1, leverage);
        const notional = capital > 0 ? capital * effectiveLeverage : 0;
        const quantity = entryPrice > 0 ? notional / entryPrice : 0;
        const riskPercent = (entryPrice > 0 && stopLoss > 0) ? Math.abs(((stopLoss - entryPrice) / entryPrice) * 100) : 0;
        const rewardPercent = (entryPrice > 0 && takeProfit > 0) ? Math.abs(((takeProfit - entryPrice) / entryPrice) * 100) : 0;

        paperPreview.innerHTML = [
            { label: 'Notional size', value: formatNumber(notional, 2) },
            { label: 'Quantity', value: formatNumber(quantity, 6) },
            { label: 'Risk to stop', value: `${formatNumber(riskPercent, 2)} %` },
            { label: 'Reward to target', value: `${formatNumber(rewardPercent, 2)} %` },
        ].map((item) => `
            <div class="preview-chip">
                <span>${escapeHtml(item.label)}</span>
                <strong>${escapeHtml(item.value)}</strong>
            </div>
        `).join('');
    };

    const renderPaperAccount = (account) => {
        if (!account) {
            paperAccount.innerHTML = '';
            return;
        }

        paperAccount.innerHTML = [
            ['Balance', formatNumber(account.balance, 2)],
            ['Equity', formatNumber(account.equity, 2)],
            ['Available', formatNumber(account.available_balance, 2)],
            ['Margin in use', formatNumber(account.margin_in_use, 2)],
            ['Realized PnL', formatSigned(account.realized_pnl, 2)],
            ['Floating PnL', formatSigned(account.floating_pnl, 2)],
        ].map(([label, value]) => `
            <article class="paper-card">
                <div class="prediction-label">${escapeHtml(label)}</div>
                <div class="prediction-value ${numberClass(value)}">${escapeHtml(value)}</div>
            </article>
        `).join('');
    };

    const renderOpenPositions = (positions) => {
        if (!positions?.length) {
            paperOpenPositions.innerHTML = '<div class="paper-empty">No open paper positions yet.</div>';
            return;
        }

        paperOpenPositions.innerHTML = positions.map((position) => `
            <article class="paper-card" data-paper-position-id="${escapeHtml(position.id)}">
                <div class="paper-card-head">
                    <div>
                        <h4 class="paper-card-title">${escapeHtml(position.symbol)} ${escapeHtml(position.side)}</h4>
                        <div class="paper-card-subtitle">${escapeHtml(position.trade_type)} | ${escapeHtml(position.margin_type)} | ${escapeHtml(position.leverage)}x</div>
                    </div>
                    <span class="badge ${Number(position.pnl_value) >= 0 ? 'long' : 'short'}">${escapeHtml(formatSigned(position.pnl_value, 2))}</span>
                </div>
                <div class="paper-card-grid">
                    <div class="metric"><span>Entry</span><span>${escapeHtml(formatNumber(position.entry_price, 4))}</span></div>
                    <div class="metric"><span>Current</span><span>${escapeHtml(formatNumber(position.current_price, 4))}</span></div>
                    <div class="metric"><span>PnL %</span><span class="${numberClass(position.pnl_percent)}">${escapeHtml(formatSigned(position.pnl_percent, 2, '%'))}</span></div>
                    <div class="metric"><span>ROE</span><span class="${numberClass(position.roe_percent)}">${escapeHtml(formatSigned(position.roe_percent, 2, '%'))}</span></div>
                    <div class="metric"><span>Capital</span><span>${escapeHtml(formatNumber(position.capital, 2))}</span></div>
                    <div class="metric"><span>Notional</span><span>${escapeHtml(formatNumber(position.notional, 2))}</span></div>
                    <div class="metric"><span>Liq. est.</span><span>${escapeHtml(formatNumber(position.liquidation_price_estimate, 4))}</span></div>
                    <div class="metric"><span>Opened</span><span>${escapeHtml(formatDate(position.opened_at))}</span></div>
                </div>
                <div class="inline-edit-grid">
                    <label class="field inline-edit">
                        <span>Stop loss</span>
                        <input type="number" step="0.00000001" data-role="stop-loss" value="${position.stop_loss ?? ''}">
                    </label>
                    <label class="field inline-edit">
                        <span>Take profit</span>
                        <input type="number" step="0.00000001" data-role="take-profit" value="${position.take_profit ?? ''}">
                    </label>
                    <label class="field inline-edit">
                        <span>Close reason</span>
                        <select data-role="close-reason">
                            <option value="MANUAL_CLOSE">Manual close</option>
                            <option value="TARGET_LOCK">Target lock</option>
                            <option value="RISK_REDUCTION">Risk reduction</option>
                        </select>
                    </label>
                </div>
                <label class="field inline-edit-notes">
                    <span>Notes</span>
                    <input type="text" data-role="notes" maxlength="140" value="${escapeHtml(position.notes || '')}">
                </label>
                <div class="inline-actions">
                    <button type="button" class="paper-inline-button alt" data-action="save-paper-position" data-id="${escapeHtml(position.id)}">Save levels</button>
                    <button type="button" class="paper-inline-button danger" data-action="close-paper-position" data-id="${escapeHtml(position.id)}">Close now</button>
                </div>
            </article>
        `).join('');
    };

    const renderHistory = (history) => {
        if (!history?.length) {
            paperHistory.innerHTML = '<div class="paper-empty">No closed simulated trades yet.</div>';
            return;
        }

        paperHistory.innerHTML = history.map((position) => `
            <article class="paper-history-card">
                <div class="paper-card-head">
                    <div>
                        <h4 class="paper-card-title">${escapeHtml(position.symbol)} ${escapeHtml(position.side)}</h4>
                        <div class="paper-card-subtitle">${escapeHtml(position.trade_type)} | ${escapeHtml(position.margin_type)} | ${escapeHtml(position.leverage)}x</div>
                    </div>
                    <span class="badge ${Number(position.realized_pnl) >= 0 ? 'long' : 'short'}">${escapeHtml(formatSigned(position.realized_pnl, 2))}</span>
                </div>
                <div class="paper-card-grid">
                    <div class="metric"><span>Entry</span><span>${escapeHtml(formatNumber(position.entry_price, 4))}</span></div>
                    <div class="metric"><span>Close</span><span>${escapeHtml(formatNumber(position.closed_price, 4))}</span></div>
                    <div class="metric"><span>Result %</span><span class="${numberClass(position.realized_percent)}">${escapeHtml(formatSigned(position.realized_percent, 2, '%'))}</span></div>
                    <div class="metric"><span>ROE</span><span class="${numberClass(position.realized_roe)}">${escapeHtml(formatSigned(position.realized_roe, 2, '%'))}</span></div>
                    <div class="metric"><span>Opened</span><span>${escapeHtml(formatDate(position.opened_at))}</span></div>
                    <div class="metric"><span>Closed</span><span>${escapeHtml(formatDate(position.closed_at))}</span></div>
                </div>
                <div class="meta">Exit reason: ${escapeHtml(position.exit_reason || 'N/A')}</div>
            </article>
        `).join('');
    };

    const renderPaperTrading = (paperTrading) => {
        currentPaperTrading = paperTrading;
        renderPaperAccount(paperTrading?.account);
        renderOpenPositions(paperTrading?.open_positions || []);
        renderHistory(paperTrading?.history || []);
    };

    const loadPaperTrading = async ({ silent = false } = {}) => {
        if (paperInFlight) {
            return;
        }

        paperInFlight = true;
        try {
            const payload = await apiJson('/api/paper-trades');
            renderPaperTrading(payload.paper_trading);
            if (!silent && currentPrediction) {
                paperStatus.textContent = `Paper trading account synced for ${currentPrediction.symbol}.`;
            }
        } catch (error) {
            if (!silent) {
                paperStatus.textContent = `Paper trading sync failed: ${error.message}`;
            }
        } finally {
            paperInFlight = false;
        }
    };

    const submitPaperForm = async (event) => {
        event.preventDefault();
        if (!currentPrediction || paperInFlight) {
            return;
        }

        const positionType = paperPositionType.value;
        const tradeType = positionType === 'SPOT' ? 'SPOT' : 'FUTURES';
        const side = positionType === 'FUTURES_SHORT' ? 'SHORT' : 'LONG';
        const leverage = positionType === 'SPOT' ? 1 : Number(paperLeverage.value || 1);

        try {
            paperStatus.textContent = 'Opening paper position...';
            const payload = await apiJson('/api/paper-trades', {
                method: 'POST',
                body: JSON.stringify({
                    symbol: currentPrediction.symbol,
                    trade_type: tradeType,
                    side,
                    margin_type: paperMarginType.value,
                    leverage,
                    capital: paperCapital.value,
                    entry_price: paperEntryPrice.value,
                    stop_loss: paperStopLoss.value,
                    take_profit: paperTakeProfit.value,
                    notes: paperNotes.value,
                }),
            });
            renderPaperTrading(payload.paper_trading);
            paperStatus.textContent = payload.message || 'Paper position opened.';
        } catch (error) {
            paperStatus.textContent = `Paper position open failed: ${error.message}`;
        }
    };

    const updatePaperPosition = async (card, id) => {
        if (paperInFlight) {
            return;
        }

        try {
            paperStatus.textContent = `Updating paper position #${id}...`;
            const payload = await apiJson('/api/paper-trades/update', {
                method: 'POST',
                body: JSON.stringify({
                    id,
                    stop_loss: card.querySelector('[data-role="stop-loss"]').value,
                    take_profit: card.querySelector('[data-role="take-profit"]').value,
                    notes: card.querySelector('[data-role="notes"]').value,
                }),
            });
            renderPaperTrading(payload.paper_trading);
            paperStatus.textContent = payload.message || 'Paper position updated.';
        } catch (error) {
            paperStatus.textContent = `Paper position update failed: ${error.message}`;
        }
    };

    const closePaperPosition = async (card, id) => {
        if (paperInFlight) {
            return;
        }

        try {
            paperStatus.textContent = `Closing paper position #${id}...`;
            const payload = await apiJson('/api/paper-trades/close', {
                method: 'POST',
                body: JSON.stringify({
                    id,
                    reason: card.querySelector('[data-role="close-reason"]').value,
                }),
            });
            renderPaperTrading(payload.paper_trading);
            paperStatus.textContent = payload.message || 'Paper position closed.';
        } catch (error) {
            paperStatus.textContent = `Paper position close failed: ${error.message}`;
        }
    };

    const scheduleRefresh = () => {
        window.clearTimeout(refreshTimerId);
        refreshTimerId = window.setTimeout(refreshSignals, refreshSeconds * 1000);
    };

    const refreshSignals = async () => {
        if (refreshInFlight) {
            scheduleRefresh();
            return;
        }

        refreshInFlight = true;

        try {
            const payload = await apiJson('/api/signals');
            if (!Array.isArray(payload.signals)) {
                throw new Error('Invalid signals payload.');
            }

            renderSignals(payload.signals);
            errorBox.hidden = true;
            lastUpdated.textContent = new Date().toLocaleString();
            const hasOpenPaperPositions = Array.isArray(currentPaperTrading?.open_positions) && currentPaperTrading.open_positions.length > 0;
            if (!paperPanel.hidden || hasOpenPaperPositions) {
                loadPaperTrading({ silent: true });
            }
        } catch (error) {
            errorBox.textContent = `Refresh failed: ${error.message}`;
            errorBox.hidden = false;
        } finally {
            refreshInFlight = false;
            scheduleRefresh();
        }
    };

    signalGrid.addEventListener('click', (event) => {
        const target = event.target.closest('[data-action="prediction"]');
        if (!target) {
            return;
        }

        loadPrediction(target.dataset.symbol || '');
    });

    predictionRefresh.addEventListener('click', () => {
        if (selectedPredictionSymbol !== null) {
            loadPrediction(selectedPredictionSymbol);
        }
    });

    paperPositionType.addEventListener('change', () => syncPaperForm());
    [paperMarginType, paperLeverage, paperCapital, paperEntryPrice, paperStopLoss, paperTakeProfit].forEach((element) => {
        element.addEventListener('input', () => renderPaperPreview());
    });
    paperForm.addEventListener('submit', submitPaperForm);

    paperOpenPositions.addEventListener('click', (event) => {
        const saveButton = event.target.closest('[data-action="save-paper-position"]');
        if (saveButton) {
            const card = saveButton.closest('[data-paper-position-id]');
            updatePaperPosition(card, Number(saveButton.dataset.id || 0));
            return;
        }

        const closeButton = event.target.closest('[data-action="close-paper-position"]');
        if (closeButton) {
            const card = closeButton.closest('[data-paper-position-id]');
            closePaperPosition(card, Number(closeButton.dataset.id || 0));
        }
    });

    loadPaperTrading({ silent: true });
    scheduleRefresh();
})();
