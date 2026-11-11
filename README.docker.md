# Panduan Hosting Docker di VPS (Amazon Linux / Ubuntu)

Panduan ini disiapkan khusus untuk mempermudah hosting dan deployment sistem Laravel **Automated Security System** di VPS (termasuk Amazon Linux 2023 / AL2 dan Ubuntu / Debian) menggunakan Docker dan Docker Compose.

---

## 1. Mengapa Coolify Gagal di Amazon Linux?

Coolify menggunakan skrip instalasi berbasis Debian/Ubuntu (`apt-get`, `systemd-resolved`, dan konfigurasi kernel tertentu). Amazon Linux 2023 (AL2023) dan Amazon Linux 2 (AL2) menggunakan arsitektur Fedora/RHEL dengan package manager `dnf`/`yum` serta konfigurasi firewall yang berbeda, sehingga skrip otomatisasi seperti Coolify sering kali ditolak (*unsupported OS*).

Solusi paling stabil, efisien, dan ringan untuk Amazon Linux adalah menggunakan **Docker & Docker Compose** secara langsung.

---

## 2. Persiapan Docker di Amazon Linux VPS

Jalankan perintah berikut di VPS Amazon Linux Anda via SSH:

### A. Untuk Amazon Linux 2023 (AL2023)
```bash
# Update sistem
sudo dnf update -y

# Install Docker & Docker Compose Plugin
sudo dnf install -y docker docker-compose-plugin git

# Jalankan service Docker dan aktifkan saat boot
sudo systemctl enable --now docker

# Berikan izin ke user Anda (ec2-user) agar bisa menjalankan docker tanpa sudo
sudo usermod -aG docker $USER
```

### B. Untuk Amazon Linux 2 (AL2)
```bash
# Update sistem
sudo yum update -y

# Install Docker via amazon-linux-extras
sudo amazon-linux-extras install docker -y
sudo yum install -y git

# Jalankan service Docker
sudo systemctl enable --now docker
sudo usermod -aG docker ec2-user

# Install Docker Compose Standalone
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
sudo ln -s /usr/local/bin/docker-compose /usr/bin/docker-compose
```

> **Penting**: Setelah menjalankan `usermod`, lakukan relogin SSH (`exit` lalu login kembali) agar izin grup `docker` aktif.

---

## 3. Langkah Deployment Proyek ke VPS

### Langkah 1: Clone atau Upload Project ke VPS
```bash
# Contoh clone repo atau letakkan folder project di VPS:
cd ~
git clone <URL_REPO_ANDA> automated-security-system
cd automated-security-system
```

### Langkah 2: Buat File `.env`
Salin template konfigurasi Docker yang sudah disediakan:
```bash
cp .env.docker.example .env
```
Lalu edit file `.env`:
```bash
nano .env
```
Pastikan mengisi:
- `APP_KEY`: Jika belum ada, Anda bisa membuatnya setelah build (atau isi dari project lokal).
- `APP_URL`: Masukkan IP VPS atau domain Anda (misal `http://1.2.3.4` atau `https://security.example.com`).
- `DB_PASSWORD` & `DB_ROOT_PASSWORD`: Buat kata sandi database yang aman.
- `GEMINI_API_KEY`: Masukkan API Key Gemini untuk fitur analisis insiden keamanan AI.

### Langkah 3: Jalankan Build dan Jalankan Container
Cukup jalankan satu perintah:
```bash
docker compose up -d --build
```
Atau gunakan skrip deploy otomatis:
```bash
./deploy.sh
```

### Langkah 4: Generate Application Key (Hanya saat pertama kali jika kosong)
Jika `APP_KEY` di `.env` masih kosong:
```bash
docker compose exec app php artisan key:generate --show
```
Salin key yang dihasilkan ke dalam `.env`, lalu restart app:
```bash
docker compose restart app
```

---

## 4. Arsitektur Container Docker

Image Docker ini dirancang **All-in-One + Microservice Ready**:
1. **`app` Container**:
   - **Nginx (Alpine/Debian)**: Web server cepat melayani static assets dan reverse proxy ke PHP-FPM.
   - **PHP 8.4-FPM**: Dilengkapi ekstensi `pdo_mysql`, `bcmath`, `gmp` (Passkeys/WebAuthn), `pcntl`, `opcache`, `redis`, dll.
   - **Queue Worker**: Dijalankan otomatis oleh Supervisor di latar belakang untuk memproses `AnalyzeIncidentJob` secara asynchronous.
   - **Scheduler (Cron)**: Dijalankan otomatis oleh Supervisor via `php artisan schedule:work` untuk polling insiden AI (`incidents:poll-analysis`) setiap interval yang ditentukan.
   - **Auto-Migrate & Cache**: Saat pertama kali start, container otomatis menjalankan migrasi database dan caching konfigurasi.
2. **`db` Container**:
   - **MariaDB 11**: Database relasional dengan persistent volume (`db_data`) sehingga data Anda aman dan tersimpan permanen di VPS.

---

## 5. Menghubungkan Domain & SSL (HTTPS)

Jika Anda ingin menggunakan domain Anda sendiri dengan HTTPS (Let's Encrypt / Certbot) di VPS:

### Opsi A: Nginx di Host VPS (Direkomendasikan)
1. Ubah `APP_PORT=8080` di `.env` agar port 80 & 443 host bebas untuk Certbot:
   ```env
   APP_PORT=8080
   ```
   Lalu `docker compose up -d`.

2. Install Nginx & Certbot di Host VPS:
   ```bash
   sudo dnf install -y nginx certbot python3-certbot-nginx
   sudo systemctl enable --now nginx
   ```

3. Konfigurasi Nginx Host (`/etc/nginx/conf.d/security.conf`):
   ```nginx
   server {
       server_name security.example.com;

       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ```

4. Aktifkan SSL Gratis:
   ```bash
   sudo certbot --nginx -d security.example.com
   ```

### Opsi B: Cloudflare Tunnel (Tanpa perlu buka port / firewall)
Gunakan `cloudflared` untuk menghubungkan port 80 container langsung ke domain Cloudflare Anda secara aman tanpa membuka port publik di AWS Security Group.

---

## 6. Perintah Operasional Harian

- **Melihat status container**:
  ```bash
  docker compose ps
  ```
- **Melihat log aplikasi, antrean (queue), dan scheduler secara real-time**:
  ```bash
  docker compose logs -f app
  ```
- **Menjalankan Artisan command**:
  ```bash
  docker compose exec app php artisan tinker
  docker compose exec app php artisan route:list
  ```
- **Menjalankan migrasi manual jika ada perubahan schema baru**:
  ```bash
  docker compose exec app php artisan migrate --force
  ```
- **Restart container**:
  ```bash
  docker compose restart app
  ```
- **Menghentikan container**:
  ```bash
  docker compose down
  ```
