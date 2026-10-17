/**
 * SISTEM MONITORING PARKIR ESP32 - CONTROLLER FRONTEND
 * Real-time polling, manajemen kartu RFID, dan simulasi hardware.
 */

const AppState = {
  summary: {},
  slots: [],
  logs: [],
  cards: [],
  esp32: {},
  soundEnabled: true,
  isProcessingTap: false,
  lastProcessedEventId: 0,
  pollTimer: null
};

// Audio Generator (Web Audio API)
class SoundFX {
  constructor() {
    this.ctx = null;
  }

  init() {
    if (!this.ctx) {
      const AudioContext = window.AudioContext || window.webkitAudioContext;
      if (AudioContext) this.ctx = new AudioContext();
    }
  }

  playSuccess() {
    if (!AppState.soundEnabled) return;
    this.init();
    if (!this.ctx) return;

    const now = this.ctx.currentTime;
    const osc = this.ctx.createOscillator();
    const gain = this.ctx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, now); // D5
    osc.frequency.setValueAtTime(880.00, now + 0.12); // A5

    gain.gain.setValueAtTime(0.12, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

    osc.connect(gain);
    gain.connect(this.ctx.destination);

    osc.start(now);
    osc.stop(now + 0.35);
  }

  playDenied() {
    if (!AppState.soundEnabled) return;
    this.init();
    if (!this.ctx) return;

    const now = this.ctx.currentTime;
    const osc = this.ctx.createOscillator();
    const gain = this.ctx.createGain();

    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(220, now);
    osc.frequency.setValueAtTime(160, now + 0.15);

    gain.gain.setValueAtTime(0.15, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

    osc.connect(gain);
    gain.connect(this.ctx.destination);

    osc.start(now);
    osc.stop(now + 0.35);
  }
}

const sounds = new SoundFX();

// ==========================================
// INITIALIZATION
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
  updateClock();
  setInterval(updateClock, 1000);

  fetchDashboardData();
  fetchCards();

  // Polling data setiap 1.2 detik (real-time responsive)
  AppState.pollTimer = setInterval(fetchDashboardData, 1200);

  // Form submit handler
  const cardForm = document.getElementById('formAddCard');
  if (cardForm) {
    cardForm.addEventListener('submit', handleAddCard);
  }
});

// Update Digital Clock
function updateClock() {
  const clockEl = document.getElementById('liveClock');
  if (clockEl) {
    const now = new Date();
    clockEl.textContent = now.toLocaleTimeString('id-ID', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    }) + ' WIB';
  }
}

// Tab Switching
function switchTab(tabName) {
  const tabs = {
    monitoring: { btn: 'tabBtnMonitoring', content: 'tabContentMonitoring' },
    cards: { btn: 'tabBtnCards', content: 'tabContentCards' },
    wiring: { btn: 'tabBtnWiring', content: 'tabContentWiring' }
  };

  Object.keys(tabs).forEach(key => {
    const btn = document.getElementById(tabs[key].btn);
    const content = document.getElementById(tabs[key].content);
    if (btn) btn.classList.remove('active');
    if (content) content.style.display = 'none';
  });

  const active = tabs[tabName];
  if (active) {
    const activeBtn = document.getElementById(active.btn);
    const activeContent = document.getElementById(active.content);
    if (activeBtn) activeBtn.classList.add('active');
    if (activeContent) activeContent.style.display = 'block';
  }
}

// Sound toggle
function toggleSound() {
  AppState.soundEnabled = !AppState.soundEnabled;
  const label = document.getElementById('soundIcon');
  if (label) {
    label.textContent = AppState.soundEnabled ? 'Audio Aktif' : 'Audio Senyap';
  }
  showToast(AppState.soundEnabled ? 'Audio notifikasi diaktifkan' : 'Audio notifikasi dibisukan');
}

