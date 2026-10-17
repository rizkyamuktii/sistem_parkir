# 🅿️ Sistem Monitoring Parkir Berbasis ESP32, RFID RC522 & LCD I2C 16x2

Aplikasi dashboard monitoring parkir pintar (*Smart Parking IoT*) berbasis **ESP32**, **RFID RC522**, dan **LCD I2C 16x2** dengan visualisasi denah slot 2D realtime, palang pintu otomatis (*servo barrier gate*), dan sistem tarif parkir.

---

## 📸 Fitur Utama Dashboard
1. **2D Realtime Parking Visualizer**:
   - Denah slot interaktif terbagi menjadi **Zona A (Mobil: 8 Slot)** dan **Zona B (Motor: 4 Slot)**.
   - Status visual dinamis: Hijau (*Tersedia / Available*) dan Merah (*Terisi / Occupied*).
   - Animasi kendaraan, plat nomor, nama pemilik, dan timer durasi parkir aktif.
2. **Virtual Hardware Monitor & Simulator**:
   - **Virtual LCD 16x2**: Menampilkan persis apa yang muncul pada layar fisik LCD I2C secara realtime.
   - **Virtual Barrier Gate & Dual LED**: Animasi palang servo membuka 90° dan indikator LED hijau/merah.
   - **RFID Card Simulator**: Pengujian Tap In / Tap Out langsung dari dashboard tanpa harus menunggu perakitan hardware.
3. **Audio Feedback (Web Audio API)**:
   - Efek suara konfirmasi saat kartu diterima (Chime).
   - Efek suara peringatan saat kartu tidak terdaftar atau slot penuh (Buzzer).
4. **Audit Activity Log Realtime**:
   - Pencatatan otomatis waktu masuk (*Tap In*), waktu keluar (*Tap Out*), durasi parkir, dan perhitungan biaya/tarif otomatis.
5. **Manajemen Kartu RFID**:
   - Pendaftaran kartu baru (UID, Pemilik, Plat Kendaraan, Jenis Mobil/Motor).
   - Blokir/aktifkan kartu dan hapus kartu.
6. **Auto Database Migration**:
   - Database dan tabel dibuat secara otomatis saat pertama kali diakses.

---

## 🔌 Skema Perkabelan (Wiring Diagram)

### ⚠️ PERINGATAN PENTING:
> **Modul RFID RC522 bekerja pada tegangan 3.3V!** Hubungkan pin `3.3V` RC522 ke pin `3V3` ESP32. **JANGAN hubungkan ke 5V / VIN** karena dapat membakar chip RFID!

| Modul | Pin Modul | Pin ESP32 | Keterangan |
| :--- | :--- | :--- | :--- |
| **RFID RC522** | **3.3V** | **3V3** | Tegangan 3.3V (Wajib!) |
| | RST | GPIO 4 | Reset Pin |
| | GND | GND | Ground |
| | MISO | GPIO 19 | SPI MISO |
| | MOSI | GPIO 23 | SPI MOSI |
| | SCK | GPIO 18 | SPI Clock |
| | SDA / SS | GPIO 5 | SPI Chip Select |
| **LCD I2C 16x2** | VCC | VIN / 5V | Power I2C Backpack |
| | GND | GND | Ground |
| | SDA | GPIO 21 | I2C Data |
| | SCL | GPIO 22 | I2C Clock |
| **Servo SG90** | Signal (Oranye) | GPIO 13 | PWM Palang Pintu |
| | VCC (Merah) | VIN / 5V | Power Servo |
| | GND (Coklat) | GND | Ground |
| **Buzzer** | Pin (+) | GPIO 12 | Suara Notifikasi |
| | Pin (-) | GND | Ground |
| **LED Hijau** | Anoda (+) | GPIO 14 | Akses Diterima (via resistor 220Ω) |
| **LED Merah** | Anoda (+) | GPIO 27 | Ditolak / Penuh (via resistor 220Ω) |

---

## 📁 Struktur Direktori Proyek

