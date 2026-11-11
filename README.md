# UM Merchandise Management System

## Project Overview

The **UM Merchandise Management System** is a web-based application developed for the **External Relations and International Affairs Office (ERIAO)** of the **University of Mindanao**. The system manages **UM Merch**, a collection of official university apparel and souvenirs that promote school pride and unity among UMians.

Launched as part of ERIAO’s expanding role, UM Merch gained popularity through the **#UMIANvibe** campaign and continues to thrive as a symbol of belonging for students, employees, alumni, and the global UM community.

---

## Company Background

The **External Relations and International Affairs Office (ERIAO)** initially served as the university’s hub for partnerships, promotions, and employee-related activities. In 2023, ERIAO expanded its initiatives by introducing **UM Merch**, integrating branding with modern merchandise management.

Under the guidance of **Mr. Jovanie Garcia, Information Assistant**, ERIAO continues to advance international relations while strengthening unity and school spirit through innovative digital solutions.

---

## Vision

To provide quality, affordable, and authentic University of Mindanao merchandise that fosters school pride and accessibility for the UM community.

---

## Mission

The UM Merchandise Store is committed to serving students, employees, and alumni by offering reliable products, transparent and efficient services, and by adapting modern management solutions.

---

## Project Goals

- Improve efficiency and accuracy of merchandise operations  
- Provide convenient access to UM merchandise  
- Ensure transparency in inventory and transaction records  
- Strengthen UM’s brand identity through modernization  

---

## Technology Stack

- **Backend:** Laravel  
- **Frontend:** React  
- **Styling:** Tailwind CSS  
- **SPA Framework:** Inertia.js  
- **Database:** MySQL (local), PostgreSQL on Neon (production)
- **Hosting:** Cloudflare Containers + Workers, uploads on Cloudflare R2

---

## System Requirements

- PHP 8.1 or higher  
- Composer  
- Node.js and npm  
- MySQL  
- Laravel CLI  

---

## How to Run the Project


```bash
### 1. Clone the Repositories
git clone https://github.com/yoshbatula/UMERCH3.0.git
cd UMERCH3.0

### 2. Install Backend Dependencies (Laravel)
composer install

### 3. Install Frontend Dependencies (React + Tailwind)
npm install

### 4. Install React Framework
npm install --save-dev @vitejs/plugin-react

### 5. Install Inertia (Server Side)
composer require inertiajs/inertia-laravel

### 6. Install Inertia (Client Side React)
npm install @inertiajs/react

### 7. Other Dependencies that needed for the system
npm install xlsx jspdf jspdf-autotable
npm install exceljs
npm install qrcode

### 8. Create and Configure Environment File
cp .env.example .env

### 9. Setting up the smtp in .env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your email here
MAIL_PASSWORD=your app password here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your email here
MAIL_FROM_NAME="UMERCH"

### 10. Generate Application Key
php artisan key:generate

### 11. Database Migrate
php artisan migrate

### 12. Development Server
composer run dev
```

---

## Deploying to Cloudflare

Production runs as a **Cloudflare Container** (FrankenPHP) behind a Worker, with
**Neon Postgres** for the database and **Cloudflare R2** for uploads.

The full walkthrough — prerequisites, secrets, first deploy, and first admin
user — is in [`DEPLOYMENT.md`](DEPLOYMENT.md). In short:

```powershell
npx wrangler login
npx wrangler secret put APP_KEY        # repeat for DB_URL, R2_*, RECAPTCHA_SECRET_KEY
npx wrangler deploy
```

Relevant files:

| Path | Purpose |
| --- | --- |
| `Dockerfile` | Multi-stage build: Vite assets, Composer vendor, FrankenPHP runtime |
| `docker/entrypoint.sh` | Waits for the database, runs migrations, caches config/views |
| `wrangler.jsonc` | Worker, container, Durable Object, vars and required secrets |
| `cloudflare/worker.ts` | Proxies requests to the single container instance |
| `.env.production.example` | Reference list of production environment values |
| `DEPLOYMENT.md` | Step-by-step deployment and operations guide |

> Local development keeps using MySQL from `.env`. Production values are supplied
> by Wrangler vars/secrets, not by a committed `.env` file.
