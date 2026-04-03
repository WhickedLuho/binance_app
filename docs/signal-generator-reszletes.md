# Signal generátor részletes működése

## Cél

Ez a dokumentum kizárólag az első fő blokkot írja le: a signal generátort.
Fókuszban a teljes futási lánc van:

- honnan indul a folyamat,
- melyik osztály mit hív meg,
- milyen inputtal dolgozik,
- milyen köztes adatok keletkeznek,
- hogyan lesz a konfigurált párból és idősávokból végül UI-on megjelenő signal.

Ez a blokk a projekt technikai döntéstámogató motorja.

## Rövid definíció

A signal generátor egy több lépcsős pipeline:

1. a rendszer beolvassa a konfigurált párokat és idősávokat,
2. Binance gyertyákat kér le minden párhoz és timeframe-hez,
3. timeframe-enként technikai elemzést végez,
4. a részpontszámokat összesíti,
5. kockázati szűrést alkalmaz,
6. meghatározza a végső akciót,
7. a jelet HTML-ben és JSON API-n keresztül is elérhetővé teszi.

## A blokk fő szereplői

### Konfiguráció

- `config/pairs.php`
- `config/strategy.php`
- `config/app.php`

### Indítás és összekötés

- `public/index.php`
- `app/Bootstrap/App.php`
- `app/Core/Container.php`
- `app/Core/Router.php`
- `app/Core/Request.php`
- `app/Core/Response.php`
- `app/Core/View.php`

### Signal lánc üzleti osztályai

- `app/Controllers/HomeController.php`
- `app/Controllers/Api/SignalController.php`
- `app/Services/Market/MarketAnalyzer.php`
- `app/Services/Binance/BinanceApiClient.php`
- `app/Services/Strategy/SignalEngine.php`
- `app/Services/Strategy/IndicatorService.php`
- `app/Services/Strategy/RiskFilterService.php`

### UI oldali megjelenítés

- `app/Views/home/index.php`
- `public/assets/js/dashboard.js`

## A signal lánc két belépési útvonala

Ugyanaz a signal számítási logika két helyről indulhat el.

### 1. Szerver oldali első render

Ekkor a felhasználó megnyitja a főoldalt:

- route: `GET /`
- controller: `HomeController::index()`
- számítás: `MarketAnalyzer::analyzeConfiguredPairs()`
- render: `View::render('home/index', ...)`

Ez adja az oldal első, már kitöltött HTML állapotát.

### 2. Kliens oldali periodikus frissítés

Ekkor a frontend JavaScript időzítve frissíti a signalokat:

- route: `GET /api/signals`
- controller: `SignalController::index()`
- számítás: `MarketAnalyzer::analyzeConfiguredPairs()`
- output: JSON payload

Ez frissíti a kártyákat az oldalon.

Fontos: a két útvonal ugyanabba az elemző service-be fut be, vagyis a tényleges signal generálás központja a `MarketAnalyzer` + `SignalEngine`.

## Teljes futási lánc végponttól a UI-ig

## 1. HTTP kérés beérkezik

### `public/index.php`

Feladata:

- beállítja az UTF-8 környezetet,
- beolvassa az alkalmazás timezone-ját,
- betölti az autoloadert,
- példányosítja az `App\Bootstrap\App` objektumot,
- meghívja az `App::run()` metódust.

### Input

- PHP szerver környezet
- `config/app.php`
- HTTP kérés

### Output

- az alkalmazás bootstrapelt futása

## 2. Az alkalmazás összerakja a service-láncot

### `app/Bootstrap/App.php`

Az `App::run()` a központi bekötési pont.

Feladata:

- létrehozza a `Container` példányt,
- betölti a `Config`-ot,
- beállítja a timezone-t,
- regisztrálja a service-eket,
- beregisztrálja a route-okat,
- elkészíti a `Request` objektumot,
- dispatcheli a route-ot,
- kiküldi a `Response`-t.

### A signal blokknál releváns DI kötései

#### `Config`

A teljes konfigurációt adja.

#### `BinanceApiClient`

Ő végzi a külső Binance REST hívásokat.

#### `IndicatorService`

Ő számolja a technikai indikátorokat.

#### `RiskFilterService`

