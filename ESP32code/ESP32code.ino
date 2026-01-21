// ===================== Kalldaja Digjitale (ESP32) =====================
// Sensors: DS18B20 (temperature), ZMPT101B (voltage), ACS712 (current), YF-S201 (flow)
// Actuators: SSR heater relay + pump relay
// UI: 16x2 LCD
// Server: HTTP GET for commands + HTTP POST JSON telemetry
// =====================================================================

#include <WiFi.h>
#include "esp_wifi.h"
#include "esp_log.h"
#include <HTTPClient.h>
#include <ArduinoJson.h>

#include <OneWire.h>
#include <DallasTemperature.h>

#include <ZMPT101B.h>

#include <LiquidCrystal.h>
#include <math.h>

// --------------------- WiFi ---------------------
static const char* WIFI_SSID     = ""; // WiFi name
static const char* WIFI_PASSWORD = ""; // WiFi password

// --------------------- Server ---------------------
static const char* SERVER_BASE = "http://192.168.***/Digital_Boiler"; // Your IP address and htdocs folder name
static const char* DEVICE_UID  = "ESP32-BOILER-01"; 

// Drop noisy ESP-IDF logs (wifi/phy/etc.)
static int dropLogs(const char* format, va_list args) {
  (void)format;
  (void)args;
  return 0;
}

// --------------------- LCD ---------------------
// NOTE: LiquidCrystal pins: rs, enable, d4, d5, d6, d7
LiquidCrystal lcd(23, 19, 18, 21, 22, 33);

// --------------------- DS18B20 ---------------------
static constexpr uint8_t ONE_WIRE_BUS = 25; // Use a 4.7k pull-up resistor from data to 3.3V
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature tempSensor(&oneWire);

// --------------------- ZMPT101B (AC voltage) ---------------------
static constexpr uint8_t PIN_ZMPT = 35;
static constexpr float   SENSITIVITY_ZMPT = 500.0f; // Calibrate to your board (depends on module)
ZMPT101B voltageSensor(PIN_ZMPT, 50.0f);            // 50Hz mains

// --------------------- ACS712 (AC current via ADC) ---------------------
// ESP32 ADC setup
static constexpr uint8_t PIN_ACS  = 34;
static constexpr float   ADC_VREF = 3.3f;
static constexpr int     ADC_BITS = 12;
static constexpr float   ADC_MAX  = (1 << ADC_BITS) - 1;

// ACS sensitivity (V/A) (typical values):
// 5A  -> 0.185, 20A -> 0.100, 30A -> 0.066
static constexpr float SENSITIVITY_ACS = 0.185f;

// Voltage divider (if your ACS output is divided before ADC)
// Example R1=22k (top), R2=10k (bottom)
static constexpr float R1_TOP_K      = 22.0f;
static constexpr float R2_BOTTOM_K   = 10.0f;
static constexpr float DIVIDER_RATIO = (R2_BOTTOM_K / (R1_TOP_K + R2_BOTTOM_K)); // ~0.3125

// --------------------- SSR Heater Relay ---------------------
static constexpr uint8_t RELAY_PIN  = 27;
static constexpr bool    RELAY_ACTIVE_LOW = false; // false = active HIGH, true = active LOW
static bool relayState = false;

// When we switch relay we skip one loop to avoid immediate ADC/LCD jitter
static volatile bool skipOneLoop = false;

// --------------------- Pump Relay ---------------------
static constexpr uint8_t PUMP_RELAY_PIN  = 26;
static constexpr bool    PUMP_ACTIVE_LOW = false;

// --------------------- Thermostat ---------------------
static constexpr float SET_DEFAULT  = 40.0f;
static constexpr float HYST         = 0.5f;
static constexpr float MIN_SETPOINT = 5.0f;
static constexpr float MAX_SETPOINT = 90.0f;

static float setpointUser      = SET_DEFAULT;
static float setpointEffective = SET_DEFAULT;

