<?php /* AI Asistan Chat Widget — footer.php'de include edilir */ ?>

<style>
/* ── AI Chat Widget ─────────────────────────────────────────────────────────── */
#aiChatBtn {
  position: fixed;
  bottom: 90px;
  right: 20px;
  z-index: 1050;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--ern, #00584E);
  color: #fff;
  border: none;
  box-shadow: 0 4px 16px rgba(0,0,0,.25);
  font-size: 1.4rem;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .2s, box-shadow .2s;
}
#aiChatBtn:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(0,0,0,.3); }
#aiChatBtn .ai-badge {
  position: absolute;
  top: -2px; right: -2px;
  background: #ef4444;
  color: #fff;
  border-radius: 50%;
  width: 16px; height: 16px;
  font-size: .6rem;
  display: flex; align-items: center; justify-content: center;
  display: none;
}
@media (min-width: 992px) {
  #aiChatBtn { bottom: 24px; }
}

#aiChatPanel {
  position: fixed;
  bottom: 155px; right: 16px;
  width: min(380px, calc(100vw - 32px));
  height: 500px;
  z-index: 1049;
  background: var(--bs-body-bg, #fff);
  border: 1px solid var(--bs-border-color, #dee2e6);
  border-radius: 16px;
  box-shadow: 0 8px 32px rgba(0,0,0,.18);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transform-origin: bottom right;
  transform: scale(0) translateY(20px);
  opacity: 0;
  transition: transform .25s cubic-bezier(.34,1.56,.64,1), opacity .2s;
  pointer-events: none;
}
#aiChatPanel.acik {
  transform: scale(1) translateY(0);
  opacity: 1;
  pointer-events: all;
}
@media (min-width: 992px) {
  #aiChatPanel { bottom: 88px; }
}

