(() => {
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

    const setHtmlIfChanged = (element, html) => {
        if (element && element.innerHTML !== html) {
            element.innerHTML = html;
        }
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

    const renderSignalCard = (row) => {
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
                        <div class="meta">Decision timeframe: ${escapeHtml(row.interval || 'n/a')}</div>
                    </div>
                    <span class="badge ${badgeClass}">${escapeHtml(action)}</span>
                </div>

                <div class="meta signal-meta-block">
                    Price: ${formatNumber(row.price, 4)}<br>
                    Market regime: ${escapeHtml(row.market_regime || 'UNKNOWN')}<br>
                    Confidence: ${escapeHtml(row.confidence ?? '-')} / 100
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

    const renderEmptySignals = (configuredPairs) => `
        <article class="card">
            <div class="signal-symbol">No data</div>
            <div class="meta">Configured pairs: ${escapeHtml((configuredPairs || []).join(', '))}</div>
        </article>
    `;

    const renderSignalsMarkup = (signals, configuredPairs) => {
        if (!Array.isArray(signals) || signals.length === 0) {
            return renderEmptySignals(configuredPairs);
        }

        return signals.map(renderSignalCard).join('');
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

    const renderPredictionScenariosMarkup = (prediction) => [
        renderScenarioCard('Short scenario', 'bearish', prediction.scenarios?.short || {}),
        renderScenarioCard('Long scenario', 'bullish', prediction.scenarios?.long || {}),
        renderScenarioCard('Neutral scenario', 'neutral', prediction.scenarios?.neutral || {}),
    ].join('');

    const renderPredictionTimeframesMarkup = (timeframes) => Object.entries(timeframes || {}).map(([label, payload]) => `
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

    const renderPaperPreviewMarkup = ({ notional, quantity, riskPercent, rewardPercent }) => [
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

    const renderPaperAccountMarkup = (account) => {
        if (!account) {
            return '';
        }

        return [
            ['Balance', account.balance],
            ['Equity', account.equity],
            ['Available', account.available_balance],
            ['Margin in use', account.margin_in_use],
            ['Realized PnL', account.realized_pnl, true],
            ['Floating PnL', account.floating_pnl, true],
        ].map(([label, value, signed]) => {
            const formatted = signed ? formatSigned(value, 2) : formatNumber(value, 2);
            return `
                <article class="paper-card">
                    <div class="prediction-label">${escapeHtml(label)}</div>
                    <div class="prediction-value ${numberClass(value)}">${escapeHtml(formatted)}</div>
                </article>
            `;
        }).join('');
    };

    const renderOpenPositionsMarkup = (positions) => {
        if (!positions?.length) {
            return '<div class="paper-empty">No open paper positions yet.</div>';
        }

        return positions.map((position) => `
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

    const renderHistoryMarkup = (history) => {
        if (!history?.length) {
            return '<div class="paper-empty">No closed simulated trades yet.</div>';
        }

        return history.map((position) => `
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

    window.DashboardView = {
        formatDate,
        formatNumber,
        formatZone,
        renderHistoryMarkup,
        renderOpenPositionsMarkup,
        renderPaperAccountMarkup,
        renderPaperPreviewMarkup,
        renderPredictionScenariosMarkup,
        renderPredictionTimeframesMarkup,
        renderSignalsMarkup,
        setHtmlIfChanged,
    };
})();