static bool     manualOverride = false;
static uint32_t lastRelaySwitchMs = 0;
static constexpr uint32_t MIN_SWITCH_MS = 2000;

// --------------------- Energy ---------------------
static float    energy_Wh    = 0.0f;
static uint32_t lastEnergyMs = 0;

// --------------------- Flow sensor (YF-S201) ---------------------
// Using uint8_t is fine for a GPIO pin. (Arduino "byte" is also uint8_t, just older style.)
static constexpr uint8_t FLOW_PIN = 14;

// "volatile" is required because this variable is written inside an interrupt.
static volatile uint32_t flowPulseCount = 0;

static float    flowRateLmin     = 0.0f;
static uint32_t flowLastCalcMs   = 0;

// --------------------- Last readings ---------------------
static float lastTempC = NAN;
static float lastVrms  = NAN;
static float lastIrms  = 0.0f;

// --------------------- Timers ---------------------
static uint32_t tWiMs      = 0;
static uint32_t tDbgMs     = 0;
static uint32_t tLastPost  = 0;
static uint32_t tLastPoll  = 0;

// =====================================================================
// Interrupts
// =====================================================================
void IRAM_ATTR flowPulseCounterISR() {
  flowPulseCount++;
}

// =====================================================================
// Relay helpers
// =====================================================================
static inline void relayWrite(bool on) {
  digitalWrite(RELAY_PIN, (RELAY_ACTIVE_LOW ? !on : on));
}

static inline void relayOn() {
  relayState = true;
  relayWrite(true);
  skipOneLoop = true;
}

static inline void relayOff() {
  relayState = false;
  relayWrite(false);
  skipOneLoop = true;
}

static inline void pumpWrite(bool on) {
  digitalWrite(PUMP_RELAY_PIN, (PUMP_ACTIVE_LOW ? !on : on));
}

static inline void pumpOn()  { pumpWrite(true);  }
static inline void pumpOff() { pumpWrite(false); }

// Turn pump off only when there is no load (current low) AND water is cold.
// This helps reduce heat loss when heater is not working and tank is cold.
static void updatePumpLogicByCurrent(float iRmsA, float tC) {
  if (isnan(tC) || isnan(iRmsA)) return;

  static constexpr float I_OFF = 0.5f;
  static constexpr float T_OFF = 30.0f;

  if (iRmsA < I_OFF && tC < T_OFF) pumpOff();
  else pumpOn();
}

// =====================================================================
// WiFi
// =====================================================================
static void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  WiFi.mode(WIFI_OFF);
  delay(150);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  Serial.print("Connecting to WiFi");
  const uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && (millis() - t0) < 20000) {
    delay(300);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Connected. IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("WiFi timeout.");
  }
}

// =====================================================================
// HTTP helpers
// =====================================================================
static bool http_post_json(const String& url, const String& json, String& resp, int& code) {
  WiFiClient client;
  HTTPClient http;

  http.setReuse(false);
  http.useHTTP10(true);
  http.setTimeout(5000);

  if (!http.begin(client, url)) {
    code = -1;
    resp = "";
    return false;
  }

  http.addHeader("Connection", "close");
  http.addHeader("Content-Type", "application/json");

  code = http.POST((uint8_t*)json.c_str(), json.length());
  resp = (code > 0) ? http.getString() : "";

  http.end();
  client.stop();
  return (code >= 200 && code < 300);
}

static bool http_get(const String& url, String& resp, int& code) {
  WiFiClient client;
  HTTPClient http;

  http.setReuse(false);
  http.useHTTP10(true);
  http.setTimeout(5000);

  if (!http.begin(client, url)) {
    code = -1;
    resp = "";
    return false;
  }

  http.addHeader("Connection", "close");

  code = http.GET();
  resp = (code > 0) ? http.getString() : "";

  http.end();
  client.stop();
  return (code > 0);
}

