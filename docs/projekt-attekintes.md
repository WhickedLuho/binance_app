# Binance App projektáttekintés

## Cél

Ez a projekt egy egyszerű, saját PHP MVC alapokra épített Binance figyelő és szimulációs dashboard.
A rendszer jelenleg 3 fő, egymástól logikailag elkülönülő blokkra bontható:

1. **Elemzés / technikai döntéstámogatás**
2. **Predikció / részletes becslés egy kiválasztott párra**
3. **Paper trading / szimulált pozíciókezelés**

A három blokk ugyanazon a felületen jelenik meg, de külön service-rétegekkel és külön API végpontokkal működik.

## Magas szintű architektúra

### Belépési pont

- A webes belépési pont: `public/index.php`
- Az alkalmazás bootstrapje: `app/Bootstrap/App.php`
- A route-ok két fájlból töltődnek be:
  - `routes/web.php`
  - `routes/api.php`

### Saját mini framework réteg

Az `app/Core` mappa biztosítja az alap működést:

- `Config.php`: betölti a `config/*.php` fájlokat és pontozott kulcsokkal olvassa a beállításokat
- `Container.php`: egyszerű dependency injection container
- `Request.php`: HTTP kérés feldolgozása, query/body parse
- `Response.php`: HTML és JSON válaszok küldése
- `Router.php`: útvonal-kezelés és controller dispatch
- `View.php`: PHP view renderelés

Ez a réteg nem Laravel vagy Symfony, hanem egy könnyű, saját megoldás.

## Fő könyvtárak röviden

- `app/Bootstrap`: indulási és service-összekötési logika
- `app/Controllers`: webes és API kontrollerek
- `app/Core`: mini framework
- `app/Services`: üzleti logika
- `app/Views`: HTML nézetek
- `config`: alkalmazás- és stratégia-beállítások
- `public/assets`: frontend CSS és JavaScript
- `routes`: route definíciók
- `storage/cache`: lokális állapotfájlok, jelenleg itt van a paper trade tárolás
- `docker`: nginx és PHP konténerkonfiguráció

## A három fő blokk

## 1. Elemzés és technikai blokk

### Mi a feladata?

Ez a blokk a konfigurációban előre megadott kereskedési párokat elemzi a beállított idősávokon, majd minden párra előállít egy döntéstámogató jelet.

Példa eredmények:

- `LONG`
- `SHORT`
- `SPOT_BUY`
- `SPOT_SELL`
- `NO_TRADE`

### Fő fájlok

- `config/pairs.php`
- `config/strategy.php`
- `app/Services/Market/MarketAnalyzer.php`
- `app/Services/Strategy/SignalEngine.php`
- `app/Services/Strategy/IndicatorService.php`
- `app/Services/Strategy/RiskFilterService.php`
- `app/Controllers/HomeController.php`
- `app/Controllers/Api/SignalController.php`
- `app/Views/home/index.php`
- `public/assets/js/dashboard.js`

### Bejövő konfiguráció

#### `config/pairs.php`

Innen jönnek az elemzéshez kapcsolódó fő paraméterek:

- figyelt párok
- alapértelmezett intervallum
- döntési idősáv
- elemzési idősávok
- predikciós idősávok
- frissítési időköz
- lekért candle darabszám

A jelenlegi fontos beállítások:

- párok: `BTCUSDT`, `ETHUSDT`, `BNBUSDT`
- elemzési idősávok: `15m`, `1h`, `2h`
- döntési idősáv: `15m`
- frissítés: `5` másodperc

#### `config/strategy.php`

Itt vannak a stratégia küszöbei és súlyai:

- minimális confidence
- spot confidence küszöb
- minimális volume ratio
- maximális ATR százalék
- maximális spike százalék
- cooldown másodperc
- timeframe súlyok
- indikátor súlyok

### Elemzési folyamat lépésről lépésre

1. A dashboard betöltésekor a `HomeController::index()` lefut.
2. A controller meghívja a `MarketAnalyzer::analyzeConfiguredPairs()` metódust.
3. A `MarketAnalyzer` végigmegy az összes konfigurált páron.
4. Minden párhoz minden konfigurált elemzési idősávra Binance gyertyákat kér le a `BinanceApiClient` segítségével.
5. Az összegyűjtött gyertyákat átadja a `SignalEngine`-nek.
6. A `SignalEngine` idősávonként technikai elemzést készít.
7. A timeframe eredményekből összesített bull/bear score és confidence számolódik.
8. A `RiskFilterService` megvizsgálja, hogy van-e blokkoló kockázat.
9. A végső akció meghatározódik: `LONG`, `SHORT`, `SPOT_BUY`, `SPOT_SELL` vagy `NO_TRADE`.
10. Az eredmény megjelenik a dashboard kártyákon, illetve JSON formában is elérhető.

