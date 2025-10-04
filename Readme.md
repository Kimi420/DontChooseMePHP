# Don't Choose Me (aka "Don't Pick Me") – Digitales, Dixit-inspiriertes Kartenspiel

Multiplayer-Partyspiel: Ein Spieler gibt als Erzähler einen Hinweis, alle legen passende Bildkarten, es wird gemischt, abgestimmt und gepunktet.

Stand: 2025-09-29

## Aktuelle Highlights
- Karten-UI im Hochformat 3:4, Bild füllt die Karte komplett (randlos via Cropping)
- Reroll: einmal pro Runde eine Handkarte tauschen
  - Erzähler: nur in der Storytelling-Phase, bevor Hinweis/Karte gesetzt sind
  - Andere Spieler: in der SelectCards-Phase, solange noch nicht abgegeben wurde
  - Server enforced (Backend prüft und protokolliert pro Runde)
- Reveal-Phase: Owner- und Voter-Infos als Overlay direkt auf der Karte (mit Gradient, gut lesbar)
- Audio: Phasenmusik (Lobby/Story/Voting) + Effekt bei Phasenwechsel, Autoplay-Block-Handhabung
- Auto-Next: In der Reveal-Phase startet der Erzähler die nächste Runde automatisch nach 10s (oder manuell sofort)

## Projektstruktur (Auszug)
```
DontChooseMePHP/
├─ backend/
│  ├─ Game_api.php                # Zentrales API-Endpoint (POST/GET actions)
│  ├─ NormalizedGameService.php   # Game-Logik, Runden, Reroll, Scoring, DB
│  ├─ Database.php                # PDO-DB-Anbindung
│  └─ cards.json                  # Kartendaten (Bildpfade etc.)
├─ config/
│  └─ database.php                # DB-Zugangsdaten
├─ frontend/
│  ├─ src/
│  │  ├─ Game.js                  # Haupt-Spieloberfläche
│  │  ├─ GameStyle.css            # Karten, Grids, Badges, Reveal-Overlay
│  │  ├─ AppLayout.css            # Globales Layout/Theme
│  │  ├─ api.js                   # REST-Aufrufe an Game_api.php
│  │  ├─ AudioManager.js          # Musik & Effekte
│  │  └─ ...
│  ├─ build/                      # Produktions-Build (statische Assets)
│  └─ package.json
└─ Readme.md (dieses Dokument)
```

## Voraussetzungen
- Backend: PHP 8.1+ mit PDO (MySQL/MariaDB)
- Datenbank: MySQL/MariaDB
- Frontend: Node.js 18+ und npm

## Lokale Entwicklung
1) Backend konfigurieren
- Datei `config/database.php` mit deinen DB-Zugangsdaten füllen (User mit CREATE/INSERT/UPDATE/DELETE-Rechten).
- PHP-Built-in-Server starten (Beispiel im Projektstamm):

```cmd
php -S localhost:8000 -t .
```

Das API ist dann z. B. unter `http://localhost:8000/backend/Game_api.php` erreichbar.

2) Frontend konfigurieren und starten
- In `frontend/src/config.json` die API-URL setzen, z. B.:

```json
{ "API_URL": "http://localhost:8000/backend" }
```

- Abhängigkeiten installieren und Dev-Server starten:

```cmd
cd frontend
npm install
npm start
```

Das Frontend läuft typischerweise auf `http://localhost:3000/`.

Hinweis Assets/Sounds: Im Produktionsbuild liegen die Audios unter `/sounds` (siehe `frontend/build/sounds`). Für lokale Entwicklung müssen sie vom Dev-Server als `sounds/*.mp3` erreichbar sein.

## Produktion / Deployment (Kurzfassung)
- Frontend bauen:

```cmd
cd frontend
npm install
npm run build
```

- Build-Inhalt (`frontend/build`) deployen (gleiche Domain wie Backend empfohlen).
- Backend (Ordner `backend` + `config/database.php`) auf einen PHP-Webspace/Server hochladen.
- In `frontend/src/config.json` oder per Umgebungssetup die produktive API-URL angeben (z. B. `https://deine-domain.tld/backend`).
- Datenbank-Berechtigungen sicherstellen. Tabellen werden bei Bedarf vom Backend angelegt.

Weitere Details siehe `README_DEPLOY.md`.