// ==========================================
// FETCH DASHBOARD DATA
// ==========================================
async function fetchDashboardData() {
  try {
    const res = await fetch(`api/get_dashboard_data.php?_t=${Date.now()}`, {
      cache: 'no-store'
    });
    if (!res.ok) throw new Error('Network error');
    const data = await res.json();

    if (data.status === 'success') {
      AppState.summary = data.summary;
      AppState.slots = data.slots;
      AppState.logs = data.recent_logs;
      AppState.esp32 = data.esp32;

      renderSummary();
      renderSlotsGrid();
      renderLogsTable();
      renderHardwareStatus();

      // Sinkronisasi LCD 16x2 dan palang gerbang secara otomatis saat ada tap dari ESP32 fisik
      if (data.latest_event && data.latest_event.id) {
        if (data.latest_event.id !== AppState.lastProcessedEventId) {
          AppState.lastProcessedEventId = data.latest_event.id;
          updateVirtualLcd(data.latest_event.lcd_line1, data.latest_event.lcd_line2);
          triggerGateAnimation(true);
          sounds.playSuccess();
          const actionText = data.latest_event.status === 'IN' ? 'MASUK' : 'KELUAR';
          showToast(`[TAP ${actionText}] ${data.latest_event.owner_name} (${data.latest_event.plate_number}) - Slot ${data.latest_event.slot_code}`);
        }
      } else if (!AppState.isProcessingTap && data.lcd_idle) {
        updateVirtualLcd(data.lcd_idle.line1, data.lcd_idle.line2);
      }
    }
  } catch (err) {
    console.warn('Gagal memperbarui data dashboard:', err);
  }
}

// ==========================================
// RENDER KPI SUMMARY
// ==========================================
function renderSummary() {
  const s = AppState.summary;
  if (!s) return;

  const setVal = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };

  setVal('valTotalSlots', s.total_slots || 12);
  setVal('valAvailableSlots', s.available_slots || 0);
  setVal('valOccupiedSlots', s.occupied_slots || 0);
  setVal('valTodayIn', s.today_in || 0);
  setVal('valTodayOut', s.today_out || 0);
  setVal('valTodayRevenue', 'Rp ' + Number(s.today_revenue || 0).toLocaleString('id-ID'));
}

// ==========================================
// RENDER PARKING BAYS (SLOTS)
// ==========================================
function renderSlotsGrid() {
  const containerMobil = document.getElementById('slotsMobilContainer');
  const containerMotor = document.getElementById('slotsMotorContainer');

  if (!containerMobil || !containerMotor) return;

  let htmlMobil = '';
  let htmlMotor = '';

  AppState.slots.forEach(slot => {
    const isOccupied = slot.status === 'occupied';
    const isMotor = slot.slot_type === 'Motor';

    // Clean SVG Icons
    const carSvg = `
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="8" rx="2"></rect>
        <path d="M5 11l2-5h10l2 5"></path>
        <circle cx="7.5" cy="16.5" r="1.5"></circle>
        <circle cx="16.5" cy="16.5" r="1.5"></circle>
      </svg>
    `;

    const motorSvg = `
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="5" cy="16" r="3"></circle>
        <circle cx="19" cy="16" r="3"></circle>
        <path d="M12 17h4l3-7h-4"></path>
        <path d="M9 11l3-6h4"></path>
      </svg>
    `;

    const emptySvg = `
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="9"></circle>
        <path d="M10 15V9h2.8a1.8 1.8 0 0 1 1.8 1.8v0a1.8 1.8 0 0 1-1.8 1.8H10"></path>
      </svg>
    `;

    const icon = isOccupied ? (isMotor ? motorSvg : carSvg) : emptySvg;

    const bayHtml = `
      <div class="parking-bay ${isOccupied ? 'occupied' : 'available'}"
           onclick="handleSlotClick('${slot.slot_code}', '${slot.status}', '${slot.current_rfid_uid || ''}')"
           title="${isOccupied ? 'Klik untuk simulasi Tap Keluar' : 'Klik untuk simulasi Parkir'}">
        <div class="bay-top">
          <span class="bay-code">${slot.slot_code}</span>
          <span class="bay-status-badge">${isOccupied ? 'Terisi' : 'Kosong'}</span>
        </div>

        <div class="bay-content">
          <div class="bay-vehicle-icon">${icon}</div>
          ${isOccupied ? `
            <div><span class="bay-plate-number">${slot.plate_number || 'TERISI'}</span></div>
            <div class="bay-owner">${slot.owner_name || 'Member RFID'}</div>
          ` : `
            <div style="font-size:0.75rem; color:var(--text-dim); margin-top:0.25rem;">Tersedia</div>
          `}
        </div>

        <div class="bay-footer">
          ${isOccupied ? `
            <span>Durasi:</span>
            <span style="font-family:var(--font-mono); color:var(--text-white);">${slot.parked_minutes || 1} mnt</span>
          ` : `
            <span style="color:var(--emerald);">Siap Parkir</span>
            <span>&bull;</span>
          `}
        </div>
      </div>
    `;

    if (isMotor) {
      htmlMotor += bayHtml;
    } else {
      htmlMobil += bayHtml;
    }
  });

  containerMobil.innerHTML = htmlMobil || '<div style="color:var(--text-dim); font-size:0.8rem;">Tidak ada slot mobil</div>';
  containerMotor.innerHTML = htmlMotor || '<div style="color:var(--text-dim); font-size:0.8rem;">Tidak ada slot motor</div>';
}

