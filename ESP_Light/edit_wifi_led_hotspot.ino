#include <ESP8266WiFi.h>
#include <WiFiUdp.h>
#include <FastLED.h>
#include <EEPROM.h>

#define LED_PIN D2
#define NUM_LEDS 144
#define LED_TYPE WS2812B
#define COLOR_ORDER GRB

CRGB leds[NUM_LEDS];
uint8_t Data[NUM_LEDS * 3];
unsigned long lastDataTime = 0;
const unsigned long ledOffDelay = 2000;

// ---------- HOTSPOT CONFIG ----------
const char* apSSID = "d1 mini";
const char* apPassword = "12345678an";
unsigned long apStartTime = 0;
bool apActive = false;
const unsigned long apDuration = 2 * 60 * 1000;  // 2 دقیقه به میلی‌ثانیه

// ---------- WIFI LED STATUS ----------
#define WIFI_LED_INDEX 0
bool wifiConnecting = false;
bool wifiConnected = false;
unsigned long wifiBlinkMillis = 0;
const unsigned long wifiBlinkInterval = 50;

// ---------- DEFAULT WIFI ----------
const char* defaultSSID = "2.";
const char* defaultPassword = "12345678an";
const IPAddress defaultIP(192, 168, 43, 200);
const IPAddress defaultGateway(192, 168, 43, 254);
const IPAddress defaultSubnet(255, 255, 255, 0);

unsigned int localPort = 8266;
WiFiUDP udp;

// ---------- EEPROM ----------
#define EEPROM_SIZE 512
#define SSID_ADDR 0
#define PASS_ADDR 100
#define IP_ADDR 200
#define GW_ADDR 220
#define SUBNET_ADDR 240

// ---------- EEPROM helper ----------
String readStringFromEEPROM(int addr, int maxLen) {
  char data[maxLen + 1];
  for (int i = 0; i < maxLen; i++) data[i] = EEPROM.read(addr + i);
  data[maxLen] = 0;
  return String(data);
}

void writeStringToEEPROM(int addr, String str, int maxLen) {
  for (int i = 0; i < maxLen; i++) {
    if (i < str.length()) EEPROM.write(addr + i, str[i]);
    else EEPROM.write(addr + i, 0);
  }
  EEPROM.commit();
}

IPAddress strToIP(String str) {
  int parts[4] = { 0, 0, 0, 0 };
  sscanf(str.c_str(), "%d.%d.%d.%d", &parts[0], &parts[1], &parts[2], &parts[3]);
  return IPAddress(parts[0], parts[1], parts[2], parts[3]);
}

// ---------- Connect WIFI ----------
void connectToWiFi(String ssid, String pass, IPAddress ip, IPAddress gw, IPAddress sn) {
  WiFi.disconnect();
  WiFi.config(ip, gw, sn);

  Serial.println("------ WiFi Config Start ------");
  Serial.print("SSID: ");
  Serial.println(ssid);
  Serial.print("Password: ");
  Serial.println(pass);
  Serial.print("Static IP: ");
  Serial.println(ip);
  Serial.print("Gateway: ");
  Serial.println(gw);
  Serial.print("Subnet: ");
  Serial.println(sn);
  Serial.println("-------------------------------");

  WiFi.begin(ssid.c_str(), pass.c_str());
  wifiConnecting = true;
  wifiConnected = false;

  Serial.print("Connecting to WiFi: ");
  Serial.println(ssid);

  int retries = 0;
  while (WiFi.status() != WL_CONNECTED && retries < 50) {
    // LED چشمک
    if (millis() - wifiBlinkMillis > wifiBlinkInterval) {
      wifiBlinkMillis = millis();
      if (leds[WIFI_LED_INDEX] == CRGB::White)
        leds[WIFI_LED_INDEX] = CRGB::Black;
      else
        leds[WIFI_LED_INDEX] = CRGB::White;
      FastLED.show();
    }
    delay(100);
    Serial.print(".");
    retries++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n✅ Connected successfully!");
    Serial.print("ESP IP address: ");
    Serial.println(WiFi.localIP());
    leds[WIFI_LED_INDEX] = CRGB::Black;  // خاموش LED وضعیت
    FastLED.show();
    wifiConnected = true;
    wifiConnecting = false;
  } else {
    Serial.println("\n❌ Failed to connect.");
    leds[WIFI_LED_INDEX] = CRGB::White;  // ثابت روشن
    FastLED.show();
    wifiConnected = false;
    wifiConnecting = false;
  }
}