### Milyen indikátorokat használ?

Az `IndicatorService` számolja többek között:

- EMA 20
- EMA 50
- EMA 200
- RSI 14
- MACD és histogram
- ATR %
- százalékos ármozgás
- átlagos volume

### Hogyan épül fel a jel?

Az `app/Services/Strategy/SignalEngine.php` a fő döntési motor.

#### Timeframe elemzés

Minden idősávra külön számolja:

- trend állapot
- momentum
- MACD irány
- volume támogatás
- struktúra mozgás
- risk flag-ek

Ezekből két pontszám keletkezik:

- `bull_score`
- `bear_score`

#### Bias meghatározás

Az adott timeframe bias lehet:

- `BULLISH`
- `BEARISH`
- `NEUTRAL`

#### Több idősáv összefésülése

Az engine a különböző timeframe-eket súlyozza, majd összevonja az eredményt.
Ezután számolja:

- összesített bull score
- összesített bear score
- confidence
- market regime
- végső action

### Kockázati szűrés

Az `RiskFilterService` jelenleg ezeket figyeli:

- túl magas ATR
- túl alacsony volume
- túl nagy utolsó gyertya spike
- aktív cooldown

Ha túl sok kockázati flag gyűlik össze, a rendszer `NO_TRADE` döntést ad.

### Kimenet

Az elemzési blokk kimenete egy páronkénti tömb, amely tartalmazza például:

- szimbólum
- döntési intervallum
- aktuális ár
- market regime
- confidence
- bull/bear score
- risk flag-ek
- indikátor értékek
- timeframe bontás
- indoklások

### Elérési utak

- felület: `GET /`
- nyers API: `GET /api/signals`

### Frontend oldali működés

A `public/assets/js/dashboard.js` időzítve hívja a `GET /api/signals` végpontot.
Alapértelmezés szerint 5 másodpercenként frissíti a signal kártyákat.

## 2. Predikciós blokk

### Mi a feladata?

Ez a blokk egy kiválasztott párhoz részletesebb, on-demand becslést készít.
Nem minden párral fut automatikusan külön nézetként, hanem a felhasználó egy adott kártyán a `Prediction` gombra kattint.

### Fő fájlok

- `app/Controllers/Api/PredictionController.php`
- `app/Services/Prediction/PairPredictionService.php`
- `app/Services/Binance/BinanceApiClient.php`
- `app/Services/Strategy/IndicatorService.php`
- `app/Views/home/index.php`
- `public/assets/js/dashboard.js`
- `config/pairs.php`

### Folyamat

1. A felhasználó kiválaszt egy párt a dashboardon.
2. A frontend meghívja: `GET /api/prediction?symbol=...`
3. A `PredictionController` ellenőrzi, hogy a szimbólum benne van-e a támogatott párok listájában.
4. A `PairPredictionService::predict()` lefut.
5. A service a predikcióhoz konfigurált timeframe-eken kér le gyertyákat.
6. Minden timeframe-re külön technikai képet készít.
7. Ezekből összesített irány-score, bias, zónák és szcenáriók épülnek fel.
8. A frontend megjeleníti a prediction panelen az eredményt.

### Használt idősávok

A predikció külön konfigurációból dolgozik:

- `15m`
- `1h`
- `4h`

Ez fontos, mert a predikciós logika nem ugyanazt az idősáv készletet használja, mint az alap elemző blokk.

### Milyen adatokat állít elő?

#### Timeframe szintű elemzés

Minden idősávra kiszámolja:

- aktuális ár
- bias
- EMA20
- EMA50
- RSI14
- ATR %
- legközelebbi support
- legközelebbi resistance
- momentum %
- pivot support szintek
- pivot resistance szintek

#### Összesített bias

A teljes predikció bias lehet:

- `BULLISH`
- `BEARISH`
- `RANGE`

#### Zónák

A rendszer support és resistance zónákat épít:

- több timeframe szintjeit összegyűjti
- klaszterezést végez
- kiválasztja a legrelevánsabb support vagy resistance zónát

Ha nincs elég jó szint, fallback zónát számol az aktuális ár köré.

#### Szcenáriók

