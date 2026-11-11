#!/usr/bin/env bash
set -e

echo "=== Deploying Automated Security System (Docker) ==="

# Check if .env exists
if [ ! -f .env ]; then
    echo "Error: .env file not found! Please create .env from .env.docker.example before deploying."
    exit 1
fi

# Build Docker image
echo "[1/4] Building Docker images..."
docker compose build --pull

# Start containers
echo "[2/4] Starting containers..."
docker compose up -d

# Wait for app container to be ready
echo "[3/4] Checking application status..."
docker compose ps

# Run database migrations and cache optimizations inside the container
echo "[4/4] Optimizing Laravel..."
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan optimize

echo "=== Deployment Successful! ==="
echo "Check logs anytime with: docker compose logs -f"
