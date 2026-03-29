# Binance Watcher

Egyszerű, saját PHP MVC alapokra épített Binance signal- és prediction dashboard.

## Fő funkciók

- Konfigurálható párok figyelése
- Több idősíkos signal ellenőrzés
- Kiválasztott párra részletes prediction nézet
- Dockeres futtatás Nginx + PHP-FPM környezetben

## Struktúra

- `public/`: belépési pont
- `app/Core/`: mini framework réteg
- `app/Services/Strategy/`: signal logika és indikátorok
- `app/Services/Prediction/`: on-demand előbecslés
- `app/Views/`: dashboard nézetek
- `config/`: alkalmazás- és stratégia-beállítások

## Végpontok

- `GET /`: dashboard
- `GET /api/signals`: konfigurált párok signaljai
- `GET /api/prediction?symbol=BTCUSDT`: részletes prediction egy párra

## Futtatás

```bash
docker compose up --build
```

Alapértelmezett URL:

- `http://localhost:8080`

## Fontos megjegyzések

- A jelenlegi signal konfiguráció 15m idősíkra van összerakva.
- A prediction külön, részletesebb nézetként fut kiválasztott párra.
- A repository UTF-8 + LF használatra van beállítva.