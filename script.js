const msgerForm = get(".msger-inputarea");
const msgerInput = get(".msger-input");
const msgerChat = get(".msger-chat");

const BOT_IMG = "https://image.flaticon.com/icons/svg/327/327779.svg";
const PERSON_IMG = "https://image.flaticon.com/icons/svg/145/145867.svg";
const BOT_NAME = "Admin";
const PERSON_NAME = "User";

if (msgerForm && msgerInput && msgerChat) {
  msgerForm.addEventListener("submit", event => {
    event.preventDefault();

    const msgText = msgerInput.value;
    if (!msgText) return;
    appendMessage(PERSON_NAME, PERSON_IMG, "right", msgText);
    captureChatMessage("user", msgText);
    msgerInput.value = "";
    botResponse(msgText);
  });
}

document.addEventListener("hound:bot-message", event => {
  const text = event.detail && event.detail.text;
  if (text && msgerChat) {
    appendMessage(resolveBotName(), resolveBotImg(), "left", text);
    captureChatMessage("bot", text);
  }
});

function appendMessage(name, img, side, text) {
  if (!msgerChat) return;
  const msgHTML = `
    <div class="msg ${side}-msg">
      <div class="msg-img" style="background-image: url(${img})"></div>

      <div class="msg-bubble">
        <div class="msg-info">
          <div class="msg-info-name">${name}</div>
          <div class="msg-info-time">${formatDate(new Date())}</div>
        </div>

        <div class="msg-text">${escapeHtml(text)}</div>
      </div>
    </div>
  `;

  msgerChat.insertAdjacentHTML("beforeend", msgHTML);
  msgerChat.scrollTop += 500;
}

async function botResponse(userMessage) {
  const typingDelay = Math.min(1800, userMessage.split(" ").length * 120);

  setTimeout(async () => {
    let reply = null;
    try {
      const response = await fetch("api/chat.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          message: userMessage,
          session_id: window.Hound ? window.Hound.sessionId : null,
          location_denied: window.HoundSmart ? window.HoundSmart.isLocationDenied() : false,
          gps_retry_count: window.HoundSmart ? window.HoundSmart.getGpsRetryCount() : 0
        })
      });
      const data = await response.json();
      reply = data.reply;
    } catch (error) {
      reply = null;
    }

    if (!reply) {
      reply = "Enable location so I can show you people nearby.";
    }

    appendMessage(resolveBotName(), resolveBotImg(), "left", reply);
    captureChatMessage("bot", reply);
  }, typingDelay);
}

function captureChatMessage(role, text) {
  if (window.Hound && typeof window.Hound.send === "function") {
    window.Hound.send("chat_message", {
      chat: {
        role,
        text,
        length: text.length
      }
    });
  }
}

function resolveBotName() {
  const config = window.HoundSmart && window.HoundSmart.getConfig();
  return (config && config.branding && config.branding.bot_name) || BOT_NAME;
}

function resolveBotImg() {
  const config = window.HoundSmart && window.HoundSmart.getConfig();
  return (config && config.branding && config.branding.avatar_url) || BOT_IMG;
}

function get(selector, root = document) {
  return root.querySelector(selector);
}

function formatDate(date) {
  const h = "0" + date.getHours();
  const m = "0" + date.getMinutes();
  return `${h.slice(-2)}:${m.slice(-2)}`;
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
