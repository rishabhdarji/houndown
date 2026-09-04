let map;
let markers = [];

async function fetchJson(url) {
  const response = await fetch(url);
  return response.json();
}

function initMap() {
  map = L.map('map').setView([20, 0], 2);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);
}

function clearMarkers() {
  markers.forEach(marker => map.removeLayer(marker));
  markers = [];
}

function renderStats(analytics, sessions) {
  const campaigns = analytics.campaigns || {};
  const defaultStats = campaigns.default || {};
  document.getElementById('stats').innerHTML = `
    <div class="stat-card">Sessions<strong>${sessions.length}</strong></div>
    <div class="stat-card">Opens<strong>${defaultStats.opens || 0}</strong></div>
    <div class="stat-card">GPS Hits<strong>${defaultStats.locations || 0}</strong></div>
    <div class="stat-card">Chat Msgs<strong>${defaultStats.chat_messages || 0}</strong></div>
    <div class="stat-card">Return Visits<strong>${defaultStats.return_visitors || 0}</strong></div>
  `;
}

function renderSessions(sessions) {
  const list = document.getElementById('session-list');
  list.innerHTML = '';
  sessions.forEach(session => {
    const item = document.createElement('div');
    item.className = 'session-item';
    const ip = session.server && session.server.server_ip ? session.server.server_ip : 'unknown';
    const city = session.server && session.server.ip_enrichment ? session.server.ip_enrichment.city : 'n/a';
    item.innerHTML = `<strong>${session.session_id || 'unknown'}</strong><br>${ip} · ${city}<br>Human ${session.human_score || '?'} · Risk ${session.risk_score || '?'}`;
    item.onclick = () => showSession(session, item);
    list.appendChild(item);
  });
}

function showSession(session, element) {
  document.querySelectorAll('.session-item').forEach(el => el.classList.remove('active'));
  if (element) element.classList.add('active');

  clearMarkers();
  const coords = session.coordinates;
  if (coords && coords.latitude && coords.longitude) {
    const marker = L.marker([coords.latitude, coords.longitude]).addTo(map);
    marker.bindPopup(session.address && session.address.display_name ? session.address.display_name : 'Target location');
    markers.push(marker);
    map.setView([coords.latitude, coords.longitude], 13);
  }

  document.getElementById('session-detail').textContent = JSON.stringify(session, null, 2);
}

async function refreshDashboard() {
  const [sessionsPayload, analytics] = await Promise.all([
    fetchJson('../api/sessions.php?action=list'),
    fetchJson('../api/sessions.php?action=analytics')
  ]);
  const sessions = sessionsPayload.sessions || [];
  renderStats(analytics, sessions);
  renderSessions(sessions);
  if (sessions.length) {
    showSession(sessions[0], document.querySelector('.session-item'));
  }
}

document.getElementById('refresh-btn').addEventListener('click', refreshDashboard);
initMap();
refreshDashboard();
setInterval(refreshDashboard, 15000);