Ő dönt a kockázati tiltásokról a `strategy` config alapján.

#### `SignalEngine`

Ő a fő döntési motor.
Konstruktor inputjai:

- `strategy` config tömb
- `pairs` config tömb
- `IndicatorService`
- `RiskFilterService`

#### `MarketAnalyzer`

Ő szervezi az egész folyamatot.
Konstruktor inputjai:

- `Config`
- `BinanceApiClient`
- `SignalEngine`

#### `HomeController`

Konstruktor inputjai:

- `View`
- `MarketAnalyzer`
- `Config`

#### `SignalController`

Konstruktor inputjai:

- `MarketAnalyzer`
- `Config`

## 3. A router kiválasztja a végpontot

### Route definíciók

#### `routes/web.php`

- `GET /` -> `HomeController::index`

#### `routes/api.php`

- `GET /api/signals` -> `SignalController::index`

### `app/Core/Request.php`

Feladata:

- kiolvassa a HTTP metódust,
- szétválasztja az URL path és query részeit,
- beolvassa a body-t,
- JSON body esetén parse-olja azt.

### Input

- `$_SERVER`
- `$_GET`
- `$_POST`
- `php://input`

### Output

Egy `Request` objektum:

- `method: string`
- `path: string`
- `query: array`
- `body: array`
- `rawBody: string`

### `app/Core/Router.php`

Feladata:

- a `Request` alapján kikeresi a route handler-t,
- a konténerből lekéri a controller példányt,
- meghívja a megfelelő controller metódust.

### Input

- `Request`
- route tábla
- `Container`

### Output

- `Response`

## 4. A controller elindítja a signal számítást

Itt két út van.

## Út A: szerver oldali első HTML render

### `HomeController::index(Request $request): Response`

### Felelősség

- beállítások előkészítése a nézetnek,
- signalok lekérése,
- hibatűrés,
- HTML oldal visszaadása.

### Input

- `Request $request`

### Közvetlenül felhasznált config értékek

- `pairs.refresh_seconds`
- `pairs.analysis_timeframes`
- `pairs.decision_timeframe`
- `pairs.pairs`
- `app.name`

### Belső lépések

1. meghatározza a refresh időt,
2. kigyűjti az aktív elemzési timeframe-eket,
3. meghatározza a decision timeframe-et,
4. meghívja a `MarketAnalyzer::analyzeConfiguredPairs()` metódust,
5. elkapja az esetleges kivételt,
6. átadja az adatot a `home/index` nézetnek.

### Output

`Response::html(...)`

A view-nek átadott fő adatmezők:

- `appName`
- `pairs`
- `analysis`
- `error`
- `refreshSeconds`
- `analysisTimeframes`
- `decisionTimeframe`
- `updatedAt`

## Út B: API alapú frissítés

### `SignalController::index(Request $request): Response`

### Felelősség

- API végpontként ugyanazt a signal számítást futtatja,
- JSON payloadként adja vissza.

### Input

- `Request $request`

Megjegyzés: a metódus jelenleg a `Request` objektumot nem használja aktívan, de a signature része.

### Belső lépések

1. meghívja a `MarketAnalyzer::analyzeConfiguredPairs()` metódust,
2. generál egy `generated_at` timestampet,
3. JSON választ ad.

### Sikeres output

```json
{
  "status": "ok",
  "generated_at": "2026-04-03T10:00:00+02:00",
  "signals": [
    {}
  ]
}
```

### Hibás output

```json
{
  "status": "error",
  "message": "..."
}
```

## 5. A `MarketAnalyzer` összegyűjti az adatot minden konfigurált párhoz

### Osztály

`app/Services/Market/MarketAnalyzer.php`

### Fő metódus

`analyzeConfiguredPairs(): array`

### Felelősség

Ez az orchestration réteg.
Nem ő számolja az indikátorokat és nem ő hozza a végső kereskedési döntést, hanem:

- kiolvassa a konfigurált párokat,
- kiolvassa az elemzési timeframe-eket,
- minden párhoz lekéri a candles adatokat,
- átadja ezeket a `SignalEngine`-nek,
- hibakezelő fallback payloadot gyárt, ha egy pár feldolgozása elbukik.

