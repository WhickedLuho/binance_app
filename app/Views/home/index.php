<?php
$enabledTimeframes = array_values(array_unique(array_map(
    static fn (mixed $timeframe): string => (string) $timeframe,
    $analysisTimeframes ?? []
)));
$enabledTimeframesLabel = $enabledTimeframes !== [] ? implode(', ', $enabledTimeframes) : 'nincs';
$decisionTimeframeLabel = (string) ($decisionTimeframe ?? 'n/a');
?>
<section class="hero">
    <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>
        Binance piacfigyelő csak a jelenleg engedélyezett elemzési idősíkokkal:
        <?= htmlspecialchars($enabledTimeframesLabel, ENT_QUOTES, 'UTF-8') ?>.
        Döntési idősík: <?= htmlspecialchars($decisionTimeframeLabel, ENT_QUOTES, 'UTF-8') ?>.
        A rendszer minden párt LONG, SHORT, SPOT_BUY, SPOT_SELL vagy NO_TRADE kategóriába sorol.
        Nyers API végpont: <a href="/api/signals">/api/signals</a>
    </p>
    <p class="meta">
        Frissítési intervallum: <strong><?= (int) $refreshSeconds ?> mp</strong>
        | Utolsó frissítés: <strong id="last-updated"><?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></strong>
        | Gyors linkek:
        <a href="/?refresh=5">5 mp</a>
        <a href="/?refresh=10">10 mp</a>
    </p>
</section>

<?php if ($error !== null): ?>
    <div class="error" id="dashboard-error">
        Binance kapcsolat vagy elemzési hiba: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php else: ?>
    <div class="error" id="dashboard-error" style="display:none;"></div>
<?php endif; ?>

