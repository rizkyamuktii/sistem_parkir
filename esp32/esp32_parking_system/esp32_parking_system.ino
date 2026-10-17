#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ESP32Servo.h>
#include <ArduinoJson.h>

const char* ssid = "Sabar";
const char* password = "anaklanank";

const char* serverTapUrl = "http://192.168.1.3/sistem_parkir/api/rfid_tap.php";
const char* serverHeartbeatUrl = "http://192.168.1.3/sistem_parkir/api/heartbeat.php";

#define SS_PIN    5
#define RST_PIN   4
#define BUZZER_PIN 12
#define SERVO_PIN 13
#define LED_GREEN 14
#define LED_RED   27

MFRC522 mfrc522(SS_PIN, RST_PIN);
LiquidCrystal_I2C lcd(0x27, 16, 2);
Servo gateServo;

unsigned long lastHeartbeat = 0;
const unsigned long heartbeatInterval = 8000;
String idleLine1 = "SMART PARKING";
String idleLine2 = "SIAP TAP KARTU";

unsigned long lastCardTapMillis = 0;
String lastCardUid = "";
const unsigned long cardCooldown = 4000;

void beepSuccess() {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(120);
  digitalWrite(BUZZER_PIN, LOW);
}

void beepDenied() {
  for (int i = 0; i < 3; i++) {
    digitalWrite(BUZZER_PIN, HIGH);
    delay(100);
    digitalWrite(BUZZER_PIN, LOW);
    delay(80);
  }
}

void setLeds(bool green, bool red) {
  digitalWrite(LED_GREEN, green ? HIGH : LOW);
  digitalWrite(LED_RED, red ? HIGH : LOW);
}

void updateLcd(String line1, String line2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(line1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(line2.substring(0, 16));
}

void connectWiFi() {
  updateLcd("MENGHUBUNGKAN", "WIFI SISTEM...");
  WiFi.begin(ssid, password);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(500);
    digitalWrite(LED_RED, !digitalRead(LED_RED));
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    digitalWrite(LED_RED, LOW);
    digitalWrite(LED_GREEN, HIGH);
    delay(500);
    digitalWrite(LED_GREEN, LOW);
    
    updateLcd("WIFI TERHUBUNG!", WiFi.localIP().toString());
    delay(1500);
  } else {
    updateLcd("WIFI GAGAL!", "CEK SSID/PASS");
    beepDenied();
    delay(2000);
  }
}

void checkHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(serverHeartbeatUrl);
  http.setTimeout(3000);
  
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    StaticJsonDocument<256> doc;
    DeserializationError error = deserializeJson(doc, payload);
    
    if (!error) {
      idleLine1 = doc["lcd_line1"].as<String>();
      idleLine2 = doc["lcd_line2"].as<String>();
      updateLcd(idleLine1, idleLine2);
    }
  }
  http.end();
}

void openGate() {
  for (int pos = 0; pos <= 90; pos += 5) {
    gateServo.write(pos);
    delay(15);
  }
  
  delay(3500);
  
  for (int pos = 90; pos >= 0; pos -= 5) {
    gateServo.write(pos);
    delay(15);
  }
}

void processCard(String uid) {
  updateLcd("MEMPROSES...", "UID: " + uid);
  setLeds(true, true);

  if (WiFi.status() != WL_CONNECTED) {
    updateLcd("ERROR: NO WIFI", "TIDAK TERHUBUNG");
    beepDenied();
    setLeds(false, true);
    delay(2000);
    setLeds(false, false);
    updateLcd(idleLine1, idleLine2);
    return;
  }

  HTTPClient http;
  http.begin(serverTapUrl);
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(5000);

  StaticJsonDocument<128> reqDoc;
  reqDoc["uid"] = uid;
  reqDoc["ip"] = WiFi.localIP().toString();
  String jsonBody;
  serializeJson(reqDoc, jsonBody);

  int httpCode = http.POST(jsonBody);

  if (httpCode > 0) {
    String response = http.getString();
    StaticJsonDocument<512> resDoc;
    DeserializationError err = deserializeJson(resDoc, response);

    if (!err) {
      bool gateOpen = resDoc["gate_open"] | false;
      String line1 = resDoc["lcd_line1"] | "RESPON SERVER";
      String line2 = resDoc["lcd_line2"] | "";
      int beepCount = resDoc["beep"] | 1;

      updateLcd(line1, line2);

      if (gateOpen) {
        setLeds(true, false);
        beepSuccess();
        openGate();
        setLeds(false, false);
      } else {
        setLeds(false, true);
        beepDenied();
        delay(2500);
        setLeds(false, false);
      }
    } else {
      updateLcd("JSON ERROR", "GAGAL PARSE");
      beepDenied();
      delay(2000);
    }
  } else {
    updateLcd("SERVER ERROR!", "KODE: " + String(httpCode));
    beepDenied();
    delay(2500);
  }

  http.end();
  checkHeartbeat();
}

void setup() {
  Serial.begin(115200);
  delay(500);

  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);
  pinMode(LED_RED, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, LOW);

  ESP32PWM::allocateTimer(0);
  gateServo.setPeriodHertz(50);
  gateServo.attach(SERVO_PIN, 500, 2400);
  gateServo.write(0);

  Wire.begin(21, 22);
  lcd.init();
  lcd.backlight();
  updateLcd("SMART PARKING", "INISIALISASI...");

  SPI.begin(18, 19, 23, 5);
  mfrc522.PCD_Init();
  delay(100);

  connectWiFi();
  checkHeartbeat();
  lastHeartbeat = millis();
}

void loop() {
  if (millis() - lastHeartbeat >= heartbeatInterval) {
    lastHeartbeat = millis();
    checkHeartbeat();
  }

  if (!mfrc522.PICC_IsNewCardPresent()) {
    return;
  }

  if (!mfrc522.PICC_ReadCardSerial()) {
    return;
  }

  String uidString = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) {
      uidString += "0";
    }
    uidString += String(mfrc522.uid.uidByte[i], HEX);
  }
  uidString.toUpperCase();

  if (uidString == lastCardUid && (millis() - lastCardTapMillis < cardCooldown)) {
    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();
    return;
  }

  lastCardTapMillis = millis();
  lastCardUid = uidString;

  Serial.println("Kartu Terdeteksi UID: " + uidString);

  processCard(uidString);

  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();
  delay(600);
}