### Input források

- `pairs.pairs`
- `pairs.analysis_timeframes`
- `pairs.default_limit`
- `pairs.decision_timeframe`

### Effektív input

A metódusnak nincs explicit paramétere, mert mindent a `Config`-ból olvas.

### Belső lépések részletesen

1. betölti a párok listáját
2. betölti az elemzési timeframe listát
3. betölti a candle limitet
4. betölti a decision timeframe-et
5. inicializál egy üres `signals` tömböt
6. minden szimbólumra:
   - létrehoz egy `candlesByTimeframe` tömböt
   - minden timeframe-re meghívja a `BinanceApiClient::getKlines()` metódust
   - átadja a kapott adatot a `SignalEngine::analyze()` metódusnak
   - eltárolja a kész signal payloadot
7. ha bármelyik timeframe vagy az engine hibát dob:
   - az adott párhoz hibatűrő `NO_TRADE` payload készül

### Input shape a `SignalEngine` felé

Egyetlen párhoz az engine ezt kapja:

- `symbol: string`
- `decisionTimeframe: string`
- `candlesByTimeframe: array<string, array<int, candle>>`

ahol egy candle elem így néz ki:

```php
[
    'open_time' => 1712100000000,
    'open' => 66800.12,
    'high' => 67020.54,
    'low' => 66750.01,
    'close' => 66946.24,
    'volume' => 132.45,
    'close_time' => 1712100899999,
]
```

### Output

Egy tömb, amelyben minden elem egy kész signal egy konfigurált párra.

```php
[
    [
        'symbol' => 'BTCUSDT',
        'interval' => '15m',
        'market_regime' => 'UPTREND',
        'action' => 'LONG',
        ...
    ],
    ...
]
```

### Hibafallback output

Ha az adott pár elemzése megszakad, a visszatérő payload többek között:

- `action: NO_TRADE`
- `market_regime: ERROR`
- `confidence: 0`
- `risk.allowed: false`
- `risk.flags: ['A pár elemzése sikertelen']`
- `reasons: [kivétel szövege]`
- `error: kivétel szövege`

Ez azért fontos, mert a UI így párszinten is tud hibát jelezni teljes oldalösszeomlás nélkül.

## 6. A `BinanceApiClient` lekéri a nyers piaci adatot

### Osztály

`app/Services/Binance/BinanceApiClient.php`

### A signal blokknál használt metódus

`getKlines(string $symbol, string $interval = '1m', int $limit = 200): array`

### Input

- `symbol`
- `interval`
- `limit`

### Felelősség

- HTTP GET hívást küld a Binance nyilvános REST API felé,
- lekéri a gyertyákat,
- a Binance tömbös válaszát belső asszociatív tömbre alakítja.

### Meghívott endpoint

- `GET https://api.binance.com/api/v3/klines`

Query paraméterek:

- `symbol`
- `interval`
- `limit`

### Kimeneti shape

Minden candle egy normalizált tömb:

- `open_time: int`
- `open: float`
- `high: float`
- `low: float`
- `close: float`
- `volume: float`
- `close_time: int`

### Hibakezelés

Hibát dob, ha:

- a HTTP kérés nem sikerül,
- a válasz nem valid JSON,
- a Binance HTTP 4xx vagy 5xx választ ad.

Ez a kivétel a `MarketAnalyzer` szintjén kerül elkapásra páronként.

## 7. A `SignalEngine` timeframe-enként elemez, majd összesít

### Osztály

`app/Services/Strategy/SignalEngine.php`

### Fő metódus

`analyze(string $symbol, string $decisionTimeframe, array $candlesByTimeframe): array`

Ez a signal generátor magja.

## 7.1. A metódus inputja

### Paraméterek

#### `$symbol`

Típus:

- `string`

Példa:

- `BTCUSDT`

#### `$decisionTimeframe`

Típus:

- `string`

Példa:

- `15m`

Ez az az idősáv, amelynek a metrikái és bias-a lesznek a végső döntés referenciaalapjai.

#### `$candlesByTimeframe`

Típus:

- `array<string, array<int, array>>`

Példa:

```php
[
    '15m' => [candle, candle, ...],
    '1h' => [candle, candle, ...],
    '2h' => [candle, candle, ...],
]
```