Három kimeneti szcenárió készül:

- `short`
- `long`
- `neutral`

Ezek tipikusan tartalmaznak:

- belépési elképzelés
- target zone
- suggested take profit
- invalidation szint
- reward %
- risk %
- rövid szöveges összefoglaló

### Mire jó ez a blokk?

Ez a rész nem direkt order végrehajtásra szolgál, hanem részletesebb piaci értelmezést ad egyetlen kiválasztott instrumentumra.
Gyakorlatban ez egy mélyebb nézet az alap signal kártya mögött.

### Elérési út

- `GET /api/prediction?symbol=BTCUSDT`

### Frontend kapcsolódás

A prediction panel elemei a `dashboard.js` fájlban töltődnek és frissülnek.
A blokk a következő UI részeket tölti:

- market bias
- confidence
- current price
- generated timestamp
- support zone
- resistance zone
- long / short / neutral scenario
- timeframe részletek

## 3. Paper trading blokk

### Mi a feladata?

Ez a blokk szimulált pozíciók nyitását, frissítését, zárását és eredménykövetését végzi.
Valós order nem megy ki a Binance felé, csak a piaci árak jönnek onnan.

### Fő fájlok

- `app/Controllers/Api/PaperTradeController.php`
- `app/Services/PaperTrading/PaperTradeService.php`
- `app/Services/PaperTrading/PaperTradeRepository.php`
- `app/Services/Binance/BinanceApiClient.php`
- `config/paper.php`
- `storage/cache/paper_trades.json`
- `app/Views/home/index.php`
- `public/assets/js/dashboard.js`

### Tárolási modell

A paper trading állapot fájlban tárolódik:

- `storage/cache/paper_trades.json`

A repository kezeli:

- állapot betöltés
- alapértelmezett üres állapot létrehozás
- fájlírás lockkal
- history és meta normalizálás

### Állapot szerkezete

A jelenlegi tárolási séma:

- `positions`: nyitott pozíciók
- `history`: lezárt pozíciók
- `meta.next_id`: következő azonosító

### Fő beállítások

A `config/paper.php` adja meg például:

- kezdő egyenleg: `10000`
- maintenance margin rate
- maximális leverage: `20`
- maximális nyitott pozíciók száma: `12`
- default margin type
- default leverage
- history limit
- storage file

### Működési folyamat

#### 1. Áttekintés betöltése

A frontend a `GET /api/paper-trades` végpontot hívja.
Erre a `PaperTradeService::overview()` válaszol.

Mit csinál?

- beolvassa a tárolt állapotot
- az összes nyitott pozícióhoz lekéri az aktuális piaci árat
- kiszámolja a lebegő PnL-t
- kiszámolja az equity-t és available balance-t
- összerakja az account summary-t, open positions listát és history-t

#### 2. Pozíció nyitása

Végpont:

- `POST /api/paper-trades`

A `PaperTradeService::openPosition()`:

- ellenőrzi a limitet
- validálja a payloadot
- validálja a kereskedési párt
- validálja a trade típust és oldalt
- kiszámolja a leverage-et
- kiszámolja a notional méretet
- kiszámolja a quantity-t
- ellenőrzi a balance fedezetet
- ellenőrzi a stop loss / take profit logikát
- elmenti a pozíciót

Támogatott módok:

- `SPOT` + `LONG`
- `FUTURES` + `LONG`
- `FUTURES` + `SHORT`

Fontos: spot short jelenleg nincs támogatva.

#### 3. Pozíció frissítése

Végpont:

- `POST /api/paper-trades/update`

Ez módosítja:

- stop loss
- take profit
- notes

#### 4. Pozíció zárása

Végpont:

- `POST /api/paper-trades/close`

Ez:

- megkeresi a nyitott pozíciót
- meghatározza a záróárat
- kiszámolja a realizált eredményt
- átteszi az elemet a history tömbbe
- frissíti az account állapotot

### Számolt metrikák

Nyitott pozíciókra többek között ezek készülnek:

- current price
- mark price
- pnl value
- pnl %
- roe %
- liquidation price estimate
- risk to stop value
- reward to take profit value
- entry gap %
- distance to stop %
- distance to take profit %

Lezárt pozíciókra:

- realized pnl
- realized percent
- realized roe
- exit reason
- opened_at
- closed_at

### Kapcsolat a predikciós blokkal

A paper trading cockpit nem teljesen önálló UI-ból indul, hanem a prediction panel kontextusából.

