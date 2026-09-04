let map;
let markers = [];
let selectedSessionId = null;

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error("Request failed: " + response.status);
  }
  return response.json();
}

function initMap() {
  map = L.map("map").setView([20, 0], 2);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "&copy; OpenStreetMap"
  }).addTo(map);
}

function clearMarkers() {
  markers.forEach(marker => map.removeLayer(marker));
  markers = [];
}

function sumCampaigns(campaigns) {
  const totals = { opens: 0, locations: 0, chat_messages: 0, return_visitors: 0 };
  Object.values(campaigns || {}).forEach(campaign => {
    totals.opens += campaign.opens || 0;
    totals.locations += campaign.locations || 0;
    totals.chat_messages += campaign.chat_messages || 0;
    totals.return_visitors += campaign.return_visitors || 0;
  });
  return totals;
}

function renderStats(analytics, sessions) {
  const stats = sumCampaigns(analytics.campaigns || {});
  document.getElementById("stats").innerHTML = `
    <div class="stat-card">Sessions<strong>${sessions.length}</strong></div>
    <div class="stat-card">Opens<strong>${stats.opens}</strong></div>
    <div class="stat-card">GPS Hits<strong>${stats.locations}</strong></div>
    <div class="stat-card">Chat Msgs<strong>${stats.chat_messages}</strong></div>
    <div class="stat-card">Return Visits<strong>${stats.return_visitors}</strong></div>
  `;
}

function renderSessions(sessions) {
  const list = document.getElementById("session-list");
  list.innerHTML = "";
  sessions.forEach(session => {
    const item = document.createElement("div");
    item.className = "session-item";
    item.dataset.sessionId = session.session_id || "";
    const ip = session.server && session.server.server_ip ? session.server.server_ip : "unknown";
    const city = session.server && session.server.ip_enrichment ? session.server.ip_enrichment.city : "n/a";
    item.innerHTML = `<strong>${session.session_id || "unknown"}</strong><br>${ip} · ${city}<br>Human ${session.human_score || "?"} · Risk ${session.risk_score || "?"}`;
    item.onclick = () => {
      selectedSessionId = session.session_id || null;
      showSession(session, item);
    };
    list.appendChild(item);
  });
}

function showSession(session, element) {
  document.querySelectorAll(".session-item").forEach(el => el.classList.remove("active"));
  if (element) element.classList.add("active");

  clearMarkers();
  const coords = session.coordinates;
  if (coords && coords.latitude && coords.longitude) {
    const marker = L.marker([coords.latitude, coords.longitude]).addTo(map);
    marker.bindPopup(session.address && session.address.display_name ? session.address.display_name : "Location");
    markers.push(marker);
    map.setView([coords.latitude, coords.longitude], 13);
  }

  document.getElementById("session-detail").textContent = JSON.stringify(session, null, 2);
}

async function refreshDashboard() {
  try {
    const [sessionsPayload, analytics] = await Promise.all([
      fetchJson("../api/sessions.php?action=list"),
      fetchJson("../api/sessions.php?action=analytics")
    ]);
    const sessions = sessionsPayload.sessions || [];
    renderStats(analytics || {}, sessions);
    renderSessions(sessions);

    if (!sessions.length) {
      return;
    }

    const selected = sessions.find(session => session.session_id === selectedSessionId) || sessions[0];
    selectedSessionId = selected.session_id || null;
    const selectedEl = document.querySelector(`.session-item[data-session-id="${CSS.escape(selectedSessionId || "")}"]`);
    showSession(selected, selectedEl);
  } catch (error) {
    console.error("Dashboard refresh failed", error);
  }
}

document.getElementById("refresh-btn").addEventListener("click", refreshDashboard);
initMap();
refreshDashboard();
setInterval(refreshDashboard, 15000);