// =====================================================================
// JSON helpers
// =====================================================================
static bool jsonReadCommand(const String& payload, String& modeOut, int& stateOut, float& setpointOut) {
  StaticJsonDocument<512> doc;
  DeserializationError err = deserializeJson(doc, payload);
  if (err) return false;

  modeOut = (const char*)(doc["mode"] | "");
  stateOut = (int)(doc["state"] | -1);

  // Your server uses "t_on" for target temp
  if (doc.containsKey("t_on")) setpointOut = doc["t_on"].as<float>();
  else setpointOut = NAN;

  return true;
}

// =====================================================================
// ACS712 measurement
// =====================================================================
// Reads current RMS by sampling the ADC many times.
// We compute mean (offset) for the same window, then RMS around that mean.
// This is robust even if ACS "zero" drifts.
static float readACS712Irms(uint16_t samples = 1200, uint16_t us_delay = 120) {
  double mean = 0.0;

  for (uint16_t i = 0; i < samples; i++) {
    const int raw = analogRead(PIN_ACS);
    const double v_adc = (raw * ADC_VREF) / ADC_MAX;
    const double v_out = v_adc / DIVIDER_RATIO;
    mean += v_out;
    delayMicroseconds(us_delay);
  }
  mean /= samples;

  double sumSq = 0.0;
  for (uint16_t i = 0; i < samples; i++) {
    const int raw = analogRead(PIN_ACS);
    const double v_adc = (raw * ADC_VREF) / ADC_MAX;
    const double v_out = v_adc / DIVIDER_RATIO;
    const double v_ac  = v_out - mean;
    sumSq += v_ac * v_ac;
    delayMicroseconds(us_delay);
  }

  const double rms_volts = sqrt(sumSq / samples);
  float i_rms = (float)(rms_volts / SENSITIVITY_ACS);

  // Clamp noise floor
  if (!isfinite(i_rms) || i_rms < 0) i_rms = 0.0f;
  if (i_rms < 0.10f) i_rms = 0.0f;

  return i_rms;
}

// =====================================================================
// Thermostat
// =====================================================================
static inline void setSetpoint(float sp) {
  setpointUser = sp;
  setpointEffective = constrain(sp, MIN_SETPOINT, MAX_SETPOINT);
}

static void thermostatControl(float tC) {
  if (manualOverride) return;
  if (!isfinite(tC)) return;

  const uint32_t now = millis();
  if (now - lastRelaySwitchMs < MIN_SWITCH_MS) return;

  // ON only when below (SET - HYST)
  if (!relayState && tC <= (setpointEffective - HYST)) {
    relayOn();
    lastRelaySwitchMs = now;
    Serial.println("[THERM] Relay ON");
    return;
  }

  // OFF when reaching SET (or above)
  if (relayState && tC >= setpointEffective) {
    relayOff();
    lastRelaySwitchMs = now;
    Serial.println("[THERM] Relay OFF");
    return;
  }
}

// =====================================================================
// Setup
// =====================================================================
void setup() {
  Serial.begin(9600);

  // Disable ESP-IDF logs
  Serial.setDebugOutput(false);
  esp_log_set_vprintf(dropLogs);

  // WiFi init (reduce weird power-save behavior)
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);

  // Optional: force only 802.11 b/g (no n) if your AP is picky
  esp_wifi_set_protocol(WIFI_IF_STA, WIFI_PROTOCOL_11B | WIFI_PROTOCOL_11G);

  connectWiFi();

  // Sensors init
  tempSensor.begin();
  tempSensor.setWaitForConversion(false);

  analogReadResolution(ADC_BITS);
  analogSetPinAttenuation(PIN_ACS,  ADC_11db);
  analogSetPinAttenuation(PIN_ZMPT, ADC_11db);

  voltageSensor.setSensitivity(SENSITIVITY_ZMPT);

  // Relays
  pinMode(RELAY_PIN, OUTPUT);
  relayOff();

  pinMode(PUMP_RELAY_PIN, OUTPUT);
  pumpOff();

  // Flow sensor interrupt
  pinMode(FLOW_PIN, INPUT_PULLUP);
  attachInterrupt(digitalPinToInterrupt(FLOW_PIN), flowPulseCounterISR, FALLING);

  // LCD
  lcd.begin(16, 2);
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("ESP32 Kalldaja");
  lcd.setCursor(0, 1);
  lcd.print("Starting...");
  delay(900);
  lcd.clear();

  flowLastCalcMs = millis();
  lastEnergyMs   = millis();
}