A frontend a predikció alapján automatikusan előtölti:

- symbol
- entry price
- stop loss
- take profit
- notes
- futures vagy spot logikához illő alapértékek

Vagyis a 3. blokk funkcionálisan külön service, de UX szinten a 2. blokkra épül rá.

## Végpontok összefoglalva

### Web

- `GET /`: dashboard főoldal

### API

- `GET /api/signals`: konfigurált párok technikai elemzése
- `GET /api/prediction?symbol=...`: részletes predikció egy párra
- `GET /api/paper-trades`: paper trading áttekintés
- `POST /api/paper-trades`: új szimulált pozíció nyitása
- `POST /api/paper-trades/update`: szimulált pozíció frissítése
- `POST /api/paper-trades/close`: szimulált pozíció zárása

## Frontend felépítés

### Fő nézet

Az egyetlen főoldali nézet:

- `app/Views/home/index.php`

Ez tartalmazza:

- hero / fejléc blokk
- signal grid
- prediction panel
- paper trading panel

### Frontend logika

A dinamikus működés nagy része itt van:

- `public/assets/js/dashboard.js`

Feladatai:

- signal lista időzített frissítése
- prediction lekérése
- prediction renderelése
- paper form alapértékeinek szinkronizálása
- paper preview számítása
- paper account, open positions és history renderelése
- inline update és close műveletek kezelése

## Adatfolyam összefoglalása

### 1. Elemzési adatfolyam

`Dashboard oldal` -> `HomeController` -> `MarketAnalyzer` -> `BinanceApiClient` + `SignalEngine` -> `View`

### 2. Predikciós adatfolyam

`Prediction gomb` -> `dashboard.js` -> `PredictionController` -> `PairPredictionService` -> `BinanceApiClient` + `IndicatorService` -> `JSON válasz` -> `prediction panel`

### 3. Paper trading adatfolyam

`Paper form / inline action` -> `dashboard.js` -> `PaperTradeController` -> `PaperTradeService` -> `PaperTradeRepository` + `BinanceApiClient` -> `JSON válasz` -> `paper cockpit`

## Fontos konfigurációs pontok

### `config/app.php`

- alkalmazás neve
- timezone
- base URL

### `config/pairs.php`

- figyelt párok
- elemzési és predikciós timeframe-ek
- frissítési idő
- candle limit

### `config/strategy.php`

- scoring küszöbök
- kockázati limitek
- indikátor súlyok

### `config/paper.php`

- paper account és risk paraméterek
- leverage limitek
- tárolási fájl

## Mi micsoda gyors térkép

### Ha az elemzésen akarsz változtatni

Elsőként nézd:

- `config/strategy.php`
- `config/pairs.php`
- `app/Services/Strategy/SignalEngine.php`
- `app/Services/Strategy/RiskFilterService.php`
- `app/Services/Strategy/IndicatorService.php`

### Ha a predikció működésén akarsz változtatni

Elsőként nézd:

- `config/pairs.php`
- `app/Services/Prediction/PairPredictionService.php`

### Ha a paper trading logikán akarsz változtatni

Elsőként nézd:

- `config/paper.php`
- `app/Services/PaperTrading/PaperTradeService.php`
- `app/Services/PaperTrading/PaperTradeRepository.php`
- `storage/cache/paper_trades.json`

### Ha a UI-t akarod módosítani

Elsőként nézd:

- `app/Views/home/index.php`
- `app/Views/layouts/main.php`
- `public/assets/js/dashboard.js`
- `public/assets/css/app.css`

## Jelenlegi projektlogika röviden

- Az **1. blokk** automatikusan, ciklikusan elemzi az előre megadott párokat több idősávon.
- A **2. blokk** egy kiválasztott párra külön, mélyebb becslést készít.
- A **3. blokk** a predikció kontextusából indított szimulált kereskedést kezeli saját állapottal.

Ez alapján a rendszer már most is jól rétegezett:

- adatszerzés: `BinanceApiClient`
- technikai döntés: `SignalEngine`
- részletes becslés: `PairPredictionService`
- szimulált kereskedés: `PaperTradeService`
- állapotmentés: `PaperTradeRepository`
- megjelenítés: view + frontend JS

## Javasolt további dokumentációs irányok

Ha később tovább akarjuk bontani a projektet, külön dokumentumot lehet írni ezekhez:

- indikátorok és scoring logika részletes leírása
- paper trading számítási képletek
- API szerződés mintapéldákkal
- fejlesztői onboarding és deploy leírás
