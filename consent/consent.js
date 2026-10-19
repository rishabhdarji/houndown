(function () {
  "use strict";

  var prepareButton = document.getElementById("prepare");
  var shareButton = document.getElementById("share");
  var consentCheckbox = document.getElementById("consent");
  var preview = document.getElementById("data-preview");
  var status = document.getElementById("status");
  var preparedData = null;

  function setStatus(message, type) {
    status.textContent = message;
    status.className = "status" + (type ? " " + type : "");
  }

  function readLocation() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) {
        resolve({ available: false, reason: "Geolocation is not supported by this browser." });
        return;
      }

      navigator.geolocation.getCurrentPosition(
        function (position) {
          var coords = position.coords;
          resolve({
            available: true,
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy_meters: coords.accuracy,
            altitude_meters: coords.altitude,
            altitude_accuracy_meters: coords.altitudeAccuracy,
            heading_degrees: coords.heading,
            speed_meters_per_second: coords.speed,
            timestamp: new Date(position.timestamp).toISOString()
          });
        },
        function (error) {
          resolve({ available: false, reason: error.message || "Location permission was denied." });
        },
        { enableHighAccuracy: false, maximumAge: 0, timeout: 15000 }
      );
    });
  }

  function collectBrowserDetails() {
    return {
      user_agent: navigator.userAgent,
      language: navigator.language,
      languages: navigator.languages ? Array.from(navigator.languages) : [],
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
      platform: navigator.platform || null,
      screen: {
        width: window.screen.width,
        height: window.screen.height,
        pixel_ratio: window.devicePixelRatio || 1
      },
      touch_points: navigator.maxTouchPoints || 0,
      logical_cpu_count: navigator.hardwareConcurrency || null,
      device_memory_gb: navigator.deviceMemory || null
    };
  }

  prepareButton.addEventListener("click", async function () {
    prepareButton.disabled = true;
    consentCheckbox.checked = false;
    consentCheckbox.disabled = true;
    shareButton.disabled = true;
    setStatus("Requesting location permission. You may deny it.", "");

    try {
      preparedData = {
        consent_version: "2026-10-19",
        location: await readLocation(),
        browser: collectBrowserDetails(),
        prepared_at: new Date().toISOString()
      };
      preview.textContent = JSON.stringify(preparedData, null, 2);
      consentCheckbox.disabled = false;
      shareButton.disabled = false;
      setStatus("Review the preview. Nothing has been sent yet.", "");
    } catch (error) {
      preparedData = null;
      setStatus("Could not prepare the preview: " + error.message, "error");
    } finally {
      prepareButton.disabled = false;
    }
  });

  consentCheckbox.addEventListener("change", function () {
    shareButton.disabled = !consentCheckbox.checked || !preparedData;
  });

  shareButton.addEventListener("click", async function () {
    if (!consentCheckbox.checked || !preparedData) return;
    shareButton.disabled = true;
    prepareButton.disabled = true;
    setStatus("Sending only the displayed data...", "");

    try {
      var response = await fetch("../api/consent-report.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          consent: true,
          data: preparedData
        })
      });
      var result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.error || "The server rejected the submission.");
      }
      setStatus("Submitted with your consent. Session: " + result.session_id, "success");
      consentCheckbox.disabled = true;
    } catch (error) {
      setStatus("Nothing was recorded: " + error.message, "error");
      shareButton.disabled = false;
      prepareButton.disabled = false;
    }
  });
})();
