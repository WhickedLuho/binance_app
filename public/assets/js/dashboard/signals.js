(() => {
    const {
        escapeHtml,
        formatNumber,
        signalActionMeta,
        signalRiskMeta,
        renderTimeframes,
    } = window.DashboardView || {};

    const renderEmptySignalsCompact = (configuredPairs) => `
        <article class="compact-signal-row empty-state-row">
            <div>
                <strong>No live signal data</strong>
                <span>${escapeHtml((configuredPairs || []).join(', ') || 'No configured pairs')}</span>
            </div>
        </article>
    `;

    const renderCompactSignalRow = (row) => {
        const action = row.action || row.direction || 'NO_TRADE';
        const badge = signalActionMeta(action);
        const risk = signalRiskMeta(row);

        return `
            <article class="compact-signal-row">
                <div class="compact-signal-main">
                    <div>
                        <strong>${escapeHtml(row.symbol || '-')}</strong>
                        <span>${escapeHtml(row.market_regime || 'UNKNOWN')} | ${escapeHtml(row.interval || 'n/a')}</span>
                    </div>
                    <span class="badge ${badge.className}">${escapeHtml(badge.label)}</span>
                </div>
                <div class="compact-signal-metrics">
                    <div class="mini-metric"><span>Price</span><strong>${escapeHtml(formatNumber(row.price, 4))}</strong></div>
                    <div class="mini-metric"><span>Confidence</span><strong>${escapeHtml(formatNumber(row.confidence, 0))}</strong></div>
                    <div class="mini-metric"><span>ATR %</span><strong>${escapeHtml(formatNumber(row.metrics?.atr_percent, 2))}</strong></div>
                    <div class="mini-metric"><span>Risk</span><strong class="${risk.className === 'long' ? 'positive-number' : (risk.className === 'short' ? 'negative-number' : 'muted-number')}">${escapeHtml(risk.label)}</strong></div>
                </div>
                <div class="compact-signal-actions">
                    <button type="button" class="prediction-button compact-button" data-action="prediction" data-symbol="${escapeHtml(row.symbol || '')}">Prediction</button>
                </div>
            </article>
        `;
    };

    const renderSignalsCompactMarkup = (signals, configuredPairs) => {
        if (!Array.isArray(signals) || signals.length === 0) {
            return renderEmptySignalsCompact(configuredPairs);
        }

        return signals.map(renderCompactSignalRow).join('');
    };

    const renderSignalCard = (row) => {
        const action = row.action || row.direction || 'NO_TRADE';
        const badge = signalActionMeta(action);
        const reasons = row.reasons && row.reasons.length
            ? row.reasons.join(', ')
            : 'Indicators do not agree strongly enough.';
        const riskText = row.risk?.allowed
            ? 'No blocking condition.'
            : ((row.risk?.flags && row.risk.flags.length) ? row.risk.flags.join(', ') : 'Unknown risk state.');

        return `
            <article class="card signal-card" data-symbol="${escapeHtml(row.symbol || '')}">
                <div class="signal-card-head">
                    <div>
                        <div class="signal-symbol">${escapeHtml(row.symbol || '-')}</div>
                        <div class="meta">${escapeHtml(row.market_regime || 'UNKNOWN')} | Decision timeframe: ${escapeHtml(row.interval || 'n/a')}</div>
                    </div>
                    <span class="badge ${badge.className}">${escapeHtml(badge.label)}</span>
                </div>

                <div class="signal-top-metrics">
                    <div class="mini-metric"><span>Price</span><strong>${escapeHtml(formatNumber(row.price, 4))}</strong></div>
                    <div class="mini-metric"><span>Confidence</span><strong>${escapeHtml(formatNumber(row.confidence, 0))} / 100</strong></div>
                    <div class="mini-metric"><span>Bull</span><strong>${escapeHtml(String(row.bull_score ?? row.long_score ?? 0))}</strong></div>
                    <div class="mini-metric"><span>Bear</span><strong>${escapeHtml(String(row.bear_score ?? row.short_score ?? 0))}</strong></div>
                    <div class="mini-metric"><span>ATR %</span><strong>${escapeHtml(formatNumber(row.metrics?.atr_percent, 2))}</strong></div>
                    <div class="mini-metric"><span>Volume</span><strong>${escapeHtml(formatNumber(row.metrics?.volume_ratio, 2))}</strong></div>
                </div>

                <div class="signal-card-sections">
                    <div class="signal-section">
                        <strong>Reasons</strong>
                        <p>${escapeHtml(reasons)}</p>
                    </div>
                    <div class="signal-section">
                        <strong>Risk filter</strong>
                        <p>${escapeHtml(riskText)}</p>
                    </div>
                    <div class="signal-section">
                        <strong>Timeframes</strong>
                        <div class="signal-timeframes">${renderTimeframes(row.timeframes)}</div>
                    </div>
                </div>

                <div class="prediction-actions compact-actions">
                    <button type="button" class="prediction-button" data-action="prediction" data-symbol="${escapeHtml(row.symbol || '')}">Prediction</button>
                </div>

                ${row.error ? `<div class="risk pair-error"><strong>Pair error</strong><br>${escapeHtml(row.error)}</div>` : ''}
            </article>
        `;
    };

    const renderSignalsMarkup = (signals, configuredPairs) => {
        if (!Array.isArray(signals) || signals.length === 0) {
            return `
                <article class="card empty-state-card">
                    <div class="signal-symbol">No data</div>
                    <div class="meta">Configured pairs: ${escapeHtml((configuredPairs || []).join(', ') || 'No configured pairs')}</div>
                </article>
            `;
        }

        return signals.map(renderSignalCard).join('');
    };

    Object.assign(window.DashboardView, {
        renderSignalsCompactMarkup,
        renderSignalsMarkup,
    });
})();
