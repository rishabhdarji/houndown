<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/policy.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy and consent</title>
  <link rel="stylesheet" href="./consent.css">
</head>
<body>
  <main class="consent-card">
    <p class="eyebrow">Hound privacy test</p>
    <h1>You are in control of your data</h1>
    <p>
      This page is a transparent, opt-in demonstration. Nothing is requested or
      sent until you review the list below and select the consent checkbox.
    </p>

    <section class="notice">
      <h2>What will be shared</h2>
      <ul>
        <li><strong>Location (optional):</strong> latitude, longitude, accuracy, altitude (when available), and timestamp.</li>
        <li><strong>Browser details:</strong> browser language, timezone, platform, screen size, touch support, and coarse capability hints.</li>
      </ul>
      <p class="small">
        Legacy telemetry in the main demo is not enabled by this consent.
        It remains disabled unless a separate, more specific notice and consent
        flow is added for each additional category.
      </p>
      <p class="small">
        The browser will ask for location permission separately. You can deny it;
        the other details will not be sent unless you also consent below.
        The web server necessarily receives a network address to handle the
        request, but this demonstration does not store or enrich it. No
        passwords, files, contacts, messages, keystrokes, camera, microphone,
        clipboard, browsing history, or persistent hardware identifiers are
        requested. Data is used only for this demonstration and should be
        deleted when it is complete.
      </p>
    </section>

    <section aria-labelledby="preview-heading">
      <h2 id="preview-heading">Preview</h2>
      <pre id="data-preview">Location has not been requested. Select "Prepare preview" to continue.</pre>
    </section>

    <div id="status" class="status" role="status" aria-live="polite"></div>
    <div class="actions">
      <button id="prepare" type="button">Prepare preview</button>
      <button id="share" type="button" disabled>Share the displayed data</button>
    </div>
    <label class="consent-row">
      <input id="consent" type="checkbox" disabled>
      <span>I have reviewed the preview and explicitly consent to send only the displayed details for this demonstration.</span>
    </label>
    <p class="small">To withdraw consent after submission, contact the demonstration owner with the session ID shown in the confirmation.</p>
    <p><a href="../index_chat.html">Return to the main demo</a></p>
  </main>
  <script src="./consent.js"></script>
</body>
</html>