<section class="grid" id="signal-grid">
    <?php foreach ($analysis as $row): ?>
        <?php
        $action = $row['action'] ?? ($row['direction'] ?? 'NO_TRADE');
        $badgeClass = match ($action) {
            'LONG', 'SPOT_BUY' => 'long',
            'SHORT', 'SPOT_SELL' => 'short',
            default => 'neutral',
        };
        $hasError = isset($row['error']) && $row['error'] !== '';
        ?>
        <article class="card" data-symbol="<?= htmlspecialchars((string) $row['symbol'], ENT_QUOTES, 'UTF-8') ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>
                    <div style="font-size:1.2rem;font-weight:700;"><?= htmlspecialchars($row['symbol'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="meta">Döntési idősík: <?= htmlspecialchars($row['interval'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="meta" style="margin-top:12px;">
                Ár: <?= number_format((float) $row['price'], 4, '.', ' ') ?><br>
                Piaci rezsim: <?= htmlspecialchars((string) ($row['market_regime'] ?? 'UNKNOWN'), ENT_QUOTES, 'UTF-8') ?><br>
                Bizalom: <?= (int) $row['confidence'] ?> / 100
            </div>

            <div class="reasons">
                <strong>Pontszámok</strong>
                <div class="metric"><span>Bika</span><span><?= (int) ($row['bull_score'] ?? 0) ?></span></div>
                <div class="metric"><span>Medve</span><span><?= (int) ($row['bear_score'] ?? 0) ?></span></div>
                <div class="metric"><span>Kockázati büntetés</span><span><?= (int) ($row['risk_penalty'] ?? 0) ?></span></div>
                <div class="metric"><span>RSI 14</span><span><?= number_format((float) ($row['metrics']['rsi14'] ?? 0), 2) ?></span></div>
                <div class="metric"><span>ATR %</span><span><?= number_format((float) ($row['metrics']['atr_percent'] ?? 0), 2) ?></span></div>
                <div class="metric"><span>Volumen arány</span><span><?= number_format((float) ($row['metrics']['volume_ratio'] ?? 0), 2) ?></span></div>
            </div>

            <div class="reasons">
                <strong>Idősíkok</strong><br>
                <?php if (!empty($row['timeframes'])): ?>
                    <?php foreach ($row['timeframes'] as $timeframe => $tf): ?>
                        <div class="metric"><span><?= htmlspecialchars((string) $timeframe, ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars((string) ($tf['bias'] ?? 'NEUTRAL'), ENT_QUOTES, 'UTF-8') ?></span></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    Nincs idősík adat.
                <?php endif; ?>
            </div>

            <div class="reasons">
                <strong>Indokok</strong><br>
                <?= htmlspecialchars(implode(', ', $row['reasons']) ?: 'Az indikátorok nem adnak elég erős egyetértést.', ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="risk">
                <strong>Kockázati szűrő</strong><br>
                <?= $row['risk']['allowed'] ? 'Nincs blokkoló feltétel.' : htmlspecialchars(implode(', ', $row['risk']['flags']), ENT_QUOTES, 'UTF-8') ?>
            </div>

            <div class="prediction-actions">
                <button type="button" class="prediction-button" data-action="prediction" data-symbol="<?= htmlspecialchars((string) $row['symbol'], ENT_QUOTES, 'UTF-8') ?>">
                    Előbecslés
                </button>
            </div>

            <?php if ($hasError): ?>
                <div class="risk" style="color:#ffd7d7;">
                    <strong>Párhiba</strong><br>
                    <?= htmlspecialchars($row['error'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <?php if ($analysis === [] && $error === null): ?>
        <article class="card">
            <div style="font-size:1.1rem;font-weight:700;">Nincs adat</div>
            <div class="meta">Beállított párok: <?= htmlspecialchars(implode(', ', $pairs), ENT_QUOTES, 'UTF-8') ?></div>
        </article>
    <?php endif; ?>
</section>

<section class="card prediction-panel" id="prediction-panel" style="margin-top:24px; display:none;">
    <div class="prediction-header">
        <div>
            <div class="prediction-kicker">Részletes előbecslés</div>
            <h2 id="prediction-title">Válassz egy párt</h2>
            <p class="meta" id="prediction-summary">Válassz egy kártyát, és a rendszer összerak egy részletesebb scenáriót az aktuális piaci adatok alapján.</p>
        </div>
        <div class="prediction-actions">
            <button type="button" class="prediction-button" id="prediction-refresh" disabled>Előbecslés frissítése</button>
        </div>
    </div>

    <div class="prediction-status" id="prediction-status">Az előbecslés jelenleg üresjáratban van.</div>

    <div class="prediction-grid" id="prediction-grid" style="display:none;">
        <article class="prediction-block">
            <div class="prediction-label">Piaci bias</div>
            <div class="prediction-value" id="prediction-bias">-</div>
            <div class="meta" id="prediction-confidence">Bizalom: -</div>
        </article>
        <article class="prediction-block">
            <div class="prediction-label">Aktuális ár</div>
            <div class="prediction-value" id="prediction-price">-</div>
            <div class="meta" id="prediction-generated">Generálva: -</div>
        </article>
        <article class="prediction-block">
            <div class="prediction-label">Támasz zóna</div>
            <div class="prediction-value" id="prediction-support">-</div>
            <div class="meta">Legközelebbi lefelé célzóna</div>
        </article>
        <article class="prediction-block">
            <div class="prediction-label">Ellenállás zóna</div>
            <div class="prediction-value" id="prediction-resistance">-</div>
            <div class="meta">Legközelebbi felfelé invalidációs terület</div>
        </article>
    </div>

    <div class="prediction-scenarios" id="prediction-scenarios" style="display:none;"></div>
    <div class="prediction-timeframes" id="prediction-timeframes" style="display:none;"></div>
</section>

<script>
    const refreshSeconds = <?= (int) $refreshSeconds ?>;
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
            ? `<div class="risk" style="color:#ffd7d7;"><strong>Párhiba</strong><br>${escapeHtml(row.error)}</div>`
            : '';

        return `
            <article class="card" data-symbol="${escapeHtml(row.symbol)}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                    <div>
                        <div style="font-size:1.2rem;font-weight:700;">${escapeHtml(row.symbol)}</div>
                        <div class="meta">Döntési idősík: ${escapeHtml(row.interval)}</div>
                    </div>
                    <span class="badge ${badgeClass}">${escapeHtml(action)}</span>
                </div>

                <div class="meta" style="margin-top:12px;">
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
        predictionPanel.style.display = 'block';
        predictionGrid.style.display = 'grid';
        predictionScenarios.style.display = 'grid';
        predictionTimeframes.style.display = 'grid';
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
        predictionPanel.style.display = 'block';
        predictionGrid.style.display = 'none';
        predictionScenarios.style.display = 'none';
        predictionTimeframes.style.display = 'none';
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
            predictionPanel.style.display = 'block';
            predictionGrid.style.display = 'none';
            predictionScenarios.style.display = 'none';
            predictionTimeframes.style.display = 'none';
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
            errorBox.style.display = 'none';
            lastUpdated.textContent = new Date().toLocaleString();
        } catch (error) {
            errorBox.textContent = `A frissítés sikertelen: ${error.message}`;
            errorBox.style.display = 'block';
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
</script>