## Spielablauf (Phasen)
1. Storytelling: Erzähler wählt eine Karte aus seiner Hand und gibt einen Hinweis (max. 60 Zeichen).
2. SelectCards: Alle anderen wählen eine Handkarte, die zum Hinweis passt.
3. Voting: Gemischte Karten werden angezeigt, alle außer Erzähler stimmen für die Erzähler-Karte.
4. Reveal: Auflösung, Punktevergabe, danach nächste Runde (auto in 10s oder manuell).

## Reroll (Karte tauschen, 1× pro Runde)
- Verfügbar, wenn die Badge „1× Reroll verfügbar“ sichtbar ist.
- Button unter den Handkarten.
- Bedingungen (UI + Server):
  - Erzähler: nur in Storytelling, solange noch kein Hinweis/Karte gesetzt wurde.
  - Spieler: nur in SelectCards, solange noch nicht abgegeben wurde.
- Nach Nutzung in der Runde: „Reroll genutzt“-Badge und Button ausgeblendet.

## Kartenanzeige & Layout
- Hochformat 3:4 (Portrait), gleichmäßiges Cropping, randloses Füllen der Kachel.
- Reveal-Overlay: Owner-Name und Voter-Zeile als Overlay mit dunklem Verlauf direkt auf der Karte.
- „Eigene“-Badge (rot) und „Erzähler“-Badge (gold) gut sichtbar oben links.

## API-Überblick
Alle Aufrufe laufen über `backend/Game_api.php`.

- GET `Game_api.php?gameId=...&playerName=...` → aktueller Spielstatus (Polling)
- POST `Game_api.php` mit JSON-Body `{ action, gameId, playerName, ... }`

Wichtige Actions (Auszug):
- `createGame` (payload: `{ action:"createGame", playerName }`)
- `join` / `rejoin` (Lobby beitreten / zurückkehren)
- `start` (Spielstart)
- `giveHint` (Erzähler setzt Hinweis + Karte)
- `chooseCard` (Karte abgeben)
- `vote` (abstimmen)
- `nextRound` (nächste Runde)
- `resetMatch` (Match zurücksetzen)
- `setDeck` (Deck wählen)
- `reroll` (Karte tauschen, 1× pro Runde)
- `leave` (Spiel verlassen)

Beispiel: Reroll-Request
```json
{
  "action": "reroll",
  "gameId": "ABCD",
  "playerName": "Alice",
  "cardId": 123
}
```
Antwort (Erfolg)
```json
{ "success": true, "newCardId": 456 }
```
Antwort (Fehler, z. B. Limit)
```json
{ "success": false, "message": "Reroll bereits genutzt" }
```

## Datenbank
- Tabellen werden beim ersten Bedarf automatisch angelegt (u. a. Runden, Deck, Handkarten, Rerolls, Votes).
- DB-User braucht Rechte für CREATE/ALTER/INSERT/UPDATE/DELETE.

## Troubleshooting
- Karten mit weißem Rand / falsches Format: Das Frontend cropt Bilder randlos ins 3:4-Portrait. Falls Bildmaterial harte, eingebrannte Ränder hat, werden diese per leichtem Zoom ausgeblendet.
- Reroll-Button fehlt: Prüfe Phase/Regeln; Server verweigert Reroll bei bereits gesetztem Hinweis/Karte (Erzähler) oder nach Abgabe (Spieler) bzw. wenn das Rundeneinmal-Limit bereits genutzt wurde.
- Reveal-Infos fehlen: Nur in der Reveal-Phase als Overlay sichtbar; in anderen Phasen unter der Karte.
- Sounds stumm: Browser-Autoplay-Blocker erst per Interaktion „entsperren“.

## Lizenz & Hinweise
- Dieses Projekt ist ein inoffizielles, nicht-kommerzielles Fan-/Lernprojekt, inspiriert von „Dixit“ (Libellud). Keine Verbindung/Kein Endorsement.
- Bildrechte: Verwende nur Material, für das du Nutzungsrechte hast. Standardmäßig sind Kartenbilder Platzhalter/Demo.
- Code: Sofern nicht anders angegeben, mit einer üblichen Open-Source-Lizenz nutzbar; prüfe ggf. Lizenzdateien/Headers.

---
Fragen oder Vorschläge? Gerne Issues/Tickets anlegen oder direkt im Code kommentieren.