// ---------- Setup ----------
void setup() {
  Serial.begin(115200);
  EEPROM.begin(EEPROM_SIZE);

  FastLED.addLeds<LED_TYPE, LED_PIN, COLOR_ORDER>(leds, NUM_LEDS).setCorrection(TypicalLEDStrip);
  FastLED.setBrightness(255);
  fill_solid(leds, NUM_LEDS, CRGB::Black);
  FastLED.show();

  // خواندن تنظیمات WiFi ذخیره شده
  String storedSSID = readStringFromEEPROM(SSID_ADDR, 100);
  String storedPass = readStringFromEEPROM(PASS_ADDR, 100);
  String storedIP = readStringFromEEPROM(IP_ADDR, 20);
  String storedGW = readStringFromEEPROM(GW_ADDR, 20);
  String storedSN = readStringFromEEPROM(SUBNET_ADDR, 20);

  if (storedSSID.length() > 0) {
    Serial.println("Connecting to saved WiFi from EEPROM...");
    connectToWiFi(storedSSID, storedPass, strToIP(storedIP), strToIP(storedGW), strToIP(storedSN));
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("Failed, connecting to default WiFi...");
      connectToWiFi(defaultSSID, defaultPassword, defaultIP, defaultGateway, defaultSubnet);
    }
  } else {
    Serial.println("No stored WiFi, connecting to default WiFi...");
    connectToWiFi(defaultSSID, defaultPassword, defaultIP, defaultGateway, defaultSubnet);
  }

  // راه‌اندازی هات‌اسپات
  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(apSSID, apPassword);
  Serial.print("AP running. Connect to WiFi SSID: ");
  Serial.println(apSSID);
  Serial.print("AP IP address: ");
  Serial.println(WiFi.softAPIP());

  apStartTime = millis();
  apActive = true;

  udp.begin(localPort);
  Serial.printf("UDP server started at port %d\n", localPort);
}

// ---------- Loop ----------
void loop() {
  //------------------------------ afet 2m turn off hotspot ---------------
  if (apActive && millis() - apStartTime >= apDuration) {
    WiFi.softAPdisconnect(true);
    Serial.println("AP turned off after 2 minutes.");
    apActive = false;
  }
  
  //----------------------------- recive packet -----------------------------
  int packetSize = udp.parsePacket();
  if (packetSize) {s
    lastDataTime = millis();
    byte firstByte = udp.read();

    // ---- DEBUG: چاپ کل داده دریافتی ----
    char dbgBuf[512];
    dbgBuf[0] = firstByte;
    int dbgLen = udp.read(&dbgBuf[1], sizeof(dbgBuf) - 2);
    int totalLen = dbgLen + 1;
    dbgBuf[totalLen] = 0;
    Serial.print("Raw UDP packet: ");
    //Serial.println(dbgBuf);

    if (firstByte == 0xAA) {
      // ---------- LED DATA ----------
      int pixels_count = ((packetSize - 1) / 3);
      int chunk_count = NUM_LEDS / pixels_count;

      for (int i = 0; i < NUM_LEDS; i++) {  // LED شماره 0 برای وای فای محفوظ
        byte r = dbgBuf[1 + (int(i - 1) / chunk_count) * 3 + 0];
        byte g = dbgBuf[1 + (int(i - 1) / chunk_count) * 3 + 1];
        byte b = dbgBuf[1 + (int(i - 1) / chunk_count) * 3 + 2];
        leds[i] = CRGB(r, g, b);
      }
      FastLED.show();

    } else {
      // ---------- WIFI CONFIG ----------
      String msg = String(dbgBuf);
      Serial.print("Config message: ");
      Serial.println(msg);

      int sep1 = msg.indexOf(';');
      int sep2 = msg.indexOf(';', sep1 + 1);
      int sep3 = msg.indexOf(';', sep2 + 1);
      int sep4 = msg.indexOf(';', sep3 + 1);

      if (sep1 > 0 && sep2 > sep1 && sep3 > sep2 && sep4 > sep3) {
        String newSSID = msg.substring(0, sep1);
        String newPass = msg.substring(sep1 + 1, sep2);
        String newIP = msg.substring(sep2 + 1, sep3);
        String newGW = msg.substring(sep3 + 1, sep4);
        String newSN = msg.substring(sep4 + 1);

        Serial.println("=== New WiFi Config Parsed ===");
        Serial.print("SSID: ");
        Serial.println(newSSID);
        Serial.print("PASS: ");
        Serial.println(newPass);
        Serial.print("IP: ");
        Serial.println(newIP);
        Serial.print("GW: ");
        Serial.println(newGW);
        Serial.print("SN: ");
        Serial.println(newSN);

        writeStringToEEPROM(SSID_ADDR, newSSID, 100);
        writeStringToEEPROM(PASS_ADDR, newPass, 100);
        writeStringToEEPROM(IP_ADDR, newIP, 20);
        writeStringToEEPROM(GW_ADDR, newGW, 20);
        writeStringToEEPROM(SUBNET_ADDR, newSN, 20);

        connectToWiFi(newSSID, newPass, strToIP(newIP), strToIP(newGW), strToIP(newSN));
      }
    }
  }

  // ---------- خاموش کردن LED ها در صورت عدم دریافت داده ----------
  if (millis() - lastDataTime > ledOffDelay) {
    lastDataTime = millis();
    for (int i = 1; i < NUM_LEDS; i++) leds[i] = CRGB::Black;  // LED صفر وضعیت وای‌فای دست نخورده
    FastLED.show();
  }
}