// ==========================================
// RENDER RECENT AUDIT LOGS
// ==========================================
function renderLogsTable() {
  const tbody = document.getElementById('logsTableBody');
  if (!tbody) return;

  if (!AppState.logs || AppState.logs.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="7" style="text-align:center; padding:1.5rem; color:var(--text-dim);">
          Belum ada riwayat aktivitas parkir.
        </td>
      </tr>
    `;
    return;
  }

  let rows = '';
  AppState.logs.forEach(log => {
    const isIN = log.status === 'IN';
    const badge = isIN
      ? `<span class="badge badge-in">Masuk</span>`
      : `<span class="badge badge-out">Keluar</span>`;

    const time = isIN
      ? log.time_in
      : `${log.time_out} <small style="color:var(--text-dim);">(${log.duration_minutes || 0} mnt)</small>`;

    const fee = isIN
      ? '<span style="color:var(--text-dim);">-</span>'
      : `<strong style="color:#34d399; font-family:var(--font-mono);">Rp ${Number(log.fee || 0).toLocaleString('id-ID')}</strong>`;

    rows += `
      <tr>
        <td>${badge}</td>
        <td style="font-family:var(--font-mono); font-size:0.78rem;">${time}</td>
        <td><span class="bay-plate-number">${log.plate_number || '-'}</span></td>
        <td>${log.owner_name || '-'}</td>
        <td><span class="badge badge-type">${log.vehicle_type}</span></td>
        <td><strong style="font-family:var(--font-mono); color:var(--primary-light);">${log.slot_code || '-'}</strong></td>
        <td>${fee}</td>
      </tr>
    `;
  });

  tbody.innerHTML = rows;
}

// ==========================================
// RENDER ESP32 HARDWARE STATUS
// ==========================================
function renderHardwareStatus() {
  const esp = AppState.esp32;
  const dot = document.getElementById('espStatusDot');
  const text = document.getElementById('espStatusText');
  const ipText = document.getElementById('espIpText');

  if (dot && text) {
    if (esp && esp.is_online) {
      dot.className = 'status-dot pulse';
      text.textContent = 'ESP32 Online';
      text.style.color = '#34d399';
    } else {
      dot.className = 'status-dot offline';
      text.textContent = 'ESP32 Siap Sambung';
      text.style.color = 'var(--text-muted)';
    }
  }

  if (ipText && esp && esp.ip_address) {
    ipText.textContent = esp.ip_address;
  }
}

// ==========================================
// VIRTUAL LCD & SERVO GATE
// ==========================================
function updateVirtualLcd(line1, line2) {
  const l1 = document.getElementById('lcdLine1');
  const l2 = document.getElementById('lcdLine2');
  if (l1) l1.textContent = (line1 || '').padEnd(16, ' ').substring(0, 16);
  if (l2) l2.textContent = (line2 || '').padEnd(16, ' ').substring(0, 16);
}

function triggerGateAnimation(isOpen) {
  const barrier = document.getElementById('gateBarrier');
  const statusText = document.getElementById('gateStatusText');
  const ledGreen = document.getElementById('vLedGreen');
  const ledRed = document.getElementById('vLedRed');

  if (isOpen) {
    if (barrier) barrier.classList.add('open');
    if (statusText) statusText.textContent = 'Terbuka (90°)';
    if (ledGreen) ledGreen.classList.add('on');
    if (ledRed) ledRed.classList.remove('on');

    setTimeout(() => {
      if (barrier) barrier.classList.remove('open');
      if (statusText) statusText.textContent = 'Tertutup (0°)';
      if (ledGreen) ledGreen.classList.remove('on');
    }, 3800);
  } else {
    if (barrier) barrier.classList.remove('open');
    if (statusText) statusText.textContent = 'Akses Ditolak';
    if (ledRed) ledRed.classList.add('on');
    if (ledGreen) ledGreen.classList.remove('on');

    setTimeout(() => {
      if (ledRed) ledRed.classList.remove('on');
      if (statusText) statusText.textContent = 'Tertutup (0°)';
    }, 2500);
  }
}

// ==========================================
// RFID SIMULATION SCAN
// ==========================================
async function simulateRfidTap(uid) {
  if (AppState.isProcessingTap) return;
  AppState.isProcessingTap = true;

  if (!uid) {
    const input = document.getElementById('simCardUid');
    uid = input ? input.value.trim() : '';
  }

  if (!uid) {
    showToast('Silakan pilih atau ketikkan UID Kartu RFID.');
    AppState.isProcessingTap = false;
    return;
  }

  updateVirtualLcd('MEMPROSES KARTU', 'UID: ' + uid);

  try {
    const res = await fetch('api/rfid_tap.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uid: uid, ip: '127.0.0.1 (Simulator)' })
    });

    const result = await res.json();
    updateVirtualLcd(result.lcd_line1 || 'RESPON', result.lcd_line2 || '');

    if (result.gate_open) {
      sounds.playSuccess();
      triggerGateAnimation(true);

      if (result.action === 'TAP_IN') {
        showToast(`[MASUK] ${result.owner} (${result.plate}) diarahkan ke slot ${result.slot}`);
      } else if (result.action === 'TAP_OUT') {
        showToast(`[KELUAR] ${result.owner} (${result.plate}) selesai. Biaya: ${result.fee_formatted}`);
      }
    } else {
      sounds.playDenied();
      triggerGateAnimation(false);
      showToast(`Akses Ditolak: ${result.message}`);

      // Auto isi UID ke form pendaftaran jika kartu belum terdaftar
      const inputCardUid = document.getElementById('inputCardUid');
      if (inputCardUid && result.action === 'DENIED') {
        inputCardUid.value = uid;
      }
    }

    await fetchDashboardData();

  } catch (err) {
    console.error(err);
    showToast('Gagal memproses tap RFID ke server.');
  } finally {
    setTimeout(() => {
      AppState.isProcessingTap = false;
      const avail = AppState.summary.available_slots || 0;
      const total = AppState.summary.total_slots || 12;
      updateVirtualLcd('SMART PARKING', `SISA SLOT: ${String(avail).padStart(2, '0')}/${String(total).padStart(2, '0')}`);
    }, 4500);
  }
}

function onSimCardSelected(uid) {
  const input = document.getElementById('simCardUid');
  if (input) input.value = uid;
}

function handleSlotClick(slotCode, status, currentUid) {
  if (status === 'occupied' && currentUid) {
    if (confirm(`Kendaraan di slot ${slotCode} akan melakukan Tap Keluar. Proses sekarang?`)) {
      simulateRfidTap(currentUid);
    }
  } else {
    // Cari kartu terdaftar yang belum masuk
    const insideUids = AppState.slots.filter(s => s.status === 'occupied').map(s => s.current_rfid_uid);
    const candidate = AppState.cards.find(c => !insideUids.includes(c.rfid_uid) && c.status === 'active');

    if (candidate) {
      simulateRfidTap(candidate.rfid_uid);
    } else {
      showToast('Tidak ada kartu terdaftar yang sedang di luar. Silakan daftarkan kartu baru di tab Registrasi.');
    }
  }
}

// ==========================================
// CARD REGISTRATION & MANAGEMENT
// ==========================================
async function fetchCards() {
  try {
    const res = await fetch('api/cards.php');
    const json = await res.json();
    if (json.status === 'success') {
      AppState.cards = json.data;
      renderCardsTable();
      populateSimCardSelect();
    }
  } catch (err) {
    console.error('Gagal mengambil daftar kartu:', err);
  }
}

function populateSimCardSelect() {
  const select = document.getElementById('simCardSelect');
  if (!select) return;

  let options = '<option value="">-- Pilih Kartu Terdaftar --</option>';
  AppState.cards.forEach(card => {
    options += `<option value="${card.rfid_uid}">${card.owner_name} - ${card.plate_number} (${card.rfid_uid})</option>`;
  });

  select.innerHTML = options;
}

function renderCardsTable() {
  const tbody = document.getElementById('cardsTableBody');
  const countBadge = document.getElementById('registeredCountBadge');

  if (countBadge) {
    countBadge.textContent = `${AppState.cards.length} Kartu Terdaftar`;
  }

  if (!tbody) return;

  if (AppState.cards.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align:center; padding:1.5rem; color:var(--text-dim);">
          Belum ada kartu RFID yang didaftarkan. Silakan daftarkan kartu melalui formulir di samping.
        </td>
      </tr>
    `;
    return;
  }

  let rows = '';
  AppState.cards.forEach(card => {
    const isActive = card.status === 'active';
    rows += `
      <tr>
        <td><strong style="font-family:var(--font-mono); color:var(--primary-light);">${card.rfid_uid}</strong></td>
        <td>${card.owner_name}</td>
        <td><span class="bay-plate-number">${card.plate_number}</span></td>
        <td><span class="badge badge-type">${card.vehicle_type}</span></td>
        <td>
          <span class="badge ${isActive ? 'badge-in' : 'badge-out'}" style="cursor:pointer;" onclick="toggleCardStatus(${card.id})">
            ${isActive ? 'Aktif' : 'Ditangguhkan'}
          </span>
        </td>
        <td>
          <button class="btn btn-danger-outline" style="padding:0.25rem 0.5rem; font-size:0.72rem;" onclick="deleteCard(${card.id})">
            Hapus
          </button>
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = rows;
}

async function handleAddCard(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const data = Object.fromEntries(formData.entries());

  try {
    const res = await fetch('api/cards.php?action=create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    const result = await res.json();

    if (result.status === 'success') {
      showToast('Kartu RFID berhasil didaftarkan ke sistem.');
      form.reset();
      await fetchCards();
    } else {
      showToast(result.message || 'Gagal mendaftarkan kartu.');
    }
  } catch (err) {
    showToast('Terjadi kesalahan komunikasi dengan server.');
  }
}

async function toggleCardStatus(id) {
  try {
    const res = await fetch('api/cards.php?action=toggle_status', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    });
    const result = await res.json();
    if (result.status === 'success') {
      showToast('Status kartu diperbarui.');
      await fetchCards();
    }
  } catch (err) {}
}

async function deleteCard(id) {
  if (!confirm('Hapus kartu RFID ini dari sistem?')) return;
  try {
    const res = await fetch('api/cards.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    });
    const result = await res.json();
    if (result.status === 'success') {
      showToast('Kartu berhasil dihapus.');
      await fetchCards();
    }
  } catch (err) {}
}

async function resetAllSlots() {
  if (!confirm('Apakah Anda yakin ingin MENGOSONGKAN SELURUH SLOT PARKIR? Sesi parkir yang aktif akan diselesaikan.')) return;
  try {
    const res = await fetch('api/cards.php?action=reset_slots', { method: 'POST' });
    const result = await res.json();
    if (result.status === 'success') {
      showToast('Seluruh slot parkir telah berhasil dikosongkan.');
      await fetchDashboardData();
    }
  } catch (err) {
    showToast('Gagal mereset slot.');
  }
}

// Simple Toast Notification
function showToast(message) {
  const box = document.getElementById('toastBox');
  if (!box) return;

  const item = document.createElement('div');
  item.className = 'toast-item';
  item.innerHTML = `
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"></circle>
      <line x1="12" y1="16" x2="12" y2="12"></line>
      <line x1="12" y1="8" x2="12.01" y2="8"></line>
    </svg>
    <span>${message}</span>
  `;

  box.appendChild(item);

  setTimeout(() => {
    item.style.opacity = '0';
    item.style.transition = 'opacity 0.25s ease';
    setTimeout(() => item.remove(), 250);
  }, 4000);
}
