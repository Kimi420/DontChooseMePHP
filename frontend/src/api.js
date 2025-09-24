import config from './config.json';

const RAW_API_URL = config.API_URL || '';
// Trailing Slash entfernen für saubere URL-Konstruktion
const API_URL = RAW_API_URL.replace(/\/+$/, '');

// Hilfsfunktion zum sicheren Parsen von JSON-Antworten
async function parseJSONResponse(response) {
  let text;
  try {
    text = await response.text();
  } catch (e) {
    return { success: false, message: 'Antwort konnte nicht gelesen werden' };
  }

  if (!text) {
    return { success: false, message: 'Leere Antwort vom Server' };
  }

  // Versuchen direkt zu parsen
  try {
    return JSON.parse(text);
  } catch (e) {
    // Falls HTML Noise enthalten ist, letzten JSON-Block extrahieren
    const start = text.lastIndexOf('{');
    const end = text.lastIndexOf('}');
    if (start !== -1 && end !== -1 && end > start) {
      try {
        const possible = text.substring(start, end + 1);
        return JSON.parse(possible);
      } catch (_) { /* ignorieren */ }
    }
    return { success: false, message: 'Ungültige Server-Antwort', raw: text };
  }
}

export async function createGame(playerName) {
  const res = await fetch(`${API_URL}/Lobby.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ playerName })
  });
  return parseJSONResponse(res);
}

export async function joinGame(gameId, playerName) {
  const res = await fetch(`${API_URL}/Lobby.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, playerName })
  });
  return parseJSONResponse(res);
}

export async function getGameState(gameId, playerName) {
  const url = new URL(`${API_URL}/Game_api.php`);
  url.searchParams.set('gameId', gameId);
  if (playerName) url.searchParams.set('playerName', playerName);
  const res = await fetch(url.toString(), { method: 'GET' });
  return parseJSONResponse(res);
}

export async function startGame(gameId) {
  const res = await fetch(`${API_URL}/Game_api.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, action: 'start' })
  });
  return parseJSONResponse(res);
}

export async function giveHint(gameId, playerName, cardId, hint) {
  const res = await fetch(`${API_URL}/Game_api.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, action: 'giveHint', playerName, cardId, hint })
  });
  return parseJSONResponse(res);
}

export async function chooseCard(gameId, playerName, cardId) {
  const res = await fetch(`${API_URL}/Game_api.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, action: 'chooseCard', playerName, cardId })
  });
  return parseJSONResponse(res);
}

export async function vote(gameId, playerName, cardId) {
  const res = await fetch(`${API_URL}/Game_api.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, action: 'vote', playerName, cardId })
  });
  return parseJSONResponse(res);
}

export async function nextRound(gameId) {
  const res = await fetch(`${API_URL}/Game_api.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ gameId, action: 'nextRound' })
  });
  return parseJSONResponse(res);
}
