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

    const positionSourceMeta = (source) => source === 'AUTO_PREDICTION'
        ? { label: 'AUTO', className: 'neutral' }
        : { label: 'MANUAL', className: 'long' };

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

    const formatDuration = (value) => {
        const milliseconds = Number(value);
        if (!Number.isFinite(milliseconds) || milliseconds <= 0) {
            return '-';
        }

        if (milliseconds < 1000) {
            return `${Math.round(milliseconds)} ms`;
        }

        return `${(milliseconds / 1000).toFixed(milliseconds >= 10000 ? 1 : 2)} s`;
    };

    const automationRuntimeMeta = (health) => {
        switch (String(health || '').toUpperCase()) {
            case 'ACTIVE':
                return { label: 'ACTIVE', className: 'long' };
            case 'RUNNING':
                return { label: 'RUNNING', className: 'neutral' };
            case 'STALE':
                return { label: 'STALE', className: 'short' };
            case 'ERROR':
                return { label: 'ERROR', className: 'short' };
            default:
                return { label: 'UNKNOWN', className: 'neutral' };
        }
    };

    const signalActionMeta = (action) => {
        switch (String(action || '').toUpperCase()) {
            case 'LONG':
            case 'SPOT_BUY':
                return { label: String(action || 'LONG'), className: 'long' };
            case 'SHORT':
            case 'SPOT_SELL':
                return { label: String(action || 'SHORT'), className: 'short' };
            default:
                return { label: String(action || 'NO_TRADE'), className: 'neutral' };
        }
    };

    const signalRiskMeta = (row) => {
        if (row?.error) {
            return { label: 'PAIR ERROR', className: 'short' };
        }

        if (row?.risk?.allowed === false) {
            return { label: 'BLOCKED', className: 'short' };
        }

        return { label: 'CLEAR', className: 'long' };
    };

    const renderSummaryStripMarkup = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
            return '';
        }

        return items.map((item) => `
            <article class="summary-stat ${escapeHtml(item.className || '')}">
                <span>${escapeHtml(item.label || '')}</span>
                <strong>${escapeHtml(item.value ?? '-')}</strong>
            </article>
        `).join('');
    };

    const renderTimeframes = (timeframes) => {
        const entries = Object.entries(timeframes || {});
        if (!entries.length) {
            return '<div class="metric"><span>Timeframes</span><span>No data</span></div>';
        }

        return entries.map(([label, payload]) => `
            <div class="metric"><span>${escapeHtml(label)}</span><span>${escapeHtml(payload.bias || 'NEUTRAL')}</span></div>
        `).join('');
    };

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

    const renderPaperPreviewMarkup = ({ notional, quantity, riskPercent, rewardPercent }) => renderSummaryStripMarkup([
        { label: 'Notional', value: formatNumber(notional, 2) },
        { label: 'Quantity', value: formatNumber(quantity, 6) },
        { label: 'Risk %', value: formatNumber(riskPercent, 2) },
        { label: 'Reward %', value: formatNumber(rewardPercent, 2) },
    ]);

    const renderPaperAccountMarkup = (account) => {
        if (!account) {
            return '<div class="paper-empty">Paper account data is not available yet.</div>';
        }

        return renderSummaryStripMarkup([
            { label: 'Balance', value: formatNumber(account.balance, 2) },
            { label: 'Equity', value: formatNumber(account.equity, 2) },
            { label: 'Available', value: formatNumber(account.available_balance, 2) },
            { label: 'Margin', value: formatNumber(account.margin_in_use, 2) },
            { label: 'Realized PnL', value: formatSigned(account.realized_pnl, 2), className: Number(account.realized_pnl) >= 0 ? 'positive' : 'negative' },
            { label: 'Floating PnL', value: formatSigned(account.floating_pnl, 2), className: Number(account.floating_pnl) >= 0 ? 'positive' : 'negative' },
        ]);
    };

    const renderOpenPositionsMarkup = (positions) => {
        if (!positions?.length) {
            return '<div class="paper-empty">No open paper positions yet.</div>';
        }

        return positions.map((position) => {
            const source = positionSourceMeta(position.source);
            const pnlTone = Number(position.pnl_value) >= 0 ? 'long' : 'short';
            const liquidation = Number(position.liquidation_price_estimate);

            return `
                <article class="paper-card ${position.source === 'AUTO_PREDICTION' ? 'paper-card-auto' : ''}" data-paper-position-id="${escapeHtml(position.id)}">
                    <div class="paper-card-head">
                        <div>
                            <h4 class="paper-card-title">${escapeHtml(position.symbol)} ${escapeHtml(position.side)}</h4>
                            <div class="paper-card-subtitle">${escapeHtml(position.trade_type)} | ${escapeHtml(position.margin_type)} | ${escapeHtml(position.leverage)}x</div>
                            <div class="paper-source-row">
                                <span class="badge ${source.className}">${escapeHtml(source.label)}</span>
                                <span class="paper-card-subtitle">${escapeHtml(position.source === 'AUTO_PREDICTION' ? 'Prediction-managed position' : 'Opened manually')}</span>
                            </div>
                        </div>
                        <span class="badge ${pnlTone}">${escapeHtml(formatSigned(position.pnl_value, 2))}</span>
                    </div>

                    <div class="paper-card-grid">
                        <div class="metric"><span>Entry</span><span>${escapeHtml(formatNumber(position.entry_price, 4))}</span></div>
                        <div class="metric"><span>Current</span><span>${escapeHtml(formatNumber(position.current_price, 4))}</span></div>
                        <div class="metric"><span>PnL %</span><span class="${numberClass(position.pnl_percent)}">${escapeHtml(formatSigned(position.pnl_percent, 2, '%'))}</span></div>
                        <div class="metric"><span>ROE</span><span class="${numberClass(position.roe_percent)}">${escapeHtml(formatSigned(position.roe_percent, 2, '%'))}</span></div>
                        <div class="metric"><span>Capital</span><span>${escapeHtml(formatNumber(position.capital, 2))}</span></div>
                        <div class="metric"><span>Notional</span><span>${escapeHtml(formatNumber(position.notional, 2))}</span></div>
                        <div class="metric"><span>TP</span><span>${escapeHtml(formatNumber(position.take_profit, 4))}</span></div>
                        <div class="metric"><span>SL</span><span>${escapeHtml(formatNumber(position.stop_loss, 4))}</span></div>
                        <div class="metric"><span>Liq. est.</span><span>${escapeHtml(Number.isFinite(liquidation) ? formatNumber(liquidation, 4) : '-')}</span></div>
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
                                <option value="AUTO_OVERRIDE">Auto override</option>
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
            `;
        }).join('');
    };

    const renderHistoryMarkup = (history) => {
        if (!history?.length) {
            return '<div class="paper-empty">No closed simulated trades yet.</div>';
        }

        return history.map((position) => {
            const source = positionSourceMeta(position.source);
            const realizedTone = Number(position.realized_pnl) >= 0 ? 'long' : 'short';

            return `
                <article class="paper-history-card">
                    <div class="paper-card-head">
                        <div>
                            <h4 class="paper-card-title">${escapeHtml(position.symbol)} ${escapeHtml(position.side)}</h4>
                            <div class="paper-card-subtitle">${escapeHtml(position.trade_type)} | ${escapeHtml(position.margin_type)} | ${escapeHtml(position.leverage)}x</div>
                            <div class="paper-source-row">
                                <span class="badge ${source.className}">${escapeHtml(source.label)}</span>
                                <span class="paper-card-subtitle">${escapeHtml(position.exit_reason || 'N/A')}</span>
                            </div>
                        </div>
                        <span class="badge ${realizedTone}">${escapeHtml(formatSigned(position.realized_pnl, 2))}</span>
                    </div>
                    <div class="paper-card-grid">
                        <div class="metric"><span>Entry</span><span>${escapeHtml(formatNumber(position.entry_price, 4))}</span></div>
                        <div class="metric"><span>Close</span><span>${escapeHtml(formatNumber(position.closed_price, 4))}</span></div>
                        <div class="metric"><span>Result %</span><span class="${numberClass(position.realized_percent)}">${escapeHtml(formatSigned(position.realized_percent, 2, '%'))}</span></div>
                        <div class="metric"><span>ROE</span><span class="${numberClass(position.realized_roe)}">${escapeHtml(formatSigned(position.realized_roe, 2, '%'))}</span></div>
                        <div class="metric"><span>Opened</span><span>${escapeHtml(formatDate(position.opened_at))}</span></div>
                        <div class="metric"><span>Closed</span><span>${escapeHtml(formatDate(position.closed_at))}</span></div>
                    </div>
                </article>
            `;
        }).join('');
    };

    const renderAutomationSummaryMarkup = (summary, totalCapital) => {
        if (!summary) {
            return '';
        }

        return renderSummaryStripMarkup([
            { label: 'Enabled pairs', value: String(summary.enabled_pairs ?? 0) },
            { label: 'Manual %', value: `${formatNumber(summary.manual_total_percent, 2)}%` },
            { label: 'Auto pairs', value: String(summary.auto_pairs ?? 0) },
            { label: 'Remaining %', value: `${formatNumber(summary.remaining_percent, 2)}%` },
            { label: 'Allocated %', value: `${formatNumber(summary.allocated_percent, 2)}%` },
            { label: 'Capital pool', value: `${formatNumber(totalCapital, 2)} USDT` },
        ]);
    };

    const renderAutomationPairsMarkup = (pairs, totalCapital) => {
        const rows = Object.values(pairs || {});
        if (!rows.length) {
            return '<div class="paper-empty">No pairs configured for automation.</div>';
        }

        return rows.map((pair) => `
            <article class="automation-pair-card" data-automation-symbol="${escapeHtml(pair.symbol)}">
                <div class="automation-pair-top">
                    <div>
                        <h4 class="paper-card-title">${escapeHtml(pair.symbol)}</h4>
                        <div class="paper-card-subtitle">Effective capital: ${escapeHtml(formatNumber(pair.capital_usdt, 2))} / ${escapeHtml(formatNumber(totalCapital, 2))} USDT</div>
                    </div>
                    <label class="switch switch-compact">
                        <input type="checkbox" data-role="enabled" ${pair.enabled ? 'checked' : ''}>
                        <span class="switch-slider"></span>
                        <span class="switch-label">${pair.enabled ? 'Enabled' : 'Disabled'}</span>
                    </label>
                </div>
                <div class="automation-pair-grid">
                    <label class="field inline-edit">
                        <span>Manual %</span>
                        <input type="number" data-role="manual-allocation" min="0" max="100" step="0.01" placeholder="Auto" value="${pair.manual_allocation_percent ?? ''}">
                    </label>
                    <div class="automation-readonly">
                        <span>Effective %</span>
                        <strong>${escapeHtml(formatNumber(pair.effective_allocation_percent, 2))}%</strong>
                    </div>
                    <div class="automation-readonly">
                        <span>Capital</span>
                        <strong>${escapeHtml(formatNumber(pair.capital_usdt, 2))} USDT</strong>
                    </div>
                </div>
            </article>
        `).join('');
    };

    const renderAutomationPositionsMarkup = (positions) => {
        if (!positions?.length) {
            return '<div class="paper-empty">No auto-managed positions are open right now.</div>';
        }

        return positions.map((position) => {
            const triggerReward = position.automation?.trigger_reward_percent != null
                ? `${formatNumber(position.automation.trigger_reward_percent, 2)}%`
                : '-';
            const minReward = position.automation?.min_reward_percent != null
                ? `${formatNumber(position.automation.min_reward_percent, 2)}%`
                : '-';
            const predictionBias = position.automation?.prediction_bias || '-';

            return `
                <button type="button" class="automation-open-card" data-action="focus-auto-position" data-id="${escapeHtml(position.id)}">
                    <div class="automation-open-card-head">
                        <div>
                            <div class="paper-card-title">${escapeHtml(position.symbol)} ${escapeHtml(position.side)}</div>
                            <div class="paper-card-subtitle">${escapeHtml(position.trade_type)} | ${escapeHtml(position.margin_type)} | ${escapeHtml(position.leverage)}x</div>
                        </div>
                        <span class="badge ${Number(position.pnl_value) >= 0 ? 'long' : 'short'}">${escapeHtml(formatSigned(position.pnl_percent, 2, '%'))}</span>
                    </div>
                    <div class="automation-open-card-grid">
                        <div class="metric"><span>Entry</span><span>${escapeHtml(formatNumber(position.entry_price, 4))}</span></div>
                        <div class="metric"><span>Current</span><span>${escapeHtml(formatNumber(position.current_price, 4))}</span></div>
                        <div class="metric"><span>TP</span><span>${escapeHtml(formatNumber(position.take_profit, 4))}</span></div>
                        <div class="metric"><span>SL</span><span>${escapeHtml(formatNumber(position.stop_loss, 4))}</span></div>
                        <div class="metric"><span>Bias</span><span>${escapeHtml(predictionBias)}</span></div>
                        <div class="metric"><span>Trigger</span><span>${escapeHtml(triggerReward)}</span></div>
                        <div class="metric"><span>Min reward</span><span>${escapeHtml(minReward)}</span></div>
                        <div class="metric"><span>Opened</span><span>${escapeHtml(formatDate(position.opened_at))}</span></div>
                    </div>
                    <div class="automation-open-card-foot">Click to focus the full manual position card below.</div>
                </button>
            `;
        }).join('');
    };

    const renderAutomationRuntimeMarkup = (runtime) => {
        if (!runtime) {
            return '<div class="paper-empty">Runtime status is not available yet.</div>';
        }

        const health = automationRuntimeMeta(runtime.health);
        const stats = runtime.stats || {};
        const lastHeartbeat = runtime.last_finished_at
            ? formatDate(runtime.last_finished_at)
            : (runtime.last_started_at ? `Started: ${formatDate(runtime.last_started_at)}` : '-');
        const freshness = runtime.seconds_since_last_activity != null
            ? `${runtime.seconds_since_last_activity} sec ago`
            : '-';

        return [
            { label: 'State', value: runtime.state || 'UNKNOWN', className: health.className },
            { label: 'Freshness', value: freshness },
            { label: 'Last heartbeat', value: lastHeartbeat },
            { label: 'Duration', value: formatDuration(runtime.last_duration_ms) },
            { label: 'Opened', value: String(stats.opened_positions ?? 0) },
            { label: 'Closed', value: String(stats.closed_positions ?? 0) },
            { label: 'Auto open', value: String(stats.auto_open_positions ?? 0) },
            { label: 'Evaluated', value: String(stats.evaluated_pairs ?? 0) },
        ].map((item) => `
            <article class="runtime-stat ${escapeHtml(item.className || '')}">
                <span>${escapeHtml(item.label)}</span>
                <strong>${escapeHtml(item.value)}</strong>
            </article>
        `).join('') + `
            <article class="runtime-message-card">
                <div class="prediction-label">Scheduler message</div>
                <p>${escapeHtml(runtime.summary || runtime.message || 'No scheduler message is available yet.')}</p>
                ${runtime.last_error ? `<div class="risk pair-error"><strong>Last error</strong><br>${escapeHtml(runtime.last_error)}</div>` : ''}
            </article>
        `;
    };

    window.DashboardView = {
        formatDate,
        formatNumber,
        formatSigned,
        formatZone,
        renderAutomationPairsMarkup,
        renderAutomationPositionsMarkup,
        renderAutomationRuntimeMarkup,
        renderAutomationSummaryMarkup,
        renderHistoryMarkup,
        renderOpenPositionsMarkup,
        renderPaperAccountMarkup,
        renderPaperPreviewMarkup,
        renderPredictionScenariosMarkup,
        renderPredictionTimeframesMarkup,
        renderSignalsCompactMarkup,
        renderSignalsMarkup,
        renderSummaryStripMarkup,
        setHtmlIfChanged,
    };
})();