```text
c:\xampp\htdocs\sistem_parkir\
├── api/
│   ├── db.php                 # Koneksi PDO & Auto-Migration Database
│   ├── rfid_tap.php           # Endpoint Scan RFID (Tap In, Tap Out & Tarif)
│   ├── get_dashboard_data.php # Endpoint Data Live Slot, Ringkasan & Log
│   ├── heartbeat.php          # Ping ESP32 & Penyedia Teks Layar LCD Idle
│   └── cards.php              # CRUD Manajemen Kartu RFID
├── assets/
│   ├── css/
│   │   └── style.css          # Desain Dark Glassmorphism Modern
│   └── js/
│       └── app.js             # Controller Frontend, Polling, Audio & Virtual LCD
├── esp32/
│   └── esp32_parking_system.ino # Program Firmware Arduino untuk ESP32
├── database.sql               # Skema & Seed Data Awal MySQL
├── index.php                  # Halaman Utama Dashboard Monitoring
└── README.md                  # Dokumentasi Lengkap
```

---

## 🚀 Panduan Penggunaan & Instalasi

### 1. Menjalankan Web Dashboard (XAMPP)
1. Buka **XAMPP Control Panel**.
2. Pastikan service **Apache** dan **MySQL** dalam status **Running**.
3. Buka browser dan akses alamat:
   ```
   http://localhost/sistem_parkir/
   ```
   *Database `sistem_parkir` dan tabel-tabelnya akan otomatis terbuat secara instan.*

### 2. Mengunggah Kode ke ESP32 (Arduino IDE)
1. Buka Arduino IDE.
2. Pasang library berikut melalui **Sketch -> Include Library -> Manage Libraries**:
   - `MFRC522` by GithubCommunity
   - `LiquidCrystal I2C` by Frank de Brabander
   - `ESP32Servo` by Kevin Harrington
   - `ArduinoJson` (versi 6.x) by Benoit Blanchon
3. Buka file [esp32/esp32_parking_system.ino](file:///c:/xampp/htdocs/sistem_parkir/esp32/esp32_parking_system.ino).
4. Sesuaikan konfigurasi WiFi dan IP komputer Anda:
   ```cpp
   const char* ssid = "NAMA_WIFI_ANDA";
   const char* password = "PASSWORD_WIFI";
   const char* serverTapUrl = "http://192.168.1.100/sistem_parkir/api/rfid_tap.php";
   const char* serverHeartbeatUrl = "http://192.168.1.100/sistem_parkir/api/heartbeat.php";
   ```
   *(Untuk mengetahui IP komputer server lokal, buka Command Prompt dan ketik `ipconfig`)*.
5. Sambungkan ESP32 via kabel USB, pilih board **DOIT ESP32 DEVKIT V1**, lalu klik **Upload**.

---

## ⚡ Cara Kerja Sistem
1. **Kondisi Standby (Idle)**:
   - ESP32 mengambil status slot dari server melalui `heartbeat.php`.
   - LCD 16x2 menampilkan:
     ```text
     SMART PARKING
     SISA SLOT: 12/12
     ```
2. **Kendaraan Masuk (Tap In)**:
   - Pengendara menempelkan kartu RFID ke reader RC522.
   - ESP32 membaca UID dan mengirim ke `rfid_tap.php`.
   - Server memverifikasi kartu dan mengalokasikan slot (misal: Slot `A-01`).
   - LCD menampilkan:
     ```text
     MASUK: A-01
     B 1234 ABC
     ```
   - Buzzer berbunyi 1x, LED hijau menyala, dan motor servo mengangkat palang selama 3.5 detik.
3. **Kendaraan Keluar (Tap Out)**:
   - Pengendara menempelkan kartu RFID yang sama saat keluar.
   - Server menghitung durasi parkir dan tarif (Mobil Rp 4.000/jam, Motor Rp 2.000/jam, gratis 15 menit pertama).
   - Slot parkir dibebaskan kembali.
   - LCD menampilkan:
     ```text
     BYE B 1234 ABC
     BIAYA: Rp 4.000
     ```
   - Palang pintu terbuka otomatis.
4. **Kartu Ditolak / Parkir Penuh**:
   - Jika kartu tidak terdaftar atau slot penuh:
     ```text
     KARTU DITOLAK!
     TIDAK TERDAFTAR
     ```
   - Buzzer berbunyi 3x tanda peringatan, LED merah menyala, dan palang tetap tertutup.