## 7.2. A metódus első fázisa: timeframe-ek külön elemzése

Az engine végigiterál a `candlesByTimeframe` tömbön, és minden timeframe-re meghívja:

`analyzeTimeframe(string $timeframe, array $candles): array`

Ennek eredménye:

```php
$timeframeAnalysis = [
    '15m' => [...],
    '1h' => [...],
    '2h' => [...],
];
```

## 7.3. A timeframe szintű elemzés részletesen

### Metódus

`analyzeTimeframe(string $timeframe, array $candles): array`

### Felelősség

- kiszámolja az adott timeframe indikátorait,
- pontozza a bullish és bearish jeleket,
- bias-t állapít meg,
- átadja a releváns kontextust a risk filternek,
- visszaad egy teljes timeframe payloadot.

### Input

- `timeframe: string`
- `candles: array<int, candle>`

### Minimális adatigény

Ha a candle elemszám kisebb mint 2, a metódus azonnal egy hibás, semleges payloadot ad vissza:

- `price = 0`
- `bias = NEUTRAL`
- `bull_score = 0`
- `bear_score = 0`
- `risk.allowed = false`
- `risk.flags = ['Not enough candle data']`

### Feldolgozás részletei

#### 1. Nyers idősoros adatok kinyerése

Az `IndicatorService` segítségével:

- `closes(array $candles): array`
- `volumes(array $candles): array`

Kinyert adatok:

- záróár sorozat
- volumen sorozat
- utolsó gyertya
- utolsó záróár
- utolsó gyertya zárási ideje
- gyertya életkora másodpercben

#### 2. Indikátorok számítása

A timeframe elemzés az alábbiakat számolja:

- `ema20`
- `ema50`
- `ema200`
- `rsi14`
- `macd['histogram']`
- `atr_percent`
- `avgVolume`
- `volumeRatio`
- `recentChange`
- `structureMove`

#### 3. Score építése

A `config/strategy.php` `weights` kulcsából dolgozik:

- `trend`
- `momentum`
- `macd`
- `volume`
- `structure`

Az egyes szabályok:

##### Trend

Bullish trend pontot kap, ha:

- `ema20 > ema50`
- `ema50 > ema200`
- `lastPrice > ema20`

Bearish trend pontot kap, ha:

- `ema20 < ema50`
- `ema50 < ema200`
- `lastPrice < ema20`

##### Momentum RSI alapján

Bull pont:

- `rsi >= 55`
- `rsi <= 68`

Bear pont:

- `rsi <= 45`
- `rsi >= 32`

##### MACD

Bull pont:

- histogram > 0

Bear pont:

- histogram < 0

##### Volume megerősítés

Feltétel:

- `volumeRatio >= 1.05`

Ha az utolsó mozgás pozitív:

- bull pont

Ha az utolsó mozgás negatív:

- bear pont

##### Structure mozgás

Bull pont:

- `structureMove > 0.6`

Bear pont:

- `structureMove < -0.6`

### A fontos köztes számítások pontos jelentése

#### `volumeRatio`

Képlet:

- utolsó gyertya volumene / utolsó 20 gyertya átlagvolumene

#### `recentChange`

Képlet:

- az utolsó előtti záró és az utolsó záró közötti százalékos változás

#### `structureMove`

Képlet:

- az utolsó 10 záróár első és utolsó elemének százalékos változása

#### `candleAgeSeconds`

Képlet:

- jelenlegi UNIX idő mínusz az utolsó gyertya `close_time` értéke másodpercesítve

### 4. Risk filter hívás

Az engine átadja a `RiskFilterService::evaluate(array $context): array` metódusnak:

```php
[
    'atr_percent' => ...,
    'volume_ratio' => ...,
    'last_candle_change' => ...,
    'candle_age_seconds' => ...,
]
```

### 5. Bias meghatározása timeframe szinten

Metódus:

`determineBias(int $bullScore, int $bearScore): string`

Szabály:

- ha a score gap `< 15`, akkor `NEUTRAL`
- különben a nagyobb score iránya nyer:
  - `BULLISH`
  - `BEARISH`

### 6. Timeframe output

