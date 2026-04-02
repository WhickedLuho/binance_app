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

    let refreshInFlight = false;
    let refreshTimerId = null;
    let predictionInFlight = false;
    let selectedPredictionSymbol = null;

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

    const formatZone = (zone) => {
        if (!zone || zone.low == null || zone.high == null) {
            return '-';
        }

        return `${formatNumber(zone.low, 4)} - ${formatNumber(zone.high, 4)}`;
    };

    const renderTimeframes = (timeframes) => {
        const entries = Object.entries(timeframes || {});
        if (!entries.length) {
            return 'Nincs idősík adat.';
        }

        return entries.map(([label, payload]) => `
            <div class="metric"><span>${escapeHtml(label)}</span><span>${escapeHtml(payload.bias || 'NEUTRAL')}</span></div>
        `).join('');
    };

    const renderCard = (row) => {
        const action = row.action || row.direction || 'NO_TRADE';
        const badgeClass = (action === 'LONG' || action === 'SPOT_BUY') ? 'long' : ((action === 'SHORT' || action === 'SPOT_SELL') ? 'short' : 'neutral');
        const reasons = row.reasons && row.reasons.length ? row.reasons.join(', ') : 'Az indikátorok nem adnak elég erős egyetértést.';
        const riskText = row.risk && row.risk.allowed
            ? 'Nincs blokkoló feltétel.'
            : ((row.risk && row.risk.flags && row.risk.flags.length) ? row.risk.flags.join(', ') : 'Ismeretlen kockázati állapot.');
        const errorBlock = row.error
            ? `<div class="risk pair-error"><strong>Párhiba</strong><br>${escapeHtml(row.error)}</div>`
            : '';

        return `
            <article class="card" data-symbol="${escapeHtml(row.symbol)}">
                <div class="signal-card-head">
                    <div>
                        <div class="signal-symbol">${escapeHtml(row.symbol)}</div>
                        <div class="meta">Döntési idősík: ${escapeHtml(row.interval)}</div>
                    </div>
                    <span class="badge ${badgeClass}">${escapeHtml(action)}</span>
                </div>

                <div class="meta signal-meta-block">
                    Ár: ${formatNumber(row.price, 4)}<br>
                    Piaci rezsim: ${escapeHtml(row.market_regime || 'UNKNOWN')}<br>
                    Bizalom: ${escapeHtml(row.confidence)} / 100
                </div>

                <div class="reasons">
                    <strong>Pontszámok</strong>
                    <div class="metric"><span>Bika</span><span>${escapeHtml(row.bull_score ?? row.long_score ?? 0)}</span></div>
                    <div class="metric"><span>Medve</span><span>${escapeHtml(row.bear_score ?? row.short_score ?? 0)}</span></div>
                    <div class="metric"><span>Kockázati büntetés</span><span>${escapeHtml(row.risk_penalty ?? 0)}</span></div>
                    <div class="metric"><span>RSI 14</span><span>${formatNumber(row.metrics?.rsi14, 2)}</span></div>
                    <div class="metric"><span>ATR %</span><span>${formatNumber(row.metrics?.atr_percent, 2)}</span></div>
                    <div class="metric"><span>Volumen arány</span><span>${formatNumber(row.metrics?.volume_ratio, 2)}</span></div>
                </div>

                <div class="reasons">
                    <strong>Idősíkok</strong><br>
                    ${renderTimeframes(row.timeframes)}
                </div>

                <div class="reasons">
                    <strong>Indokok</strong><br>
                    ${escapeHtml(reasons)}
                </div>

                <div class="risk">
                    <strong>Kockázati szűrő</strong><br>
                    ${escapeHtml(riskText)}
                </div>

                <div class="prediction-actions">
                    <button type="button" class="prediction-button" data-action="prediction" data-symbol="${escapeHtml(row.symbol)}">Előbecslés</button>
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
            ? `<div class="metric"><span>Célzóna</span><span>${escapeHtml(formatZone(payload.target_zone))}</span></div>`
            : '';
        const tp = payload.suggested_take_profit != null
            ? `<div class="metric"><span>Javasolt TP</span><span>${escapeHtml(formatNumber(payload.suggested_take_profit, 4))}</span></div>`
            : '';
        const invalidation = payload.invalidation != null
            ? `<div class="metric"><span>Invalidáció</span><span>${escapeHtml(formatNumber(payload.invalidation, 4))}</span></div>`
            : '';
        const reward = payload.reward_percent != null
            ? `<div class="metric"><span>Hozam %</span><span>${escapeHtml(formatNumber(payload.reward_percent, 2))}</span></div>`
            : '';
        const risk = payload.risk_percent != null
            ? `<div class="metric"><span>Kockázat %</span><span>${escapeHtml(formatNumber(payload.risk_percent, 2))}</span></div>`
            : '';
        const range = payload.range_low != null && payload.range_high != null
            ? `<div class="metric"><span>Várható sáv</span><span>${escapeHtml(formatNumber(payload.range_low, 4))} - ${escapeHtml(formatNumber(payload.range_high, 4))}</span></div>`
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

    const renderPrediction = (prediction) => {
        predictionPanel.hidden = false;
        predictionGrid.hidden = false;
        predictionScenarios.hidden = false;
        predictionTimeframes.hidden = false;
        predictionTitle.textContent = `${prediction.symbol} előbecslés`;
        predictionSummary.textContent = prediction.summary || 'A scenáriót az aktuális piaci adatokból építettük fel.';
        predictionStatus.textContent = 'Az előbecslés betöltve.';
        predictionBias.textContent = prediction.bias || 'RANGE';
        predictionConfidence.textContent = `Bizalom: ${prediction.confidence ?? '-'} / 100`;
        predictionPrice.textContent = formatNumber(prediction.current_price, 4);
        predictionGenerated.textContent = `Generálva: ${new Date(prediction.generated_at).toLocaleString()}`;
        predictionSupport.textContent = formatZone(prediction.zones?.support);
        predictionResistance.textContent = formatZone(prediction.zones?.resistance);
        predictionScenarios.innerHTML = [
            renderScenarioCard('Short scenárió', 'bearish', prediction.scenarios?.short || {}),
            renderScenarioCard('Long scenárió', 'bullish', prediction.scenarios?.long || {}),
            renderScenarioCard('Neutrális scenárió', 'neutral', prediction.scenarios?.neutral || {}),
        ].join('');
        predictionTimeframes.innerHTML = Object.entries(prediction.timeframes || {}).map(([label, payload]) => `
            <article class="prediction-block">
                <div class="prediction-label">${escapeHtml(label)}</div>
                <div class="prediction-value">${escapeHtml(payload.bias || 'NEUTRAL')}</div>
                <div class="metric"><span>Ár</span><span>${escapeHtml(formatNumber(payload.price, 4))}</span></div>
                <div class="metric"><span>EMA20</span><span>${escapeHtml(formatNumber(payload.ema20, 4))}</span></div>
                <div class="metric"><span>EMA50</span><span>${escapeHtml(formatNumber(payload.ema50, 4))}</span></div>
                <div class="metric"><span>RSI14</span><span>${escapeHtml(formatNumber(payload.rsi14, 2))}</span></div>
                <div class="metric"><span>ATR %</span><span>${escapeHtml(formatNumber(payload.atr_percent, 2))}</span></div>
                <div class="metric"><span>Támasz</span><span>${escapeHtml(formatNumber(payload.support, 4))}</span></div>
                <div class="metric"><span>Ellenállás</span><span>${escapeHtml(formatNumber(payload.resistance, 4))}</span></div>
            </article>
        `).join('');
    };

    const setPredictionLoading = (symbol) => {
        predictionPanel.hidden = false;
        predictionGrid.hidden = true;
        predictionScenarios.hidden = true;
        predictionTimeframes.hidden = true;
        predictionTitle.textContent = `${symbol} előbecslés`;
        predictionSummary.textContent = 'A rendszer éppen egy részletesebb scenáriót állít össze a Binance adatokból.';
        predictionStatus.textContent = 'Előbecslés betöltése...';
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
            const response = await fetch(`/api/prediction?symbol=${encodeURIComponent(symbol)}`, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok' || !payload.prediction) {
                throw new Error(payload.message || `HTTP ${response.status}`);
            }

            renderPrediction(payload.prediction);
        } catch (error) {
            predictionPanel.hidden = false;
            predictionGrid.hidden = true;
            predictionScenarios.hidden = true;
            predictionTimeframes.hidden = true;
            predictionStatus.textContent = `Az előbecslés sikertelen: ${error.message}`;
            predictionSummary.textContent = 'Próbáld újra a következő frissítés után, vagy válassz másik párt.';
        } finally {
            predictionInFlight = false;
            predictionRefresh.disabled = !selectedPredictionSymbol;
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
            const response = await fetch('/api/signals', {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            if (payload.status !== 'ok' || !Array.isArray(payload.signals)) {
                throw new Error(payload.message || 'Érvénytelen API válasz');
            }

            renderSignals(payload.signals);
            errorBox.hidden = true;
            lastUpdated.textContent = new Date().toLocaleString();
        } catch (error) {
            errorBox.textContent = `A frissítés sikertelen: ${error.message}`;
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

    scheduleRefresh();
})();