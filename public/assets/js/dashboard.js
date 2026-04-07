(() => {
    const root = document.getElementById('dashboard-root');
    if (!root || !window.DashboardView) {
        return;
    }

    const {
        formatDate,
        formatNumber,
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
    const automationPanel = document.getElementById('automation-panel');
    const automationContent = document.getElementById('automation-content');
    const automationStatus = document.getElementById('automation-status');
    const automationForm = document.getElementById('automation-form');
    const automationRefresh = document.getElementById('automation-refresh');
    const automationToggle = document.getElementById('automation-toggle');
    const automationSave = document.getElementById('automation-save');
    const automationEnabled = document.getElementById('automation-enabled');
    const automationTotalCapital = document.getElementById('automation-total-capital');
    const automationMaxOpenPositions = document.getElementById('automation-max-open-positions');
    const automationPositionType = document.getElementById('automation-position-type');
    const automationMarginType = document.getElementById('automation-margin-type');
    const automationLeverage = document.getElementById('automation-leverage');
    const automationMinProfitSpot = document.getElementById('automation-min-profit-spot');
    const automationMinProfitLong = document.getElementById('automation-min-profit-long');
    const automationMinProfitShort = document.getElementById('automation-min-profit-short');
    const automationMaxPredictionAtr = document.getElementById('automation-max-prediction-atr');
    const automationMaxCandleChange = document.getElementById('automation-max-candle-change');
    const automationCooldownMinutes = document.getElementById('automation-cooldown-minutes');
    const automationCloseOnTakeProfit = document.getElementById('automation-close-on-take-profit');
    const automationCloseOnStopLoss = document.getElementById('automation-close-on-stop-loss');
    const automationSummary = document.getElementById('automation-summary');
    const automationPairs = document.getElementById('automation-pairs');
    const automationRuntime = document.getElementById('automation-runtime');
    const automationOpenPositions = document.getElementById('automation-open-positions');
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
    let automationInFlight = false;
    let automationHeartbeatInFlight = false;
    let currentAutomationSettings = null;
    let currentAutomationRuntime = null;
    const automationPanelStorageKey = 'dashboard.automation-panel';

    const readAutomationPanelExpanded = () => {
        try {
            return window.localStorage.getItem(automationPanelStorageKey) !== 'collapsed';
        } catch {
            return true;
        }
    };

    const setAutomationPanelExpanded = (expanded) => {
        automationPanel.classList.toggle('is-collapsed', !expanded);
        automationContent.hidden = !expanded;
        automationToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        automationToggle.textContent = expanded ? 'Collapse' : 'Expand';

        try {
            window.localStorage.setItem(automationPanelStorageKey, expanded ? 'expanded' : 'collapsed');
        } catch {
            // Ignore storage failures and keep the in-memory UI state.
        }
    };

    const extractAutoPositions = (paperTrading) => {
        const positions = Array.isArray(paperTrading?.open_positions) ? paperTrading.open_positions : [];
        return positions.filter((position) => position.source === 'AUTO_PREDICTION');
    };

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

    const setAutomationBusy = (isBusy) => {
        automationInFlight = isBusy;

        if (automationSave) {
            automationSave.disabled = isBusy;
        }

        if (automationRefresh) {
            automationRefresh.disabled = isBusy;
        }
    };

    const normalizeManualAllocation = (value) => {
        if (value === '' || value === null || value === undefined) {
            return null;
        }

        const number = Number(value);
        if (!Number.isFinite(number)) {
            return null;
        }

        return Math.max(0, Math.min(100, number));
    };

    const readAutomationDraft = () => {
        const pairDraft = {};
        automationPairs.querySelectorAll('[data-automation-symbol]').forEach((card) => {
            const symbol = card.dataset.automationSymbol;
            pairDraft[symbol] = {
                enabled: card.querySelector('[data-role="enabled"]').checked,
                manual_allocation_percent: normalizeManualAllocation(card.querySelector('[data-role="manual-allocation"]').value),
            };
        });

        return {
            enabled: automationEnabled.checked,
            total_capital_usdt: Number(automationTotalCapital.value || 0),
            max_open_positions: Number(automationMaxOpenPositions.value || 1),
            default_position_type: automationPositionType.value,
            default_margin_type: automationMarginType.value,
            default_leverage: Number(automationLeverage.value || 1),
            min_profit_trigger_percent_spot: Number(automationMinProfitSpot.value || 0),
            min_profit_trigger_percent_long: Number(automationMinProfitLong.value || 0),
            min_profit_trigger_percent_short: Number(automationMinProfitShort.value || 0),
            max_prediction_atr_percent: Number(automationMaxPredictionAtr.value || 0),
            max_signal_candle_change_percent: Number(automationMaxCandleChange.value || 0),
            cooldown_minutes: Number(automationCooldownMinutes.value || 0),
            close_on_take_profit: automationCloseOnTakeProfit.checked,
            close_on_stop_loss: automationCloseOnStopLoss.checked,
            pairs: pairDraft,
        };
    };

    const computeAutomationPreview = (draft) => {
        const normalizedPairs = {};
        const rows = Object.entries(draft.pairs || {});
        let manualTotal = 0;
        let autoPairsCount = 0;

        rows.forEach(([symbol, pair]) => {
            const enabled = Boolean(pair.enabled);
            const manual = enabled ? normalizeManualAllocation(pair.manual_allocation_percent) : null;
            if (enabled && manual !== null) {
                manualTotal += manual;
            }
            if (enabled && manual === null) {
                autoPairsCount += 1;
            }
            normalizedPairs[symbol] = {
                symbol,
                enabled,
                manual_allocation_percent: manual,
                effective_allocation_percent: 0,
                capital_usdt: 0,
            };
        });

        const remainingPercent = Math.max(0, 100 - manualTotal);
        let remainingToDistribute = remainingPercent;
        let autoSlots = autoPairsCount;
        const autoShare = autoPairsCount > 0 ? remainingPercent / autoPairsCount : 0;
        const totalCapital = Math.max(0, Number(draft.total_capital_usdt || 0));

        Object.values(normalizedPairs).forEach((pair) => {
            if (!pair.enabled) {
                return;
            }

            let effective = pair.manual_allocation_percent;
            if (effective === null) {
                autoSlots -= 1;
                effective = autoSlots === 0 ? remainingToDistribute : Number(autoShare.toFixed(4));
                remainingToDistribute = Math.max(0, remainingToDistribute - effective);
            }

            pair.effective_allocation_percent = Number(effective.toFixed(4));
            pair.capital_usdt = Number(((totalCapital * pair.effective_allocation_percent) / 100).toFixed(4));
        });

        return {
            ...draft,
            pairs: normalizedPairs,
            summary: {
                enabled_pairs: Object.values(normalizedPairs).filter((pair) => pair.enabled).length,
                manual_total_percent: Number(manualTotal.toFixed(4)),
                auto_pairs: autoPairsCount,
                remaining_percent: Number(remainingPercent.toFixed(4)),
                allocated_percent: Number(Object.values(normalizedPairs).reduce((carry, pair) => carry + pair.effective_allocation_percent, 0).toFixed(4)),
            },
        };
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

    const updatePaperTabAvailability = () => {
        paperTabEnabled = Boolean(currentPrediction) || Boolean(currentPaperTrading?.open_positions?.length);
        if (!paperTabEnabled && activePredictionTab === 'paper') {
            activePredictionTab = 'prediction';
        }
        syncPredictionTabs();
    };

    const setActivePredictionTab = (tabName) => {
        activePredictionTab = tabName === 'paper' && paperTabEnabled ? 'paper' : 'prediction';
        syncPredictionTabs();
    };

    const renderAutomationOpenPositions = (paperTrading) => {
        setHtmlIfChanged(automationOpenPositions, renderAutomationPositionsMarkup(extractAutoPositions(paperTrading)));
    };

    const renderAutomationRuntime = (runtime) => {
        currentAutomationRuntime = runtime;
        setHtmlIfChanged(automationRuntime, renderAutomationRuntimeMarkup(runtime));
    };

    const renderAutomationSettings = (settings) => {
        currentAutomationSettings = settings;
        automationEnabled.checked = Boolean(settings?.enabled);
        automationTotalCapital.value = settings?.total_capital_usdt ?? 100;
        automationMaxOpenPositions.value = settings?.max_open_positions ?? 3;
        automationPositionType.value = settings?.default_position_type || 'FUTURES_LONG';
        automationMarginType.value = settings?.default_margin_type || 'ISOLATED';
        automationLeverage.value = settings?.default_leverage ?? 5;
        automationMinProfitSpot.value = settings?.min_profit_trigger_percent_spot ?? 2.5;
        automationMinProfitLong.value = settings?.min_profit_trigger_percent_long ?? 2.5;
        automationMinProfitShort.value = settings?.min_profit_trigger_percent_short ?? 2.5;
        automationMaxPredictionAtr.value = settings?.max_prediction_atr_percent ?? 3.5;
        automationMaxCandleChange.value = settings?.max_signal_candle_change_percent ?? 2.5;
        automationCooldownMinutes.value = settings?.cooldown_minutes ?? 30;
        automationCloseOnTakeProfit.checked = Boolean(settings?.close_on_take_profit ?? true);
        automationCloseOnStopLoss.checked = Boolean(settings?.close_on_stop_loss ?? true);
        setHtmlIfChanged(automationSummary, renderAutomationSummaryMarkup(settings?.summary, settings?.total_capital_usdt));
        setHtmlIfChanged(automationPairs, renderAutomationPairsMarkup(settings?.pairs, settings?.total_capital_usdt));
        updateText(automationStatus, settings?.enabled
            ? 'Automation is enabled. The background scheduler handles the heartbeat, while this dashboard only shows the live state.'
            : 'Automation is disabled. Configure the trigger and volatility limits, then enable it when you are ready.');
    };

    const refreshAutomationPreview = () => {
        const preview = computeAutomationPreview(readAutomationDraft());
        currentAutomationSettings = preview;
        setHtmlIfChanged(automationSummary, renderAutomationSummaryMarkup(preview.summary, preview.total_capital_usdt));
        setHtmlIfChanged(automationPairs, renderAutomationPairsMarkup(preview.pairs, preview.total_capital_usdt));
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

    const setPredictionLoading = (symbol) => {
        currentPrediction = null;
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;
        updatePaperTabAvailability();
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
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;
        updatePaperTabAvailability();
        setActivePredictionTab('prediction');
        predictionGrid.hidden = true;
        predictionScenarios.hidden = true;
        predictionTimeframes.hidden = true;
        updateText(predictionStatus, `Prediction failed: ${message}`);
        updateText(predictionSummary, 'Try again on the next refresh or choose another pair.');
    };

    const renderPrediction = (prediction) => {
        currentPrediction = prediction;
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;
        updatePaperTabAvailability();
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
        renderAutomationOpenPositions(paperTrading);
        setPaperBusy(false);
        updatePaperTabAvailability();
    };
    const focusPaperPosition = (id) => {
        predictionPanel.hidden = false;
        predictionTabs.hidden = false;

        if (!currentPrediction) {
            updateText(predictionTitle, 'Paper trading overview');
            updateText(predictionSummary, 'Manage open simulated positions and adjust their live levels here.');
        }

        updatePaperTabAvailability();
        setActivePredictionTab('paper');

        const target = paperOpenPositions.querySelector(`[data-paper-position-id="${String(id)}"]`);
        if (!target) {
            return;
        }

        paperOpenPositions.querySelectorAll('.is-focused').forEach((card) => card.classList.remove('is-focused'));
        target.classList.add('is-focused');
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        window.setTimeout(() => target.classList.remove('is-focused'), 1800);
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

    const loadAutomationSettings = async ({ silent = false } = {}) => {
        if (automationInFlight) {
            return;
        }

        setAutomationBusy(true);
        try {
            const payload = await apiJson('/api/auto-trade-settings');
            renderAutomationSettings(payload.settings);
            if (!silent) {
                updateText(automationStatus, 'Auto trade settings loaded.');
            }
        } catch (error) {
            if (!silent) {
                updateText(automationStatus, `Auto trade settings failed to load: ${error.message}`);
            }
        } finally {
            setAutomationBusy(false);
        }
    };

    const loadAutomationRuntime = async ({ silent = false } = {}) => {
        if (automationHeartbeatInFlight) {
            return;
        }

        automationHeartbeatInFlight = true;
        try {
            const payload = await apiJson('/api/automation/status');
            renderAutomationRuntime(payload.automation_status || null);
            if (!silent) {
                updateText(automationStatus, payload.message || 'Automation runtime loaded.');
            }
        } catch (error) {
            if (!silent) {
                updateText(automationStatus, `Automation runtime failed to load: ${error.message}`);
            }
        } finally {
            automationHeartbeatInFlight = false;
        }
    };

    const saveAutomationSettings = async (event) => {
        event.preventDefault();
        if (automationInFlight) {
            return;
        }

        const draft = computeAutomationPreview(readAutomationDraft());
        setAutomationBusy(true);
        updateText(automationStatus, 'Saving auto trade settings...');

        try {
            const payload = await apiJson('/api/auto-trade-settings', {
                method: 'POST',
                body: JSON.stringify(draft),
            });
            renderAutomationSettings(payload.settings);
            updateText(automationStatus, payload.message || 'Auto trade settings saved.');
            void loadPaperTrading({ silent: false });
        } catch (error) {
            updateText(automationStatus, `Auto trade settings save failed: ${error.message}`);
        } finally {
            setAutomationBusy(false);
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
            void loadPaperTrading({ silent: true });
            void loadAutomationRuntime({ silent: true });
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

    automationToggle.addEventListener('click', () => {
        setAutomationPanelExpanded(automationContent.hidden);
    });

    automationRefresh.addEventListener('click', () => {
        void loadAutomationSettings();
        void loadAutomationRuntime({ silent: false });
        void loadPaperTrading({ silent: false });
    });

    automationForm.addEventListener('submit', saveAutomationSettings);
    automationForm.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target === automationTotalCapital || target.closest('#automation-pairs')) {
            refreshAutomationPreview();
        }
    });
    automationForm.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target === automationEnabled || target.closest('#automation-pairs')) {
            refreshAutomationPreview();
        }
    });

    automationOpenPositions.addEventListener('click', (event) => {
        const target = event.target.closest('[data-action="focus-auto-position"]');
        if (!target) {
            return;
        }

        focusPaperPosition(Number(target.dataset.id || 0));
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

    updatePaperTabAvailability();
    setAutomationPanelExpanded(readAutomationPanelExpanded());
    renderPaperPreview();
    renderAutomationRuntime(currentAutomationRuntime);
    renderAutomationOpenPositions({ open_positions: [] });
    void loadAutomationSettings({ silent: true });
    void loadAutomationRuntime({ silent: true });
    void loadPaperTrading({ silent: true });
    scheduleRefresh();
})();
