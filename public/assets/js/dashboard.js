(() => {
    const root = document.getElementById('dashboard-root');
    if (!root || !window.DashboardView) {
        return;
    }

    const {
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
    } = window.DashboardView;

    const parseConfiguredPairs = (value) => {
        if (!value) {
            return [];
        }

        try {
            const parsed = JSON.parse(value);
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    };

    const updateText = (element, value) => {
        if (element && element.textContent !== value) {
            element.textContent = value;
        }
    };

    const refreshSeconds = Number(root.dataset.refreshSeconds || 5);
    const configuredPairs = parseConfiguredPairs(root.dataset.configuredPairs);
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
    const predictionTabs = document.getElementById('prediction-tabs');
    const predictionTabButtons = Array.from(document.querySelectorAll('[data-tab]'));
    const predictionTabPanels = Array.from(document.querySelectorAll('[data-tab-panel]'));
    const paperPanel = document.getElementById('paper-panel');
    const paperStatus = document.getElementById('paper-status');
    const paperForm = document.getElementById('paper-form');
    const paperSubmitButton = paperForm?.querySelector('button[type="submit"]');
    const paperSymbol = document.getElementById('paper-symbol');
    const paperPositionType = document.getElementById('paper-position-type');
    const paperMarginType = document.getElementById('paper-margin-type');
    const paperLeverage = document.getElementById('paper-leverage');
    const paperCapital = document.getElementById('paper-capital');
    const paperEntryPrice = document.getElementById('paper-entry-price');
    const paperStopLoss = document.getElementById('paper-stop-loss');
    const paperTakeProfit = document.getElementById('paper-take-profit');
    const paperNotes = document.getElementById('paper-notes');
    const paperPreview = document.getElementById('paper-preview');
    const paperAccount = document.getElementById('paper-account');
    const paperOpenPositions = document.getElementById('paper-open-positions');
    const paperHistory = document.getElementById('paper-history');

    let refreshInFlight = false;
    let refreshTimerId = null;
    let predictionInFlight = false;
    let paperInFlight = false;
    let selectedPredictionSymbol = null;
    let activePredictionTab = 'prediction';
    let currentPrediction = null;
    let paperTabEnabled = false;
    let currentPaperTrading = null;

    const predictionDefaults = (prediction, positionType) => {
        const currentPrice = Number(prediction?.current_price || 0);
        const longScenario = prediction?.scenarios?.long || {};
        const shortScenario = prediction?.scenarios?.short || {};

        if (positionType === 'FUTURES_SHORT') {
            return {
                stopLoss: shortScenario.invalidation ?? '',
                takeProfit: shortScenario.suggested_take_profit ?? '',
                entryPrice: currentPrice || shortScenario.entry || '',
                notes: `Short simulation based on ${prediction?.symbol || 'selected'} prediction`,
            };
        }

        if (positionType === 'FUTURES_LONG') {
            return {
                stopLoss: longScenario.invalidation ?? '',
                takeProfit: longScenario.suggested_take_profit ?? '',
                entryPrice: currentPrice || longScenario.entry || '',
                notes: `Long simulation based on ${prediction?.symbol || 'selected'} prediction`,
            };
        }

        return {
            stopLoss: prediction?.zones?.support?.low ?? '',
            takeProfit: longScenario.suggested_take_profit ?? prediction?.zones?.resistance?.low ?? '',
            entryPrice: currentPrice || '',
            notes: `Spot simulation based on ${prediction?.symbol || 'selected'} prediction`,
        };
    };

    const setPaperBusy = (isBusy) => {
        paperInFlight = isBusy;

        if (paperSubmitButton) {
            paperSubmitButton.disabled = isBusy;
        }

        paperOpenPositions.querySelectorAll('button').forEach((button) => {
            button.disabled = isBusy;
        });
    };

    const renderPaperPreview = () => {
        const entryPrice = Number(paperEntryPrice.value || 0);
        const capital = Number(paperCapital.value || 0);
        const leverage = Number(paperLeverage.value || 1);
        const stopLoss = Number(paperStopLoss.value || 0);
        const takeProfit = Number(paperTakeProfit.value || 0);
        const effectiveLeverage = paperPositionType.value === 'SPOT' ? 1 : Math.max(1, leverage);
        const notional = capital > 0 ? capital * effectiveLeverage : 0;
        const quantity = entryPrice > 0 ? notional / entryPrice : 0;
        const riskPercent = (entryPrice > 0 && stopLoss > 0) ? Math.abs(((stopLoss - entryPrice) / entryPrice) * 100) : 0;
        const rewardPercent = (entryPrice > 0 && takeProfit > 0) ? Math.abs(((takeProfit - entryPrice) / entryPrice) * 100) : 0;

        setHtmlIfChanged(paperPreview, renderPaperPreviewMarkup({
            notional,
            quantity,
            riskPercent,
            rewardPercent,
        }));
    };

    const syncPaperForm = ({ preserveManual = false } = {}) => {
        if (!currentPrediction) {
            return;
        }

        const positionType = paperPositionType.value;
        const defaults = predictionDefaults(currentPrediction, positionType);
        const isSpot = positionType === 'SPOT';

        paperForm.classList.toggle('is-spot', isSpot);
        paperMarginType.disabled = isSpot;
        paperLeverage.disabled = isSpot;

        if (isSpot) {
            paperMarginType.value = 'ISOLATED';
            paperLeverage.value = '1';
        }

        paperSymbol.value = currentPrediction.symbol || '';

        if (!preserveManual) {
            paperEntryPrice.value = defaults.entryPrice !== '' ? defaults.entryPrice : '';
            paperStopLoss.value = defaults.stopLoss !== '' ? defaults.stopLoss : '';
            paperTakeProfit.value = defaults.takeProfit !== '' ? defaults.takeProfit : '';
            paperNotes.value = defaults.notes || '';
        }

        renderPaperPreview();
    };

    const syncPredictionTabs = () => {
        predictionTabButtons.forEach((button) => {
            const isPaperTab = button.dataset.tab === 'paper';
            const isDisabled = isPaperTab && !paperTabEnabled;
            const isActive = !isDisabled && button.dataset.tab === activePredictionTab;

            button.classList.toggle('is-active', isActive);
            button.classList.toggle('is-disabled', isDisabled);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
            button.disabled = isDisabled;
        });

        predictionTabPanels.forEach((panel) => {
            const isPaperPanel = panel.dataset.tabPanel === 'paper';
            panel.hidden = panel.dataset.tabPanel !== activePredictionTab || (isPaperPanel && !paperTabEnabled);
        });
    };

    const setActivePredictionTab = (tabName) => {
        activePredictionTab = tabName === 'paper' && paperTabEnabled ? 'paper' : 'prediction';
        syncPredictionTabs();
    };

    const setPredictionLoading = (symbol) => {
        currentPrediction = null;
        paperTabEnabled = false;
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;
        setActivePredictionTab('prediction');
        predictionGrid.hidden = true;
        predictionScenarios.hidden = true;
        predictionTimeframes.hidden = true;
        updateText(predictionTitle, `${symbol} prediction`);
        updateText(predictionSummary, 'Building a detailed scenario from live Binance market data.');
        updateText(predictionStatus, 'Loading prediction...');
    };

    const setPredictionError = (message) => {
        currentPrediction = null;
        paperTabEnabled = false;
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;
        setActivePredictionTab('prediction');
        predictionGrid.hidden = true;
        predictionScenarios.hidden = true;
        predictionTimeframes.hidden = true;
        updateText(predictionStatus, `Prediction failed: ${message}`);
        updateText(predictionSummary, 'Try again on the next refresh or choose another pair.');
    };

    const renderPrediction = (prediction) => {
        currentPrediction = prediction;
        paperTabEnabled = true;
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;
        setActivePredictionTab('prediction');
        predictionGrid.hidden = false;
        predictionScenarios.hidden = false;
        predictionTimeframes.hidden = false;

        updateText(predictionTitle, `${prediction.symbol} prediction`);
        updateText(predictionSummary, prediction.summary || 'Prediction built from current market data.');
        updateText(predictionStatus, 'Prediction loaded.');
        updateText(predictionBias, prediction.bias || 'RANGE');
        updateText(predictionConfidence, `Confidence: ${prediction.confidence ?? '-'} / 100`);
        updateText(predictionPrice, formatNumber(prediction.current_price, 4));
        updateText(predictionGenerated, `Generated: ${formatDate(prediction.generated_at)}`);
        updateText(predictionSupport, formatZone(prediction.zones?.support));
        updateText(predictionResistance, formatZone(prediction.zones?.resistance));

        setHtmlIfChanged(predictionScenarios, renderPredictionScenariosMarkup(prediction));
        setHtmlIfChanged(predictionTimeframes, renderPredictionTimeframesMarkup(prediction.timeframes || {}));

        syncPaperForm();
    };

    const renderPaperTrading = (paperTrading) => {
        currentPaperTrading = paperTrading;
        setHtmlIfChanged(paperAccount, renderPaperAccountMarkup(paperTrading?.account));
        setHtmlIfChanged(paperOpenPositions, renderOpenPositionsMarkup(paperTrading?.open_positions || []));
        setHtmlIfChanged(paperHistory, renderHistoryMarkup(paperTrading?.history || []));
        setPaperBusy(false);
    };

    const apiJson = async (url, options = {}) => {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(options.headers || {}),
            },
            cache: 'no-store',
            ...options,
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status === 'error') {
            throw new Error(payload.message || `HTTP ${response.status}`);
        }

        return payload;
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
            const payload = await apiJson(`/api/prediction?symbol=${encodeURIComponent(symbol)}`);
            renderPrediction(payload.prediction);
            updateText(paperStatus, `Paper trading defaults prepared for ${symbol}.`);
        } catch (error) {
            setPredictionError(error.message);
        } finally {
            predictionInFlight = false;
            predictionRefresh.disabled = !selectedPredictionSymbol;
        }
    };

    const loadPaperTrading = async ({ silent = false } = {}) => {
        if (paperInFlight) {
            return;
        }

        setPaperBusy(true);
        try {
            const payload = await apiJson('/api/paper-trades');
            renderPaperTrading(payload.paper_trading);
            if (!silent && currentPrediction) {
                updateText(paperStatus, `Paper trading account synced for ${currentPrediction.symbol}.`);
            }
        } catch (error) {
            setPaperBusy(false);
            if (!silent) {
                updateText(paperStatus, `Paper trading sync failed: ${error.message}`);
            }
        }
    };

    const submitPaperForm = async (event) => {
        event.preventDefault();
        if (!currentPrediction || paperInFlight) {
            return;
        }

        const positionType = paperPositionType.value;
        const tradeType = positionType === 'SPOT' ? 'SPOT' : 'FUTURES';
        const side = positionType === 'FUTURES_SHORT' ? 'SHORT' : 'LONG';
        const leverage = positionType === 'SPOT' ? 1 : Number(paperLeverage.value || 1);

        setPaperBusy(true);
        try {
            updateText(paperStatus, 'Opening paper position...');
            const payload = await apiJson('/api/paper-trades', {
                method: 'POST',
                body: JSON.stringify({
                    symbol: currentPrediction.symbol,
                    trade_type: tradeType,
                    side,
                    margin_type: paperMarginType.value,
                    leverage,
                    capital: paperCapital.value,
                    entry_price: paperEntryPrice.value,
                    stop_loss: paperStopLoss.value,
                    take_profit: paperTakeProfit.value,
                    notes: paperNotes.value,
                }),
            });
            renderPaperTrading(payload.paper_trading);
            updateText(paperStatus, payload.message || 'Paper position opened.');
        } catch (error) {
            setPaperBusy(false);
            updateText(paperStatus, `Paper position open failed: ${error.message}`);
        }
    };

    const updatePaperPosition = async (card, id) => {
        if (!card || paperInFlight) {
            return;
        }

        setPaperBusy(true);
        try {
            updateText(paperStatus, `Updating paper position #${id}...`);
            const payload = await apiJson('/api/paper-trades/update', {
                method: 'POST',
                body: JSON.stringify({
                    id,
                    stop_loss: card.querySelector('[data-role="stop-loss"]').value,
                    take_profit: card.querySelector('[data-role="take-profit"]').value,
                    notes: card.querySelector('[data-role="notes"]').value,
                }),
            });
            renderPaperTrading(payload.paper_trading);
            updateText(paperStatus, payload.message || 'Paper position updated.');
        } catch (error) {
            setPaperBusy(false);
            updateText(paperStatus, `Paper position update failed: ${error.message}`);
        }
    };

    const closePaperPosition = async (card, id) => {
        if (!card || paperInFlight) {
            return;
        }

        setPaperBusy(true);
        try {
            updateText(paperStatus, `Closing paper position #${id}...`);
            const payload = await apiJson('/api/paper-trades/close', {
                method: 'POST',
                body: JSON.stringify({
                    id,
                    reason: card.querySelector('[data-role="close-reason"]').value,
                }),
            });
            renderPaperTrading(payload.paper_trading);
            updateText(paperStatus, payload.message || 'Paper position closed.');
        } catch (error) {
            setPaperBusy(false);
            updateText(paperStatus, `Paper position close failed: ${error.message}`);
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
            const payload = await apiJson('/api/signals');
            if (!Array.isArray(payload.signals)) {
                throw new Error('Invalid signals payload.');
            }

            setHtmlIfChanged(signalGrid, renderSignalsMarkup(payload.signals, configuredPairs));
            errorBox.hidden = true;
            updateText(lastUpdated, new Date().toLocaleString());

            const hasOpenPaperPositions = Array.isArray(currentPaperTrading?.open_positions) && currentPaperTrading.open_positions.length > 0;
            if (!paperPanel.hidden || hasOpenPaperPositions) {
                void loadPaperTrading({ silent: true });
            }
        } catch (error) {
            updateText(errorBox, `Refresh failed: ${error.message}`);
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

        void loadPrediction(target.dataset.symbol || '');
    });

    predictionRefresh.addEventListener('click', () => {
        if (selectedPredictionSymbol !== null) {
            void loadPrediction(selectedPredictionSymbol);
        }
    });

    predictionTabs.addEventListener('click', (event) => {
        const tabButton = event.target.closest('[data-tab]');
        if (!tabButton || predictionTabs.hidden || tabButton.disabled) {
            return;
        }

        setActivePredictionTab(tabButton.dataset.tab || 'prediction');
    });

    paperPositionType.addEventListener('change', () => syncPaperForm());
    [paperMarginType, paperLeverage, paperCapital, paperEntryPrice, paperStopLoss, paperTakeProfit].forEach((element) => {
        element.addEventListener('input', renderPaperPreview);
    });
    paperForm.addEventListener('submit', submitPaperForm);

    paperOpenPositions.addEventListener('click', (event) => {
        const saveButton = event.target.closest('[data-action="save-paper-position"]');
        if (saveButton) {
            const card = saveButton.closest('[data-paper-position-id]');
            void updatePaperPosition(card, Number(saveButton.dataset.id || 0));
            return;
        }

        const closeButton = event.target.closest('[data-action="close-paper-position"]');
        if (closeButton) {
            const card = closeButton.closest('[data-paper-position-id]');
            void closePaperPosition(card, Number(closeButton.dataset.id || 0));
        }
    });

    syncPredictionTabs();
    renderPaperPreview();
    void loadPaperTrading({ silent: true });
    scheduleRefresh();
})();
