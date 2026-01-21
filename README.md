# Digital-boiler

Smart heating system using **ESP32** for remote control, monitoring, and **energy consumption tracking** of an electric boiler.

## Overview
This project is a prototype IoT heating controller built around an ESP32. It reads multiple sensors (temperature, voltage, current, flow) and sends telemetry to a local web server where the data is stored in a MySQL database and visualized in a web dashboard. The system supports **AUTO** (thermostat) and **MANUAL** modes and can control the heater (SSR) and circulation pump.

## Features
- ESP32-based smart boiler control (AUTO / MANUAL)
- Real-time monitoring:
  - Water temperature (DS18B20)
  - AC voltage (ZMPT101B)
  - AC current (ACS712)
  - Water flow (YF-S201)
- Energy tracking (Wh/kWh calculation from V × I over time)
- Local web dashboard + MySQL storage (XAMPP)
- Remote commands via HTTP (polling) + telemetry via JSON HTTP POST

## Hardware Used
- ESP32 Dev Module
- DS18B20 (waterproof recommended) – temperature
- ZMPT101B – AC voltage sensor
- ACS712 – current sensor (with voltage divider if needed)
- YF-S201 – water flow sensor
- SSR (Solid State Relay) – boiler heater control
- Relay module – pump control
- 16x2 LCD

## Software Stack
- ESP32 (Arduino framework)
- PHP (backend endpoints)
- MySQL / MariaDB (database)
- XAMPP (Apache + MySQL) for local server
- HTML/CSS dashboard (web UI)

## Repository Structure
Digital-boiler/
├── database/ # MySQL schema (tables and relations)
├── esp32/ # ESP32 firmware (Arduino sketch)
├── web/ # PHP backend endpoints and web dashboard
├── LICENSE
└── README.md

## How It Works
- **ESP32** reads sensors and performs thermostat control locally.
- Every few seconds it **polls commands** from the server (AUTO/MANUAL, relay state, setpoint).
- It sends **telemetry** to the server as JSON via HTTP POST.
- The web server stores readings in MySQL and displays them in the dashboard.

## Setup (Local Server)
### 1) Start XAMPP
- Enable **Apache**
- Enable **MySQL**

### 2) Create the database
- Open phpMyAdmin
- Create a database named: `digital_boiler`
- Import the SQL file from:
  - `database/digital_boiler.sql`

### 3) Configure database connection
Inside `web/`, copy:
- `db.example.php` → `db.php`

Then edit `db.php` with your local credentials (host/user/password).

### 4) Place the web folder
Copy the `web/` folder into your XAMPP `htdocs/` directory, for example:
- `C:\xampp\htdocs\digital-boiler\`

Your project should be accessible at something like:
- `http://localhost/digital-boiler/`

## Setup (ESP32 Firmware)
1. Open the ESP32 code from the `esp32/` folder in Arduino IDE
2. Set your Wi-Fi credentials:
   - `WIFI_SSID`
   - `WIFI_PASSWORD`
3. Set your server base URL:
   - `SERVER_BASE` (your PC local IP + folder name)
4. Upload the code to the ESP32

> Note: Make sure your ESP32 and PC are on the same Wi-Fi network.

## API Endpoints (Web)
- **GET** `relay_get.php?device_uid=...`  
  Returns control mode (auto/manual), relay state, and target temperature.
- **POST** `Ingest.php` (JSON payload)  
  Receives telemetry readings and stores them in the database.

## Notes / Security
- Do **NOT** commit secrets (Wi-Fi passwords, DB passwords).
- Keep `db.php` local; use `db.example.php` for the repo.

## License
This project is licensed under the **MIT License**.