.ai-panel-header {
  background: var(--ern, #00584E);
  color: #fff;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.ai-panel-header .ai-avatar {
  width: 32px; height: 32px;
  background: rgba(255,255,255,.2);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem;
}
.ai-panel-header .ai-title { font-weight: 600; font-size: .95rem; flex: 1; }
.ai-panel-header .ai-subtitle { font-size: .72rem; opacity: .75; }
.ai-panel-close {
  background: none; border: none; color: #fff; opacity: .7;
  font-size: 1.1rem; cursor: pointer; padding: 4px;
  line-height: 1;
}
.ai-panel-close:hover { opacity: 1; }

.ai-mesajlar {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  scroll-behavior: smooth;
}

.ai-mesaj {
  max-width: 85%;
  padding: 8px 12px;
  border-radius: 12px;
  font-size: .855rem;
  line-height: 1.5;
  word-break: break-word;
}
.ai-mesaj.kullanici {
  align-self: flex-end;
  background: var(--ern, #00584E);
  color: #fff;
  border-bottom-right-radius: 4px;
}
.ai-mesaj.asistan {
  align-self: flex-start;
  background: var(--bs-tertiary-bg, #f1f5f9);
  color: var(--bs-body-color);
  border-bottom-left-radius: 4px;
}
[data-dark="1"] .ai-mesaj.asistan {
  background: #1e293b;
}
.ai-mesaj.asistan strong { color: var(--ern, #00584E); }
[data-dark="1"] .ai-mesaj.asistan strong { color: #4ade80; }

.ai-yazıyor {
  align-self: flex-start;
  display: flex;
  gap: 4px;
  padding: 10px 14px;
  background: var(--bs-tertiary-bg, #f1f5f9);
  border-radius: 12px;
  border-bottom-left-radius: 4px;
}
[data-dark="1"] .ai-yazıyor { background: #1e293b; }
.ai-yazıyor span {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: #94a3b8;
  animation: aiDot 1.2s infinite;
}
.ai-yazıyor span:nth-child(2) { animation-delay: .2s; }
.ai-yazıyor span:nth-child(3) { animation-delay: .4s; }
@keyframes aiDot {
  0%,60%,100% { transform: translateY(0); opacity:.5; }
  30% { transform: translateY(-5px); opacity:1; }
}

.ai-input-row {
  border-top: 1px solid var(--bs-border-color, #dee2e6);
  padding: 10px 12px;
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}
.ai-input-row textarea {
  flex: 1;
  resize: none;
  border: 1px solid var(--bs-border-color, #dee2e6);
  border-radius: 10px;
  padding: 8px 12px;
  font-size: .855rem;
  background: var(--bs-body-bg);
  color: var(--bs-body-color);
  outline: none;
  max-height: 90px;
  line-height: 1.4;
}
.ai-input-row textarea:focus { border-color: var(--ern, #00584E); }
.ai-gonder {
  width: 38px; height: 38px;
  border-radius: 50%;
  background: var(--ern, #00584E);
  color: #fff;
  border: none;
  font-size: 1rem;
  cursor: pointer;
  flex-shrink: 0;
  align-self: flex-end;
  display: flex; align-items: center; justify-content: center;
  transition: opacity .15s;
}
.ai-gonder:disabled { opacity: .4; cursor: default; }

.ai-oneri-listesi {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0 12px 10px;
}
.ai-oneri {
  font-size: .75rem;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid var(--ern, #00584E);
  color: var(--ern, #00584E);
  background: transparent;
  cursor: pointer;
  white-space: nowrap;
  transition: background .15s, color .15s;
}
.ai-oneri:hover { background: var(--ern, #00584E); color: #fff; }
</style>

<!-- Yüzen buton -->
<button id="aiChatBtn" title="AI Asistan" onclick="aiChatToggle()">
  <i class="bi bi-stars"></i>
  <span class="ai-badge" id="aiBadge"></span>
</button>

<!-- Chat paneli -->
<div id="aiChatPanel">
  <div class="ai-panel-header">
    <div class="ai-avatar"><i class="bi bi-stars"></i></div>
    <div style="flex:1">
      <div class="ai-title">AI Asistan</div>
      <div class="ai-subtitle">Verilerinizi sorgulayın</div>
    </div>
    <button class="ai-panel-close" onclick="aiChatKapat()" title="Kapat">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>

  <div class="ai-mesajlar" id="aiMesajlar"></div>

  <div class="ai-oneri-listesi" id="aiOneriler">
    <button class="ai-oneri" onclick="aiOneriGonder(this)">Bu ay kaç m³?</button>
    <button class="ai-oneri" onclick="aiOneriGonder(this)">Son 5 irsaliye</button>
    <button class="ai-oneri" onclick="aiOneriGonder(this)">Projeler listesi</button>
    <button class="ai-oneri" onclick="aiOneriGonder(this)">Tedarikçi bazlı toplam</button>
  </div>

  <div class="ai-input-row">
    <textarea id="aiInput" placeholder="Bir şey sorun… (Enter gönderir)" rows="1"
              onkeydown="aiKeyDown(event)" oninput="aiInputResize(this)"></textarea>
    <button class="ai-gonder" id="aiGonder" onclick="aiGonder()" title="Gönder">
      <i class="bi bi-send-fill"></i>
    </button>
  </div>
</div>

<script>
(function(){
  const ROOT = (typeof rootPath !== 'undefined') ? rootPath : '';
  const API  = ROOT + 'api/ai_asistan.php';
  const GECMIS_KEY = 'ai_chat_gecmis';
  const MAX_GECMIS  = 10;

  let gecmis  = [];
  let bekliyor = false;

  function yukle() {
    try { gecmis = JSON.parse(sessionStorage.getItem(GECMIS_KEY) || '[]'); } catch(e){ gecmis = []; }
    const kutu = document.getElementById('aiMesajlar');
    kutu.innerHTML = '';
    if (gecmis.length === 0) {
      ekleKarsilama();
    } else {
      gecmis.forEach(m => ekleBalon(m.rol, m.icerik, false));
      kutu.scrollTop = kutu.scrollHeight;
    }
  }

  function kaydet() {
    sessionStorage.setItem(GECMIS_KEY, JSON.stringify(gecmis.slice(-MAX_GECMIS)));
  }

  function ekleKarsilama() {
    ekleBalon('asistan',
      'Merhaba! Ben ERN Holding Beton Takip Sistemi\'nin AI asistanıyım.\n' +
      'İrsaliye verileri, projeler veya tedarikçiler hakkında sorularınızı yanıtlayabilirim.',
      false);
  }

  function ekleBalon(rol, metin, kaydetGecmis) {
    const kutu = document.getElementById('aiMesajlar');
    const div  = document.createElement('div');
    div.className = 'ai-mesaj ' + rol;
    div.innerHTML = metinFormatla(metin);
    kutu.appendChild(div);
    kutu.scrollTop = kutu.scrollHeight;
    if (kaydetGecmis) {
      gecmis.push({ rol, icerik: metin });
      kaydet();
    }
    return div;
  }

  function metinFormatla(t) {
    // Temel markdown: **bold**, satır sonu
    return t
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\n/g,'<br>');
  }

  function ekleYazıyor() {
    const kutu = document.getElementById('aiMesajlar');
    const div  = document.createElement('div');
    div.className = 'ai-yazıyor';
    div.id = 'aiYazıyor';
    div.innerHTML = '<span></span><span></span><span></span>';
    kutu.appendChild(div);
    kutu.scrollTop = kutu.scrollHeight;
  }

  function kaldir(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
  }

  window.aiChatToggle = function() {
    const panel = document.getElementById('aiChatPanel');
    panel.classList.toggle('acik');
    if (panel.classList.contains('acik')) {
      if (!document.getElementById('aiMesajlar').childElementCount) yukle();
      setTimeout(() => document.getElementById('aiInput').focus(), 200);
      document.getElementById('aiBadge').style.display = 'none';
    }
  };

  window.aiChatKapat = function() {
    document.getElementById('aiChatPanel').classList.remove('acik');
  };

  window.aiOneriGonder = function(btn) {
    const txt = btn.textContent.trim();
    document.getElementById('aiInput').value = txt;
    document.getElementById('aiOneriler').style.display = 'none';
    aiGonder();
  };

  window.aiKeyDown = function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      aiGonder();
    }
  };

  window.aiInputResize = function(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 90) + 'px';
  };

  window.aiGonder = async function() {
    if (bekliyor) return;
    const inp  = document.getElementById('aiInput');
    const metin = inp.value.trim();
    if (!metin) return;

    inp.value = '';
    inp.style.height = 'auto';
    document.getElementById('aiGonder').disabled = true;
    bekliyor = true;

    // Öneri kutularını gizle
    document.getElementById('aiOneriler').style.display = 'none';

    // Eğer geçmiş sıfırsa karşılama silindi; yükle
    if (!document.getElementById('aiMesajlar').childElementCount) ekleKarsilama();

    ekleBalon('kullanici', metin, true);
    ekleYazıyor();

    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          mesaj: metin,
          gecmis: gecmis.slice(0, -1) // son kayıt bu mesaj, API zaten alıyor
        }),
      });
      const veri = await res.json();
      kaldir('aiYazıyor');
      const yanit = veri.yanit || veri.hata || 'Yanıt alınamadı.';
      ekleBalon('asistan', yanit, true);
    } catch(err) {
      kaldir('aiYazıyor');
      ekleBalon('asistan', 'Bağlantı hatası. Lütfen tekrar deneyin.', false);
    }

    document.getElementById('aiGonder').disabled = false;
    bekliyor = false;
    document.getElementById('aiInput').focus();
  };

  // Panel dışına tıklanınca kapat
  document.addEventListener('click', function(e) {
    const panel = document.getElementById('aiChatPanel');
    const btn   = document.getElementById('aiChatBtn');
    if (panel.classList.contains('acik') &&
        !panel.contains(e.target) &&
        !btn.contains(e.target)) {
      aiChatKapat();
    }
  });

})();
</script>
