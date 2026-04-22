(() => {
    const {
        automationRuntimeMeta,
        escapeHtml,
        formatDate,
        formatDuration,
        formatNumber,
        formatSigned,
        renderSummaryStripMarkup,
    } = window.DashboardView || {};

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

    Object.assign(window.DashboardView, {
        renderAutomationPairsMarkup,
        renderAutomationPositionsMarkup,
        renderAutomationRuntimeMarkup,
        renderAutomationSummaryMarkup,
    });
})();
