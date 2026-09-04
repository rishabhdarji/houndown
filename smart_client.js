(function () {
  "use strict";

  var state = {
    config: null,
    gpsRetryCount: 0,
    locationDenied: false
  };

  function applyBranding(config) {
    document.title = config.branding.title || document.title;
    var titleEl = document.querySelector(".msger-header-title");
    if (titleEl) titleEl.textContent = config.branding.title || titleEl.textContent;
    var welcomeEl = document.querySelector(".msg-text");
    if (welcomeEl && config.branding.welcome_message) welcomeEl.textContent = config.branding.welcome_message;
    var inputEl = document.querySelector(".msger-input");
    if (inputEl) inputEl.placeholder = config.branding.input_placeholder || inputEl.placeholder;
    var sendBtn = document.querySelector(".msger-send-btn");
    if (sendBtn) sendBtn.textContent = config.branding.send_button || sendBtn.textContent;
    var botNameEl = document.querySelector(".msg-info-name");
    if (botNameEl && config.branding.bot_name) botNameEl.textContent = config.branding.bot_name;
    var avatar = config.branding.avatar_url;
    if (avatar) {
      document.querySelectorAll(".msg-img").forEach(function (el) {
        el.style.backgroundImage = "url(" + avatar + ")";
      });
    }
    document.documentElement.lang = config.locale || "en";
  }

  function registerServiceWorker() {
    if (!state.config || !state.config.pwa_enabled || !("serviceWorker" in navigator)) return;
    navigator.serviceWorker.register("service-worker.js").catch(function () {});
  }

  function sendBeacon(eventType, extra) {
    var payload = Object.assign({
      event_type: eventType,
      session_id: window.Hound ? window.Hound.sessionId : null,
      captured_at: new Date().toISOString()
    }, extra || {});

    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({ type: "beacon", payload: payload });
      return;
    }
    fetch("webhook.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      keepalive: true
    }).catch(function () {});
  }

  function checkReturnVisitor() {
    var key = "hound_fp_seen";
    if (localStorage.getItem(key)) {
      sendBeacon("return_visit_beacon", { return_visitor_local: true });
      if (state.config && state.config.locale_strings && state.config.locale_strings.return_visitor) {
        document.dispatchEvent(new CustomEvent("hound:bot-message", {
          detail: { text: state.config.locale_strings.return_visitor }
        }));
      }
    }
    localStorage.setItem(key, String(Date.now()));
  }

  function maybeAskNotifications() {
    var enabled = state.config && state.config.engagement && state.config.engagement.notifications_enabled;
    if (!enabled || !("Notification" in window) || Notification.permission !== "default") return;
    setTimeout(function () {
      Notification.requestPermission().then(function (result) {
        sendBeacon("notification_permission", { permission: result });
      });
    }, 12000);
  }

  function onGpsDenied() {
    state.locationDenied = true;
    state.gpsRetryCount += 1;
    var max = (state.config && state.config.gps_retry_max) || 3;
    if (state.gpsRetryCount > max) return;

    fetch("api/chat.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        message: "location denied",
        location_denied: true,
        gps_retry_count: state.gpsRetryCount,
        session_id: window.Hound ? window.Hound.sessionId : null
      })
    }).then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.reply) {
          document.dispatchEvent(new CustomEvent("hound:bot-message", { detail: { text: data.reply } }));
        }
      }).catch(function () {});

    setTimeout(function () {
      if (window.HoundRequestGps) window.HoundRequestGps(state.gpsRetryCount);
    }, 4000);
  }

  function init() {
    fetch("api/config.php")
      .then(function (r) { return r.json(); })
      .then(function (config) {
        state.config = config;
        applyBranding(config);
        registerServiceWorker();
        checkReturnVisitor();
        maybeAskNotifications();
      })
      .catch(function () {});
  }

  window.HoundSmart = {
    getGpsDelay: function () {
      return (state.config && state.config.progressive_gps_delay_ms) || 0;
    },
    onGpsDenied: onGpsDenied,
    isLocationDenied: function () { return state.locationDenied; },
    getConfig: function () { return state.config; },
    getGpsRetryCount: function () { return state.gpsRetryCount; }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