Az `analyzeTimeframe()` kimenete:

- `timeframe`
- `price`
- `bias`
- `bull_score`
- `bear_score`
- `metrics`
- `risk`
- `reasons`

Pontosabban a `metrics` blokk:

- `ema20`
- `ema50`
- `ema200`
- `rsi14`
- `macd_histogram`
- `atr_percent`
- `volume_ratio`
- `last_candle_change`
- `structure_move`
- `candle_age_seconds`

## 7.4. A `RiskFilterService` pontos működése

### Osztály

`app/Services/Strategy/RiskFilterService.php`

### Fő metódus

`evaluate(array $context): array`

### Input

```php
[
    'atr_percent' => ?float,
    'volume_ratio' => ?float,
    'last_candle_change' => ?float,
    'candle_age_seconds' => ?int,
]
```

### Felhasznált config kulcsok

- `max_atr_percent`
- `min_volume_ratio`
- `max_spike_percent`
- `cooldown_seconds`

### Szabályok

Flag kerül a listába, ha:

- `atr_percent > max_atr_percent` -> `ATR too high`
- `volume_ratio < min_volume_ratio` -> `Volume below threshold`
- `abs(last_candle_change) > max_spike_percent` -> `Last candle spike too large`
- `candle_age_seconds < cooldown_seconds` -> `Cooldown active`

### Output

```php
[
    'allowed' => bool,
    'flags' => ['...', '...']
]
```

Az `allowed` csak akkor igaz, ha egyetlen flag sem keletkezett.

## 7.5. Több timeframe összefésülése

Miután az engine minden timeframe-et kielemzett, elindul az összesítés.

### A döntési timeframe kiválasztása

Az engine megpróbálja a `decisionTimeframe` kulccsal kivenni a releváns timeframe payloadot:

```php
$decision = $timeframeAnalysis[$decisionTimeframe] ?? reset($timeframeAnalysis);
```

Ez lesz a fő referencia a végső:

- árhoz,
- metrikákhoz,
- bias-hoz,
- risk alaphoz.

### Trigger és confirmation timeframe

Az engine külön kiválaszt még két szerepkört:

- trigger timeframe
- confirmation timeframe

Forrása a `pairs` config:

- `trigger_timeframe`
- `confirmation_timeframe`

Ezeket a `firstConfiguredTimeframe()` segédmetódus választja ki a már kiszámolt timeframe payloadok közül.

### Súlyozott score összevonás

Az engine a `strategy.timeframe_weights` alapján súlyoz.

Minden timeframe-re:

- `weightedBull += bull_score * weight`
- `weightedBear += bear_score * weight`
- `weightTotal += weight`

A végén:

- `bullScore = round(weightedBull / weightTotal)`
- `bearScore = round(weightedBear / weightTotal)`

### Összefésült reasons

Minden timeframe `reasons` listája összefűződik, majd a végén egyedivé válik.

### Timeframe payload a UI felé

Az engine nem ad vissza minden nyers adatot minden timeframe-re, hanem egy kisebb összefoglalót:

```php
[
    '15m' => [
        'bias' => 'BULLISH',
        'bull_score' => 65,
        'bear_score' => 20,
        'metrics' => [...]
    ]
]
```

## 7.6. Konfliktusok és risk penalty

Az engine a döntési timeframe `risk.flags` listájából indul.

Utána további flag-eket is hozzátehet:

- `Higher timeframe conflict`
- `Trigger timeframe conflict`

Ezek akkor keletkeznek, ha a trigger vagy a confirmation bias ellentétes a döntési bias-szal.

### Risk penalty számítás

```php
$riskPenalty = count($riskFlags) * 12;
if (!$decision['risk']['allowed']) {
    $riskPenalty += 12;
}
```

Ez fontos, mert a confidence nem a nyers bull/bear score, hanem a kockázatokkal csökkentett érték.

## 7.7. Confidence számítás

### Képlet

```php
$rawConfidence = max($bullScore, $bearScore) - $riskPenalty;
$confidence = max(0, min(100, $rawConfidence));
```

### Jelentés

- a domináns irány ereje a kiindulás,
- ebből levonódik a risk penalty,
- az eredmény 0 és 100 közé van szorítva.

