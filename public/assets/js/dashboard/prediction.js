(() => {
    const {
        escapeHtml,
        formatNumber,
        formatZone,
    } = window.DashboardView || {};

    const renderScenarioCard = (title, tone, payload) => {
        const metrics = [
            ['Target zone', payload.target_zone ? formatZone(payload.target_zone) : null],
            ['Suggested TP', payload.suggested_take_profit != null ? formatNumber(payload.suggested_take_profit, 4) : null],
            ['Invalidation', payload.invalidation != null ? formatNumber(payload.invalidation, 4) : null],
            ['Reward %', payload.reward_percent != null ? formatNumber(payload.reward_percent, 2) : null],
            ['Risk %', payload.risk_percent != null ? formatNumber(payload.risk_percent, 2) : null],
            ['Expected band', payload.range_low != null && payload.range_high != null ? `${formatNumber(payload.range_low, 4)} - ${formatNumber(payload.range_high, 4)}` : null],
        ].filter(([, value]) => value !== null);

        return `
            <article class="prediction-block scenario-${tone}">
                <div class="prediction-label">${escapeHtml(title)}</div>
                <div class="meta">${escapeHtml(payload.summary || '')}</div>
                <div class="metric-list">
                    ${metrics.map(([label, value]) => `
                        <div class="metric"><span>${escapeHtml(label)}</span><span>${escapeHtml(value)}</span></div>
                    `).join('')}
                </div>
            </article>
        `;
    };

    const renderPredictionScenariosMarkup = (prediction) => [
        renderScenarioCard('Short scenario', 'bearish', prediction.scenarios?.short || {}),
        renderScenarioCard('Long scenario', 'bullish', prediction.scenarios?.long || {}),
        renderScenarioCard('Neutral scenario', 'neutral', prediction.scenarios?.neutral || {}),
    ].join('');

    const renderPredictionTimeframesMarkup = (timeframes) => Object.entries(timeframes || {}).map(([label, payload]) => `
        <article class="prediction-block">
            <div class="prediction-label">${escapeHtml(label)}</div>
            <div class="prediction-value">${escapeHtml(payload.bias || 'NEUTRAL')}</div>
            <div class="metric-list">
                <div class="metric"><span>Price</span><span>${escapeHtml(formatNumber(payload.price, 4))}</span></div>
                <div class="metric"><span>EMA20</span><span>${escapeHtml(formatNumber(payload.ema20, 4))}</span></div>
                <div class="metric"><span>EMA50</span><span>${escapeHtml(formatNumber(payload.ema50, 4))}</span></div>
                <div class="metric"><span>RSI14</span><span>${escapeHtml(formatNumber(payload.rsi14, 2))}</span></div>
                <div class="metric"><span>ATR %</span><span>${escapeHtml(formatNumber(payload.atr_percent, 2))}</span></div>
                <div class="metric"><span>Support</span><span>${escapeHtml(formatNumber(payload.support, 4))}</span></div>
                <div class="metric"><span>Resistance</span><span>${escapeHtml(formatNumber(payload.resistance, 4))}</span></div>
            </div>
        </article>
    `).join('');

    Object.assign(window.DashboardView, {
        renderPredictionScenariosMarkup,
        renderPredictionTimeframesMarkup,
    });
})();
