# Deployment-Anleitung

Diese Anleitung beschreibt den produktiven Rollout des Projekts mit PHP-Backend und React-Frontend.

## Voraussetzungen
- Webhosting/Server mit PHP 8.1+ und PDO (MySQL/MariaDB)
- Datenbank (MySQL/MariaDB) mit User, der CREATE/ALTER/INSERT/UPDATE/DELETE darf
- Optionale lokale Build-Umgebung: Node.js 18+ und npm (für das React-Frontend)

## 1) Backend deployen
1. Ordner `backend/` auf den Webspace/Server hochladen (z. B. `public_html/backend`).
2. Datenbankzugang in `config/database.php` eintragen (Host, DB-Name, User, Passwort).
3. Prüfen, dass `backend/Game_api.php` erreichbar ist (z. B. `https://deine-domain.tld/backend/Game_api.php`).
   - Die Tabellen werden vom Backend bei Bedarf automatisch erzeugt.

## 2) Frontend konfigurieren & bauen
1. In `frontend/src/config.json` die produktive API-URL setzen, z. B.:
   ```json
   { "API_URL": "https://deine-domain.tld/backend" }
   ```
2. Produktionsbuild erzeugen:
   ```cmd
   cd frontend
   npm install
   npm run build
   ```
3. Den Inhalt von `frontend/build/` auf den Webspace/Server laden (z. B. `public_html/`).
   - Alternativ kannst du den Build in ein Unterverzeichnis legen (z. B. `public_html/app/`).

## 3) Verzeichnisstruktur (Beispiel)
```
public_html/
├─ backend/                 # PHP-Backend (Game_api.php, NormalizedGameService.php, ...)
│  └─ ...
├─ sounds/                  # Falls separat ausgeliefert
│  ├─ lobby.mp3
│  ├─ storyteller.mp3
│  ├─ voting.mp3
│  └─ phase-change.mp3
├─ index.html               # aus frontend/build
├─ asset-manifest.json      # aus frontend/build
└─ static/                  # aus frontend/build/static
   ├─ js/
   └─ css/
```

Hinweis zu Sounds/Assets:
- Im Build liegen die Audios als `sounds/*.mp3`. Stelle sicher, dass sie unter derselben Domain wie das Frontend erreichbar sind (z. B. `https://deine-domain.tld/sounds/...`).

## 4) Domain & Routing
- Idealerweise liegen Frontend und Backend auf derselben Domain, damit keine CORS-Probleme entstehen.
- API-Endpunkt: `https://deine-domain.tld/backend/Game_api.php`
- Frontend: `https://deine-domain.tld/` (oder ein Unterordner)

## 5) Smoke-Test
- Seite im Browser öffnen und testen:
  - Lobby erstellen/beitreten
  - Spiel starten
  - Kartenanzeige (3:4 hochkant) und Reveal-Overlay prüfen
  - Reroll-Button (einmal pro Runde) prüfen
  - Audio (Musik/Effekte) prüfen

## 6) Fehlersuche
- API nicht erreichbar: Pfade prüfen (`backend/Game_api.php`), Server-Logs ansehen.
- DB-Probleme: Zugangsdaten in `config/database.php` validieren, Rechte prüfen.
- CORS/Old Cache: Frontend/Backend auf derselben Domain betreiben; Browser-Cache leeren / Hard-Reload.
- Sounds stumm: Autoplay erfordert Benutzerinteraktion; Pfade zu `/sounds/*.mp3` prüfen.

---

Tipp: Für Staging bitte separate DB und Subdomain verwenden, um Daten zu trennen.