## 7.8. Market regime meghatározása

### Metódus

`determineMarketRegime(array $decision, ?array $confirmation, int $bullScore, int $bearScore, array $riskFlags): string`

### Kimenetek

- `COOLDOWN`
- `HIGH_VOLATILITY`
- `WEAK_STRUCTURE`
- `UPTREND`
- `DOWNTREND`
- `RANGE`

### Döntési sorrend

1. ha `Cooldown active` van -> `COOLDOWN`
2. ha `atr_percent > max_atr_percent` -> `HIGH_VOLATILITY`
3. ha higher timeframe conflict van -> `WEAK_STRUCTURE`
4. ha decision bullish és confirmation nem bearish -> `UPTREND`
5. ha decision bearish és confirmation nem bullish -> `DOWNTREND`
6. ha `abs(bullScore - bearScore) < 12` -> `RANGE`
7. különben -> `WEAK_STRUCTURE`

Ez nem közvetlenül a kereskedési action, hanem a piaci állapot címkéje.

## 7.9. A végső action meghatározása

### Metódus

`determineAction(array $decision, ?array $trigger, ?array $confirmation, int $bullScore, int $bearScore, int $scoreGap, int $confidence, array $riskFlags): string`

### Lehetséges kimenetek

- `LONG`
- `SHORT`
- `SPOT_BUY`
- `SPOT_SELL`
- `NO_TRADE`

### Felhasznált küszöbök

- `min_confidence`
- `spot_confidence`

### Döntési logika sorrendben

#### 1. Azonnali tiltások

Ha igaz bármelyik:

- `Cooldown active`
- `decision bias == NEUTRAL`
- `scoreGap < 12`
- `count(riskFlags) >= 2`

akkor:

- `NO_TRADE`

#### 2. Bullish eset

Bullish akkor lehet, ha:

- a decision bias `BULLISH`

##### `LONG`

Feltételek:

- `confidence >= min_confidence`
- trigger nem bearish
- confirmation létezik
- confirmation bullish

##### `SPOT_BUY`

Feltételek:

- `confidence >= spot_confidence`
- confirmation nem bearish

#### 3. Bearish eset

Bearish akkor lehet, ha:

- a decision bias `BEARISH`

##### `SHORT`

Feltételek:

- `confidence >= min_confidence`
- trigger nem bullish
- confirmation létezik
- confirmation bearish

##### `SPOT_SELL`

Feltételek:

- `confidence >= spot_confidence`
- confirmation nem bullish

#### 4. Ha egyik feltétel sem teljesül

- `NO_TRADE`

### Fontos értelmezés

Ez a rendszer különbséget tesz:

- magasabb meggyőződésű futures jellegű irány (`LONG` / `SHORT`)
- alacsonyabb küszöbű spot irány (`SPOT_BUY` / `SPOT_SELL`)
- tiltott vagy bizonytalan eset (`NO_TRADE`)

## 7.10. A `SignalEngine::analyze()` végső outputja

Az engine végül egy teljes signal payloadot ad vissza:

```php
[
    'symbol' => 'BTCUSDT',
    'interval' => '15m',
    'market_regime' => 'UPTREND',
    'action' => 'LONG',
    'direction' => 'LONG',
    'price' => 66946.24,
    'confidence' => 76,
    'bull_score' => 78,
    'bear_score' => 24,
    'long_score' => 78,
    'short_score' => 24,
    'risk_penalty' => 12,
    'risk' => [
        'allowed' => false,
        'flags' => ['Trigger timeframe conflict']
    ],
    'metrics' => [
        'ema20' => ...,
        'ema50' => ...,
        'ema200' => ...,
        'rsi14' => ...,
        'macd_histogram' => ...,
        'atr_percent' => ...,
        'volume_ratio' => ...,
        'last_candle_change' => ...,
        'structure_move' => ...,
        'candle_age_seconds' => ...
    ],
    'timeframes' => [
        '15m' => [...],
        '1h' => [...],
        '2h' => [...]
    ],
    'reasons' => [
        '15m rising trend',
        '1h MACD positive'
    ]
]
```

### Fontos mezők értelmezése

#### `action`

A végső, UI által is megjelenített döntés.

