(() => {
    const {
        escapeHtml,
        formatDate,
        formatNumber,
        formatSigned,
        numberClass,
        positionSourceMeta,
        renderSummaryStripMarkup,
    } = window.DashboardView || {};

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

    Object.assign(window.DashboardView, {
        renderPaperAccountMarkup,
        renderPaperPreviewMarkup,
        renderOpenPositionsMarkup,
        renderHistoryMarkup,
    });
})();
