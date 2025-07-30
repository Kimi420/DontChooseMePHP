# Deployment-Anleitung für Don't Pick Me

## Voraussetzungen
- Webhosting mit PHP-Unterstützung (z.B. Netcup)
- Möglichkeit, eigene Dateien hochzuladen (FTP, Web-FTP, SSH)
- Optional: Node.js für das Bauen des React-Frontends

## Schritt 1: Backend hochladen
1. Lade den gesamten `backend`-Ordner auf deinen Server (z.B. in `public_html/backend`).
2. Stelle sicher, dass die Datei `GameController.php` und die anderen PHP-Dateien vorhanden sind.
3. Die Datei `.htaccess` im backend-Ordner sorgt für CORS und API-Zugriff.

## Schritt 2: Frontend hochladen
1. Lade den gesamten `frontend`-Ordner auf deinen Server (z.B. in `public_html/frontend`).
2. Die Datei `.htaccess` im frontend-Ordner sorgt für korrektes Routing der React-App.
3. Die Sounds müssen im Ordner `frontend/sounds` liegen.

## Schritt 3: API-URL konfigurieren
1. Öffne die Datei `frontend/config.json` und trage die korrekte URL zu deinem Backend ein, z.B.:
   ```json
   { "API_URL": "https://deinedomain.de/backend" }
   ```

## Schritt 4: React-Frontend bauen (optional)
Falls du das Frontend lokal bauen möchtest:
1. Navigiere in den `frontend`-Ordner.
2. Führe `npm install` und dann `npm run build` aus.
3. Lade den Inhalt des `build`-Ordners auf den Server.

## Schritt 5: Domain und Dokumentstamm
- Setze den Dokumentstamm deiner Domain auf `public_html` oder den Ordner, in dem `frontend` und `backend` liegen.
- Prüfe, ob du über `https://deinedomain.de/frontend` und `https://deinedomain.de/backend/GameController.php` zugreifen kannst.

## Schritt 6: Testen
- Öffne die Seite im Browser und teste die wichtigsten Funktionen (Lobby, Spielstart, Karten, Sounds).
- Prüfe die Kommunikation zwischen Frontend und Backend.

---

**Hinweis:**
- Bei Problemen mit CORS, Routing oder PHP-Rechten prüfe die .htaccess und Server-Einstellungen.
- Für weitere Fragen oder Anpassungen kannst du dich jederzeit melden!