#### `direction`

Jelenleg ugyanaz, mint az `action`.

#### `metrics`

A decision timeframe metrikái.

#### `timeframes`

Timeframe szintű összefoglaló payloadok.

#### `reasons`

Összefűzött indoklások az időtávokból.

## 8. A signal hogyan jut el a UI-ig

## 8.1. Szerver oldali HTML render

### `app/Views/home/index.php`

Az `analysis` tömbön iterál:

```php
foreach ($analysis as $row)
```

Minden elemhez:

- akció badge-et választ,
- kiírja a szimbólumot,
- kiírja a decision timeframe-et,
- megjeleníti az árat,
- market regime-et,
- confidence-et,
- bull / bear / risk pontokat,
- indikátorokat,
- timeframe bias-okat,
- reasons listát,
- risk flag-eket,
- és esetleges pair-level hibát.

### UI mapping

#### Badge színezés

- `LONG`, `SPOT_BUY` -> long stílus
- `SHORT`, `SPOT_SELL` -> short stílus
- minden más -> neutral

#### A felhasználó által ténylegesen látott fő mezők

- `symbol`
- `interval`
- `action`
- `price`
- `market_regime`
- `confidence`
- `bull_score`
- `bear_score`
- `risk_penalty`
- `metrics.rsi14`
- `metrics.atr_percent`
- `metrics.volume_ratio`
- `timeframes[*].bias`
- `reasons`
- `risk.flags`

## 8.2. Kliens oldali API frissítés

### `public/assets/js/dashboard.js`

#### A fontos függvény

`refreshSignals()`

### Mit csinál?

1. meghívja a `GET /api/signals` végpontot
2. JSON payloadot vár
3. ellenőrzi, hogy a `signals` tömb valóban tömb-e
4. újrarendereli a signal gridet a `renderSignals()` segítségével
5. frissíti a last updated mezőt

### Render lánc

- `refreshSignals()`
- `renderSignals(signals)`
- `renderCard(row)`

### `renderCard(row)` inputja

Pontosan ugyanaz a signal payload, amit a backend a `SignalEngine` végén ad vissza.

### `renderCard(row)` outputja

Egy HTML string egy kártyáról.

Ez azért fontos, mert a UI oldali signal valójában nem újraszámolt adat, csak a backend payload vizuális leképezése.

## 9. Input-output bontás metódusonként

## `HomeController::index(Request $request): Response`

### Input

- `Request`

### Output

- `Response` HTML-lel

### Mellékhatás

- meghívja a teljes signal láncot

## `SignalController::index(Request $request): Response`

### Input

- `Request`

### Output

- `Response` JSON-nal

### Mellékhatás

- meghívja a teljes signal láncot

## `MarketAnalyzer::analyzeConfiguredPairs(): array`

### Input

- implicit config

### Output

- `array<int, signalPayload>`

### Mellékhatás

- külső Binance API hívások

## `BinanceApiClient::getKlines(string $symbol, string $interval, int $limit): array`

### Input

- szimbólum
- idősáv
- limit

### Output

- `array<int, candle>`

### Mellékhatás

- HTTP kérés Binance felé

## `SignalEngine::analyze(string $symbol, string $decisionTimeframe, array $candlesByTimeframe): array`

### Input

- szimbólum
- decision timeframe
- candles timeframe-enként

### Output

- kész signal payload

## `SignalEngine::analyzeTimeframe(string $timeframe, array $candles): array`

### Input

- timeframe
- candle lista

### Output

- timeframe elemzés

## `RiskFilterService::evaluate(array $context): array`

### Input

- ATR
- volume ratio
- utolsó gyertya mozgása
- candle age

### Output

- `allowed`
- `flags`

## `IndicatorService::ema(array $values, int $period): ?float`

### Input

- számsor
- periódus

### Output

- EMA vagy `null`

## `IndicatorService::rsi(array $values, int $period = 14): ?float`

### Input

- záróárak
- periódus

### Output

- RSI vagy `null`

## `IndicatorService::macd(array $values): array`

### Input

- záróárak

### Output

```php
[
    'macd' => ?float,
    'signal' => ?float,
    'histogram' => ?float,
]
```

