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

    window.DashboardView = {
        escapeHtml,
        formatNumber,
        formatSigned,
        numberClass,
        positionSourceMeta,
        formatZone,
        formatDate,
        setHtmlIfChanged,
        formatDuration,
        automationRuntimeMeta,
        signalActionMeta,
        signalRiskMeta,
        renderSummaryStripMarkup,
        renderTimeframes,
    };
})();
