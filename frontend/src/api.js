import config from './config.json';

// API-Client für die Kommunikation mit dem PHP-Backend
const API_URL = config.API_URL; // Passe ggf. den Pfad an

// Hilfsfunktion zum sicheren Parsen von JSON-Antworten
async function parseJSONResponse(response) {
  try {
    // Prüfen, ob die Antwort erfolgreich war
    if (!response.ok) {
      // Bei 500er Fehlern versuchen wir trotzdem, die Antwort zu lesen
      if (response.status === 500) {
        try {
          const text = await response.text();
          console.error('Server-Fehler (500):', text);
          return { success: false, message: `Server-Fehler: ${text}` };
        } catch {
          return { success: false, message: 'Server-Fehler (500): Keine Details verfügbar' };
        }
      }
      throw new Error(`HTTP error! Status: ${response.status}`);
    }

    // Text der Antwort lesen
    const text = await response.text();

    // Wenn der Text leer ist, geben wir ein leeres Objekt zurück
    if (!text || text.trim() === '') {
      console.warn('Leere Antwort vom Server erhalten');
      return { success: false, message: 'Leere Antwort vom Server' };
    }

    // Versuchen, den Text als JSON zu parsen
    try {
      return JSON.parse(text);
    } catch (jsonError) {
      console.error('JSON-Parsing-Fehler:', jsonError);
      console.error('Erhaltener Text:', text);
      throw new Error('Ungültiges JSON vom Server erhalten');
    }
  } catch (error) {
    console.error('API-Anfragefehler:', error);
    return { success: false, message: `API-Fehler: ${error.message}` };
  }
}

export async function createGame(playerName) {
  try {
    const res = await fetch(`${API_URL}/backend/Lobby.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ playerName })
    });
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Erstellen des Spiels:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}

export async function joinGame(gameId, playerName) {
  try {
    const res = await fetch(`${API_URL}/Lobby.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ gameId, playerName })
    });
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Beitreten des Spiels:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}

export async function getGameState(gameId) {
  try {
    const res = await fetch(`${API_URL}/Game_api.php?gameId=${gameId}`);
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Abrufen des Spielstatus:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}

export async function giveHint(gameId, playerName, cardId, hint) {
  try {
    const res = await fetch(`${API_URL}/Game_api.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ gameId, playerName, cardId, hint, action: 'giveHint' })
    });
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Senden des Hinweises:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}

export async function chooseCard(gameId, playerName, cardId) {
  try {
    const res = await fetch(`${API_URL}/Game_api.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ gameId, playerName, cardId, action: 'chooseCard' })
    });
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Auswählen einer Karte:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}

export async function vote(gameId, playerName, cardId) {
  try {
    const res = await fetch(`${API_URL}/Game_api.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ gameId, playerName, cardId, action: 'vote' })
    });
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Abstimmen:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}

export async function nextRound(gameId) {
  try {
    const res = await fetch(`${API_URL}/Game_api.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ gameId, action: 'nextRound' })
    });
    return parseJSONResponse(res);
  } catch (error) {
    console.error('Fehler beim Starten der nächsten Runde:', error);
    return { success: false, message: 'Verbindungsfehler: Bitte überprüfe deine Internetverbindung' };
  }
}
