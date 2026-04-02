<?php
$enabledTimeframes = array_values(array_unique(array_map(
    static fn (mixed $timeframe): string => (string) $timeframe,
    $analysisTimeframes ?? []
)));
$enabledTimeframesLabel = $enabledTimeframes !== [] ? implode(', ', $enabledTimeframes) : 'nincs';
$decisionTimeframeLabel = (string) ($decisionTimeframe ?? 'n/a');
?>
<div id="dashboard-root" data-refresh-seconds="<?= (int) $refreshSeconds ?>">
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
        <div class="error" id="dashboard-error" hidden></div>
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
                <div class="signal-card-head">
                    <div>
                        <div class="signal-symbol"><?= htmlspecialchars($row['symbol'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="meta">Döntési idősík: <?= htmlspecialchars($row['interval'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="meta signal-meta-block">
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
                    <div class="risk pair-error">
                        <strong>Párhiba</strong><br>
                        <?= htmlspecialchars($row['error'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($analysis === [] && $error === null): ?>
            <article class="card">
                <div class="signal-symbol">Nincs adat</div>
                <div class="meta">Beállított párok: <?= htmlspecialchars(implode(', ', $pairs), ENT_QUOTES, 'UTF-8') ?></div>
            </article>
        <?php endif; ?>
    </section>

    <section class="card prediction-panel" id="prediction-panel" hidden>
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

        <div class="prediction-grid" id="prediction-grid" hidden>
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

        <div class="prediction-scenarios" id="prediction-scenarios" hidden></div>
        <div class="prediction-timeframes" id="prediction-timeframes" hidden></div>
    </section>
</div>
<script src="/assets/js/dashboard.js" defer></script>