<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sistem Monitoring Parkir ESP32 - Pusat Kontrol Akses Gerbang</title>
  <meta name="description" content="Dashboard monitoring sistem parkir cerdas berbasis ESP32, RFID RC522, dan LCD I2C 16x2.">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
</head>
<body>

  <!-- Top Navigation Bar -->
  <header class="topbar">
    <div class="topbar-container">
      <div class="brand-wrapper">
        <div class="brand-icon-box" style="padding:0; background:transparent; border:none; display:flex; align-items:center;">
          <img src="assets/img/favicon.svg" alt="Logo" width="36" height="36" style="border-radius:9px; box-shadow:0 2px 8px rgba(37,99,235,0.35);">
        </div>
        <div class="brand-details">
          <h1>Sistem Monitoring Parkir</h1>
          <p>Kontrol Gerbang & Alokasi Slot ESP32</p>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <nav class="nav-tabs" role="tablist">
        <button class="nav-tab-btn active" id="tabBtnMonitoring" onclick="switchTab('monitoring')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7"></rect>
            <rect x="14" y="3" width="7" height="7"></rect>
            <rect x="14" y="14" width="7" height="7"></rect>
            <rect x="3" y="14" width="7" height="7"></rect>
          </svg>
          Monitoring Slot
        </button>
        <button class="nav-tab-btn" id="tabBtnCards" onclick="switchTab('cards')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
            <line x1="2" y1="10" x2="22" y2="10"></line>
          </svg>
          Registrasi & Kartu RFID
        </button>
        <button class="nav-tab-btn" id="tabBtnWiring" onclick="switchTab('wiring')">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="4" y="4" width="16" height="16" rx="2"></rect>
            <rect x="9" y="9" width="6" height="6"></rect>
            <line x1="9" y1="1" x2="9" y2="4"></line>
            <line x1="15" y1="1" x2="15" y2="4"></line>
            <line x1="9" y1="20" x2="9" y2="23"></line>
            <line x1="15" y1="20" x2="15" y2="23"></line>
          </svg>
          Skema Hardware
        </button>
      </nav>

      <!-- Hardware Status Meta -->
      <div class="topbar-meta">
        <div class="status-pill">
          <span class="status-dot pulse" id="espStatusDot"></span>
          <span id="espStatusText">ESP32 Siap Sambung</span>
          <span style="color:var(--text-dim);">|</span>
          <span id="espIpText" style="font-family:var(--font-mono); color:var(--text-muted);">192.168.1.100</span>
        </div>

        <div class="clock-display" id="liveClock">--:--:-- WIB</div>

        <button class="btn btn-secondary" id="soundToggleBtn" onclick="toggleSound()">
          <span id="soundIcon">Audio Aktif</span>
        </button>
        <button class="btn btn-danger-outline" onclick="resetAllSlots()">Reset Slot</button>
      </div>
    </div>
  </header>

  <main class="main-wrapper">

    <!-- KPI SUMMARY ROW -->
    <section class="kpi-row" aria-label="Ringkasan Statistik">
      <div class="kpi-card">
        <div class="kpi-header">
          <span class="kpi-title">Total Kapasitas</span>
          <svg class="kpi-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
          </svg>
        </div>
        <div class="kpi-number" id="valTotalSlots">12</div>
        <div class="kpi-subtext">Slot Terintegrasi Sistem</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <span class="kpi-title">Slot Tersedia</span>
          <span class="status-dot" style="background-color:var(--emerald);"></span>
        </div>
        <div class="kpi-number" id="valAvailableSlots" style="color:#34d399;">12</div>
        <div class="kpi-subtext">Siap Digunakan</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <span class="kpi-title">Slot Terisi</span>
          <span class="status-dot" style="background-color:var(--rose);"></span>
        </div>
        <div class="kpi-number" id="valOccupiedSlots" style="color:#f87171;">0</div>
        <div class="kpi-subtext">Kendaraan Parkir Aktif</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <span class="kpi-title">Masuk Hari Ini</span>
          <svg class="kpi-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
            <polyline points="10 17 15 12 10 7"></polyline>
            <line x1="15" y1="12" x2="3" y2="12"></line>
          </svg>
        </div>
        <div class="kpi-number" id="valTodayIn">0</div>
        <div class="kpi-subtext">Transaksi Tap Masuk</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <span class="kpi-title">Pendapatan Parkir</span>
          <svg class="kpi-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="1" x2="12" y2="23"></line>
            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
          </svg>
        </div>
        <div class="kpi-number" id="valTodayRevenue" style="font-size:1.35rem; color:#34d399;">Rp 0</div>
        <div class="kpi-subtext"><span id="valTodayOut">0</span> Transaksi Selesai</div>
      </div>
    </section>

    <!-- TAB 1: MONITORING SLOT & GERBANG -->
    <div id="tabContentMonitoring" class="tab-content">
      <div class="content-grid">

        <!-- LEFT COLUMN: PARKING BAYS & AUDIT LOG -->
        <div class="left-column">

          <!-- 2D PARKING BAYS -->
          <div class="panel">
            <div class="panel-header">
              <h2 class="panel-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                  <path d="M7 15h10"></path>
                  <path d="M7 9h4a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2H7V9z"></path>
                </svg>
                Denah Status Slot Parkir
              </h2>
              <div style="font-size:0.75rem; color:var(--text-muted); display:flex; gap:0.75rem;">
                <span style="display:inline-flex; align-items:center; gap:0.35rem;">
                  <span class="status-dot" style="background-color:var(--emerald);"></span> Tersedia
                </span>
                <span style="display:inline-flex; align-items:center; gap:0.35rem;">
                  <span class="status-dot" style="background-color:var(--rose);"></span> Terisi
                </span>
              </div>
            </div>

            <div class="panel-body">
              <!-- ZONA A: MOBIL -->
              <div class="zone-divider">
                <span>ZONA A - KHUSUS MOBIL (8 SLOT)</span>
                <span style="font-family:var(--font-mono); font-size:0.7rem;">Tarif: Rp 4.000 / jam (Gratis 5 mnt pertama)</span>
              </div>
              <div class="parking-bays-grid" id="slotsMobilContainer">
                <div style="color:var(--text-dim); font-size:0.8rem;">Memuat status slot mobil...</div>
              </div>

              <!-- ZONA B: MOTOR -->
              <div class="zone-divider" style="margin-top:1.25rem;">
                <span>ZONA B - KHUSUS MOTOR (4 SLOT)</span>
                <span style="font-family:var(--font-mono); font-size:0.7rem;">Tarif: Rp 2.000 / jam (Gratis 5 mnt pertama)</span>
              </div>
              <div class="parking-bays-grid" id="slotsMotorContainer">
                <div style="color:var(--text-dim); font-size:0.8rem;">Memuat status slot motor...</div>
              </div>
            </div>
          </div>

          <!-- AUDIT LOG TABLE -->
          <div class="panel">
            <div class="panel-header">
              <h2 class="panel-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="16" y1="13" x2="8" y2="13"></line>
                  <line x1="16" y1="17" x2="8" y2="17"></line>
                  <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                Riwayat Aktivitas Kendaraan
              </h2>
              <span style="font-size:0.72rem; color:var(--text-dim);">Pembaruan Otomatis</span>
            </div>

            <div class="table-container">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Status</th>
                    <th>Waktu Masuk / Keluar</th>
                    <th>Plat Nomor</th>
                    <th>Pemilik</th>
                    <th>Tipe</th>
                    <th>Slot</th>
                    <th>Biaya Parkir</th>
                  </tr>
                </thead>
                <tbody id="logsTableBody">
                  <tr>
                    <td colspan="7" style="text-align:center; padding:1.5rem; color:var(--text-dim);">
                      Belum ada transaksi aktivitas parkir.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- RIGHT COLUMN: HARDWARE MONITOR & SIMULATION CONSOLE -->
        <div class="right-column">
          
          <div class="panel">
            <div class="panel-header">
              <h3 class="panel-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="4" y="4" width="16" height="16" rx="2"></rect>
                  <rect x="9" y="9" width="6" height="6"></rect>
                </svg>
                Konsol Perangkat Keras
              </h3>
              <span class="badge badge-in">ESP32 Online</span>
            </div>

            <div class="panel-body">
              
              <!-- 1. VIRTUAL LCD 16X2 SCREEN -->
              <div class="form-label" style="display:flex; justify-content:space-between; margin-bottom:0.4rem;">
                <span>TAMPILAN LCD I2C 16x2</span>
                <span style="font-family:var(--font-mono); font-size:0.68rem; color:var(--text-muted);">Alamat: 0x27</span>
              </div>
              
              <div class="lcd-bezel">
                <div class="lcd-screen-matrix">
                  <span class="lcd-row" id="lcdLine1">SMART PARKING   </span>
                  <span class="lcd-row" id="lcdLine2">SISA SLOT: 12/12</span>
                </div>
              </div>

              <!-- 2. GATE BARRIER & LED INDICATORS -->
              <div class="gate-status-box">
                <div class="gate-indicator">
                  <div class="gate-arm-graphic">
                    <div class="gate-arm-bar" id="gateBarrier"></div>
                  </div>
                  <div>
                    <div style="font-size:0.75rem; font-weight:600; color:var(--text-white);">Palang Pintu (Servo)</div>
                    <div style="font-size:0.68rem; color:var(--text-muted);" id="gateStatusText">Tertutup (0°)</div>
                  </div>
                </div>

                <div class="led-indicator-group">
                  <div class="led-indicator">
                    <div class="led-bulb green" id="vLedGreen"></div>
                    <span class="led-name">Hijau</span>
                  </div>
                  <div class="led-indicator">
                    <div class="led-bulb red" id="vLedRed"></div>
                    <span class="led-name">Merah</span>
                  </div>
                </div>
              </div>

              <!-- 3. SIMULASI SCAN RFID -->
              <div style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:0.9rem;">
                <div style="font-size:0.78rem; font-weight:600; color:var(--text-white); margin-bottom:0.25rem;">
                  Simulasi Pembaca RFID RC522
                </div>
                <div style="font-size:0.7rem; color:var(--text-muted); margin-bottom:0.75rem;">
                  Pilih kartu yang telah didaftarkan atau masukkan UID secara manual.
                </div>

                <!-- Select registered card -->
                <div class="form-group">
                  <label class="form-label">Pilih Kartu Terdaftar</label>
                  <select id="simCardSelect" class="form-select" onchange="onSimCardSelected(this.value)">
                    <option value="">-- Pilih Kartu Member --</option>
                  </select>
                </div>

                <div class="form-group">
                  <label class="form-label">Atau Ketik UID Kartu</label>
                  <input type="text" id="simCardUid" class="form-input" placeholder="Contoh: E3A19B2C" style="font-family:var(--font-mono); text-transform:uppercase;">
                </div>

                <button type="button" class="btn btn-primary" style="width:100%;" onclick="simulateRfidTap()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                  </svg>
                  Tap Kartu ke Reader
                </button>
              </div>

            </div>
          </div>

        </div>

      </div>
    </div>

    <!-- TAB 2: MANAJEMEN & REGISTRASI KARTU RFID -->
    <div id="tabContentCards" class="tab-content" style="display:none;">
      <div style="display:grid; grid-template-columns:360px 1fr; gap:1.5rem; align-items:start;">

        <!-- Form Tambah Kartu Baru -->
        <div class="panel">
          <div class="panel-header">
            <h3 class="panel-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="16"></line>
                <line x1="8" y1="12" x2="16" y2="12"></line>
              </svg>
              Daftarkan Kartu RFID Baru
            </h3>
          </div>
          <div class="panel-body">
            <form id="formAddCard">
              <div class="form-group">
                <label class="form-label">UID Kartu RFID</label>
                <input type="text" name="rfid_uid" id="inputCardUid" class="form-input" placeholder="Misal: 74F921B5" required style="font-family:var(--font-mono); text-transform:uppercase;">
                <div style="font-size:0.68rem; color:var(--text-dim); margin-top:0.25rem;">UID bisa diperoleh dari Serial Monitor Arduino saat kartu ditempelkan.</div>
              </div>

              <div class="form-group">
                <label class="form-label">Nama Pemilik Kendaraan</label>
                <input type="text" name="owner_name" class="form-input" placeholder="Nama Lengkap" required>
              </div>

              <div class="form-group">
                <label class="form-label">Nomor Plat Polisi</label>
                <input type="text" name="plate_number" class="form-input" placeholder="B 1234 ABC" required style="text-transform:uppercase;">
              </div>

              <div class="form-group">
                <label class="form-label">Jenis Kendaraan</label>
                <select name="vehicle_type" class="form-select">
                  <option value="Mobil">Mobil (Zona A)</option>
                  <option value="Motor">Motor (Zona B)</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label">Saldo Awal (Opsional)</label>
                <input type="number" name="balance" class="form-input" value="50000" step="5000">
              </div>

              <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.5rem;">
                Simpan Kartu ke Database
              </button>
            </form>
          </div>
        </div>

        <!-- Tabel Daftar Kartu Terdaftar -->
        <div class="panel">
          <div class="panel-header">
            <h3 class="panel-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                <line x1="2" y1="10" x2="22" y2="10"></line>
              </svg>
              Daftar Kartu Pengguna Terdaftar
            </h3>
            <span id="registeredCountBadge" class="badge badge-in">0 Kartu</span>
          </div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>UID RFID</th>
                  <th>Nama Pemilik</th>
                  <th>Plat Nomor</th>
                  <th>Jenis</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody id="cardsTableBody">
                <tr>
                  <td colspan="6" style="text-align:center; padding:1.5rem; color:var(--text-dim);">
                    Belum ada kartu RFID yang didaftarkan. Silakan daftarkan kartu melalui formulir di samping.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>

    <!-- TAB 3: PANDUAN HARDWARE & PINOUT -->
    <div id="tabContentWiring" class="tab-content" style="display:none;">
      <div class="panel">
        <div class="panel-header">
          <h3 class="panel-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="4" y="4" width="16" height="16" rx="2"></rect>
              <rect x="9" y="9" width="6" height="6"></rect>
            </svg>
            Tabel Pinout Rangkaian ESP32, RFID RC522 & LCD I2C 16x2
          </h3>
        </div>
        <div class="panel-body">
          <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:var(--radius-sm); padding:0.75rem 1rem; margin-bottom:1.25rem; font-size:0.82rem; color:#fca5a5;">
            <strong>Peringatan Tegangan Modul RC522:</strong> Pin 3.3V pada RFID RC522 wajib dihubungkan ke pin <strong>3V3</strong> ESP32. Jangan gunakan pin 5V/VIN agar modul tidak terbakar.
          </div>

          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Komponen</th>
                  <th>Pin Komponen</th>
                  <th>Pin ESP32</th>
                  <th>Fungsi / Keterangan</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td rowspan="7"><strong>RFID RC522</strong></td>
                  <td>3.3V</td>
                  <td><strong style="color:#ef4444;">3V3</strong></td>
                  <td>Catu daya 3.3 Volt</td>
                </tr>
                <tr>
                  <td>RST</td>
                  <td>GPIO 4</td>
                  <td>Reset Pin</td>
                </tr>
                <tr>
                  <td>GND</td>
                  <td>GND</td>
                  <td>Ground</td>
                </tr>
                <tr>
                  <td>MISO</td>
                  <td>GPIO 19</td>
                  <td>SPI MISO</td>
                </tr>
                <tr>
                  <td>MOSI</td>
                  <td>GPIO 23</td>
                  <td>SPI MOSI</td>
                </tr>
                <tr>
                  <td>SCK</td>
                  <td>GPIO 18</td>
                  <td>SPI Serial Clock</td>
                </tr>
                <tr>
                  <td>SDA (SS)</td>
                  <td>GPIO 5</td>
                  <td>SPI Chip Select</td>
                </tr>
                <tr>
                  <td rowspan="4"><strong>LCD I2C 16x2</strong></td>
                  <td>VCC</td>
                  <td>VIN / 5V</td>
                  <td>Power Modul I2C (PCF8574)</td>
                </tr>
                <tr>
                  <td>GND</td>
                  <td>GND</td>
                  <td>Ground</td>
                </tr>
                <tr>
                  <td>SDA</td>
                  <td>GPIO 21</td>
                  <td>I2C Data Bus</td>
                </tr>
                <tr>
                  <td>SCL</td>
                  <td>GPIO 22</td>
                  <td>I2C Clock Bus</td>
                </tr>
                <tr>
                  <td rowspan="2"><strong>Servo Motor</strong></td>
                  <td>Signal (Oranye)</td>
                  <td>GPIO 13</td>
                  <td>PWM Gerak Palang Pintu</td>
                </tr>
                <tr>
                  <td>VCC / GND</td>
                  <td>5V / GND</td>
                  <td>Power Motor Servo</td>
                </tr>
                <tr>
                  <td><strong>Buzzer</strong></td>
                  <td>Pin (+)</td>
                  <td>GPIO 12</td>
                  <td>Nada Notifikasi Tap</td>
                </tr>
                <tr>
                  <td><strong>LED Indikator</strong></td>
                  <td>Hijau / Merah</td>
                  <td>GPIO 14 / GPIO 27</td>
                  <td>Akses Diterima / Ditolak (resistor 220Ω)</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </main>

  <!-- Toast Notification Box -->
  <div class="toast-box" id="toastBox"></div>

  <!-- JavaScript Controller -->
  <script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
