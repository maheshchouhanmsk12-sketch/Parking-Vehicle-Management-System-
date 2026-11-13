# 🚗 Vehicle Parking Management System

A modern, cloud-ready **Vehicle Parking Management System** built with **PHP 8.2 & Apache**, containerized via **Docker**, and engineered specifically for deployment on **Render** with **Aiven Cloud MySQL**.

---

## 📌 Features

- **PHP 8.x Fully Compatible**: Safe constructor property initializations, `mysqli_result` null checks, strict types handling, and zero deprecated function notices.
- **Aiven Cloud MySQL Ready**: Native SSL encryption (`MYSQLI_CLIENT_SSL` / `require_secure_transport=ON`), 5-digit custom ports (e.g., `26284`), and dynamic `.env` configuration.
- **Render Cloud Native**:
  - Base Image: `php:8.2-apache` with `mysqli`, `pdo_mysql`, `gd`, `zip`, `mod_rewrite`, `headers`.
  - Dynamic `$PORT` binding via `start.sh`.
  - **Reverse Proxy Fix**: `UseCanonicalName Off` and `UseCanonicalPhysicalPort Off` prevents internal port `:10000` leaks during redirects.
  - Smart trailing-slash rewrite rules in `.htaccess`.
- **1-Click Web Database Importer (`db_setup.php`)**:
  - Live SSL connection health check.
  - Automatically parses and imports `database/parking_db.sql` with foreign key safety (`SET FOREIGN_KEY_CHECKS=0`).
  - Verifies tables and default admin user credentials with 1 click.

---

## 🔐 Default Admin Login Credentials

| Role | Username | Password | Access URL |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin` | `admin123` | `/login.php` or `/` |

---

## 🛠️ Environment Variables (Render & Aiven)

Configure these environment variables in your Render Web Service dashboard or in a local `.env` file:

| Variable | Description | Example / Recommended Value |
| :--- | :--- | :--- |
| `DB_SERVER` | Aiven MySQL Hostname | `mysql-xxxx.aivencloud.com` |
| `DB_PORT` | Aiven MySQL 5-Digit Port | `26284` |
| `DB_USERNAME` | MySQL Username | `avnadmin` |
| `DB_PASSWORD` | Aiven Service Password | *(Your Aiven Password)* |
| `DB_NAME` | Database Name | `defaultdb` (or `parking_db`) |
| `DB_SSL` | Enforce SSL for Aiven Cloud | `true` |
| `PORT` | Web Server Listen Port | `10000` *(assigned automatically by Render)* |
| `BASE_URL` | Public Application URL | `https://your-service.onrender.com` *(optional)* |

> **Tip:** You can also supply the single Aiven Service URI as `DATABASE_URL` or `MYSQL_URL`:
> `mysql://avnadmin:your_password@mysql-xxxx.aivencloud.com:26284/defaultdb?ssl-mode=REQUIRED`

---

## 🚀 1-Click Database Setup (`db_setup.php`)

Once deployed (or when running locally), open:
```text
https://<your-render-url>.onrender.com/db_setup.php
```
1. Verify the connection status badge turns **Green (Connected)**.
2. If credentials are not set in environment variables, paste your **Aiven Service URI** or fill the form and click **Save .env**.
3. Click **1-Click Import Database** to auto-create all tables, seed vehicle categories, locations, and configure the default admin account.
4. Click **Proceed to Login** to start using the system immediately!

---

## 🌐 Deploying to Render

### Method 1: Connect GitHub Repository (Recommended)
1. Go to [Render Dashboard](https://dashboard.render.com/) and click **New +** > **Web Service**.
2. Connect your GitHub repository: `https://github.com/maheshchouhanmsk12-sketch/Parking-Vehicle-Management-System-`.
3. Select **Docker** environment (Render auto-detects `Dockerfile`).
4. In the **Environment Variables** section, add:
   - `DB_SERVER`: Your Aiven Hostname
   - `DB_PORT`: `26284` (or your Aiven Port)
   - `DB_USERNAME`: `avnadmin`
   - `DB_PASSWORD`: Your Aiven Password
   - `DB_NAME`: `defaultdb`
   - `DB_SSL`: `true`
5. Click **Create Web Service**.
6. Once deployed, visit `https://<your-app>.onrender.com/db_setup.php` to run the 1-click importer.

### Method 2: Render Blueprint (`render.yaml`)
1. In Render Dashboard, click **Blueprints** > **New Blueprint Instance**.
2. Select your repository. Render will automatically read `render.yaml`.
3. Provide the secret environment variables (`DB_SERVER`, `DB_PASSWORD`).
4. Click **Apply**.

---

## 💻 Running Locally

### Using PHP Built-in Server:
```bash
# Start server
php -S localhost:8000

# Open in browser
http://localhost:8000/db_setup.php
```

### Using Docker:
```bash
# Build Docker image
docker build -t parking-system .

# Run container
docker run -p 10000:10000 -e DB_SERVER="your-host" -e DB_PORT=26284 -e DB_USERNAME="avnadmin" -e DB_PASSWORD="password" -e DB_NAME="defaultdb" -e DB_SSL="true" parking-system
```

---

## 📁 Project Structure

```text
├── assets/                 # CSS, JavaScript, Vendor libraries, Images
├── database/
│   └── parking_db.sql      # Database dump file
├── admin_class.php         # Core business logic & database CRUD actions (PHP 8.2)
├── ajax.php                # AJAX router
├── category.php            # Vehicle categories management
├── db_connect.php          # SSL-enabled Aiven MySQL connection handler
├── db_setup.php            # Visual 1-Click web installer & health checker
├── Dockerfile              # Docker container specification (php:8.2-apache)
├── home.php                # Dashboard with live vehicle statistics
├── index.php               # Protected main application router
├── location.php            # Parking area location manager
├── login.php               # Admin authentication portal
├── manage_park.php         # Vehicle check-in / check-out manager
├── park_list.php           # Parked vehicles list
├── render.yaml             # Render Blueprint configuration
├── start.sh                # Container startup script with reverse proxy fix
├── users.php               # System user management
└── view_parked_details.php # Vehicle receipt & checkout details
```

---

## 🛡️ License & Original Backups
All original codebase files are safely backed up with `.original` extensions as per project requirements.