// =====================================================================
// Loop
// =====================================================================
void loop() {
  // WiFi reconnect: try every 3 seconds if disconnected
  if (WiFi.status() != WL_CONNECTED && (millis() - tWiMs) > 3000) {
    tWiMs = millis();
    connectWiFi();
  }

  // Skip one loop after relay switching (helps stability)
  if (skipOneLoop) {
    skipOneLoop = false;
    delay(10);
    return;
  }

  // --------------------- Temperature (non-blocking style) ---------------------
  static uint32_t tTempReqMs = 0;
  static bool     tempRequested = false;

  if (!tempRequested && (millis() - tTempReqMs) >= 1000) {
    tempSensor.requestTemperatures();
    tempRequested = true;
    tTempReqMs = millis();
  }

  if (tempRequested && (millis() - tTempReqMs) >= 800) {
    const float tC = tempSensor.getTempCByIndex(0);
    const bool valid =
      isfinite(tC) &&
      tC > -55.0f && tC < 125.0f &&
      fabsf(tC - 85.0f) > 0.001f; // DS18B20 sometimes returns 85C when not ready

    if (valid) lastTempC = tC;
    tempRequested = false;
  }

  // --------------------- Voltage (ZMPT101B) ---------------------
  lastVrms = voltageSensor.getRmsVoltage();
  if (!isfinite(lastVrms) || lastVrms < 0) lastVrms = NAN;

  // --------------------- Current (ACS712) ---------------------
  lastIrms = readACS712Irms();

  // --------------------- Energy ---------------------
  const uint32_t nowE = millis();
  float dt_sec = (nowE - lastEnergyMs) / 1000.0f;
  if (dt_sec < 0) dt_sec = 0;
  lastEnergyMs = nowE;

  const float vForPower = isfinite(lastVrms) ? lastVrms : 0.0f;
  const float power_W   = vForPower * lastIrms;
  energy_Wh += power_W * (dt_sec / 3600.0f);

  // --------------------- Flow rate (every 1 second) ---------------------
  const uint32_t nowMs = millis();
  if ((nowMs - flowLastCalcMs) > 1000) {
    detachInterrupt(digitalPinToInterrupt(FLOW_PIN));

    uint32_t dt = nowMs - flowLastCalcMs;
    if (dt == 0) dt = 1;

    // Classic YF-S201 formula: frequency(Hz) = 7.5 * Q(L/min)
    // pulses/sec = flowPulseCount / (dt_ms/1000)
    flowRateLmin = (1000.0f / (float)dt) * (float)flowPulseCount / 7.5f;

    flowLastCalcMs = nowMs;
    flowPulseCount = 0;

    attachInterrupt(digitalPinToInterrupt(FLOW_PIN), flowPulseCounterISR, FALLING);
  }

  // --------------------- Control logic ---------------------
  thermostatControl(lastTempC);
  updatePumpLogicByCurrent(lastIrms, lastTempC);

  // --------------------- LCD update (every 1 second) ---------------------
  static uint32_t lastLcdMs = 0;
  if ((millis() - lastLcdMs) > 1000) {
    lastLcdMs = millis();

    const float tempToShow = isfinite(lastTempC) ? lastTempC : 0.0f;

    lcd.setCursor(0, 0);
    lcd.print("Temp: ");
    lcd.print(tempToShow, 1);
    lcd.print((char)223);
    lcd.print("C   ");

    lcd.setCursor(0, 1);
    lcd.print("SET:  ");
    lcd.print(setpointUser, 1);
    lcd.print((char)223);
    lcd.print("C   ");
  }

  // --------------------- Serial debug (every 2 seconds) ---------------------
  if ((millis() - tDbgMs) >= 2000) {
    tDbgMs = millis();
    Serial.printf(
      "T=%.2fC | I=%.3fA | V=%.1fV | P=%.0fW | E=%.3fWh | Flow=%.3fL/min | Relay=%s | Mode=%s | SET=%.2f\n",
      isfinite(lastTempC) ? lastTempC : 0.0f,
      lastIrms,
      isfinite(lastVrms) ? lastVrms : 0.0f,
      power_W,
      energy_Wh,
      flowRateLmin,
      relayState ? "ON" : "OFF",
      manualOverride ? "MANUAL" : "AUTO",
      setpointUser
    );
  }

  const uint32_t now = millis();

  // --------------------- Poll commands (every 5 seconds) ---------------------
  if (WiFi.status() == WL_CONNECTED && (now - tLastPoll) >= 5000) {
    tLastPoll = now;

    const String url = String(SERVER_BASE) + "/relay_get.php?device_uid=" + DEVICE_UID;

    String resp;
    int code = 0;

    if (http_get(url, resp, code) && code == 200) {
      String mode;
      int state = -1;
      float newSet = NAN;

      if (jsonReadCommand(resp, mode, state, newSet)) {
        if (isfinite(newSet) && newSet > -50 && newSet < 150) {
          setSetpoint(newSet);
        }

        if (mode == "manual") {
          manualOverride = true;

          if ((state == 0 || state == 1) && (millis() - lastRelaySwitchMs > MIN_SWITCH_MS)) {
            if (state == 1 && !relayState) {
              relayOn();
              lastRelaySwitchMs = millis();
              Serial.println("[CMD] Relay ON");
            } else if (state == 0 && relayState) {
              relayOff();
              lastRelaySwitchMs = millis();
              Serial.println("[CMD] Relay OFF");
            }
          }
        } else if (mode == "auto") {
          manualOverride = false;
        }
      }
    }
  }

  // --------------------- Telemetry POST (every 1 minute) ---------------------
  if (WiFi.status() == WL_CONNECTED && (now - tLastPost) >= 60000) {
    tLastPost = now;

    // Build JSON safely with ArduinoJson (avoids String-concat bugs and escaping issues)
    StaticJsonDocument<512> doc;
    doc["device_uid"] = DEVICE_UID;

    JsonArray readings = doc.createNestedArray("readings");

    {
      JsonObject r = readings.createNestedObject();
      r["sensor_uid"] = "temp-1";
      r["type"] = "temperature";
      r["unit"] = "C";
      r["value"] = isfinite(lastTempC) ? lastTempC : 0.0f;
    }
    {
      JsonObject r = readings.createNestedObject();
      r["sensor_uid"] = "acs-1";
      r["type"] = "current";
      r["unit"] = "A";
      r["value"] = lastIrms;
    }
    {
      JsonObject r = readings.createNestedObject();
      r["sensor_uid"] = "zmpt-1";
      r["type"] = "voltage";
      r["unit"] = "V";
      r["value"] = isfinite(lastVrms) ? lastVrms : 0.0f;
    }
    {
      JsonObject r = readings.createNestedObject();
      r["sensor_uid"] = "flow-1";
      r["type"] = "flow";
      r["unit"] = "L_min";
      r["value"] = flowRateLmin;
    }

    doc["relay_state"] = relayState;

    String json;
    serializeJson(doc, json);

    const String url = String(SERVER_BASE) + "/Ingest.php";
    String resp;
    int code = 0;
    http_post_json(url, json, resp, code);
  }

  delay(5);
}