## `IndicatorService::atrPercent(array $candles, int $period = 14): ?float`

### Input

- candle lista
- periódus

### Output

- ATR százalék vagy `null`

## 10. Konfigurációból UI signalig: teljes példafolyamat

Vegyünk egy konkrét konfigurációs példát.

### Kiinduló config

#### `config/pairs.php`

```php
'pairs' => ['BTCUSDT', 'ETHUSDT', 'BNBUSDT'],
'analysis_timeframes' => ['15m', '1h', '2h'],
'decision_timeframe' => '15m',
'default_limit' => 200,
```

#### `config/strategy.php`

```php
'min_confidence' => 70,
'spot_confidence' => 58,
'timeframe_weights' => [
    '1m' => 0.6,
    '5m' => 1.0,
    '15m' => 0.9,
],
```

### Folyamat BTCUSDT-re

1. a `MarketAnalyzer` kiválasztja a `BTCUSDT` párt
2. lekéri a `15m`, `1h`, `2h` gyertyákat
3. a `SignalEngine` mindhárom idősávon kiszámolja:
   - EMA-kat
   - RSI-t
   - MACD-t
   - ATR-t
   - volume arányt
   - structure mozgást
4. minden timeframe kap:
   - bull score-t
   - bear score-t
   - bias-t
   - reasons listát
   - risk állapotot
5. a `15m` timeframe lesz a decision timeframe
6. az engine a timeframe eredményeket összesúlyozza
7. kiszámolja a confidence-et
8. megállapítja a market regime-et
9. megállapítja az `action` mezőt
10. visszaad egy kész signal payloadot
11. a `HomeController` vagy a `SignalController` ezt továbbadja
12. a view vagy a frontend JS ezt kártyává rendereli

### Így lesz a konfigurált párból UI signal

Röviden:

`config pair + timeframe list`
-> `Binance candles`
-> `indikátorok`
-> `timeframe score`
-> `bias`
-> `risk flags`
-> `összesített confidence`
-> `action`
-> `controller output`
-> `HTML/JSON`
-> `dashboard card`

## 11. Fontos megfigyelések és szakmai következtetések

### 1. A `SignalEngine` a valódi döntési központ

A `MarketAnalyzer` csak koordinál, a döntés itt születik.

### 2. A decision timeframe elsődleges

A végső price és metrics a decision timeframe-ből jönnek, még akkor is, ha több timeframe vesz részt az elemzésben.

### 3. A több timeframe nem egyenrangú

Van:

- decision timeframe
- trigger timeframe
- confirmation timeframe
- súlyozott összesítés

Ez egy tudatos, hierarchikus modell.

### 4. A confidence nem tiszta technikai erő

A kockázati szűrés erősen beleszól, tehát a confidence már egy risk-adjusted érték.

### 5. A UI nem dönt, csak megjelenít

A frontend semmit nem számol újra a signalból, csak rendereli a backend payloadot.

### 6. A rendszer páronként hibatűrő

Ha egy pár elhasal, a többi tovább működik, és a UI egy hibás `NO_TRADE` payloadot kap az adott sorhoz.

## 12. Melyik fájlt nézd, ha...

### ...a signal szabályokat akarod módosítani

- `app/Services/Strategy/SignalEngine.php`

### ...az indikátorok számítását akarod módosítani

- `app/Services/Strategy/IndicatorService.php`

### ...a risk tiltásokat akarod módosítani

- `app/Services/Strategy/RiskFilterService.php`
- `config/strategy.php`

### ...a figyelt párokat vagy timeframe-eket akarod módosítani

- `config/pairs.php`

### ...a Binance adatforrást akarod cserélni vagy bővíteni

- `app/Services/Binance/BinanceApiClient.php`

### ...a signal megjelenést akarod módosítani

- `app/Views/home/index.php`
- `public/assets/js/dashboard.js`

## 13. Összefoglaló egy mondatban

A signal generátor úgy működik, hogy a konfigurált párokhoz több idősávos Binance gyertyákat gyűjt, timeframe-enként technikai score-okat és risk állapotot számol, ezeket egy döntési logikában összefésüli, majd a kapott `action`-t és metaadatokat HTML-ben és API-n keresztül a dashboardra rendereli.
