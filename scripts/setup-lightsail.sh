#!/usr/bin/env bash
# ==============================================================================
# Script de Preparación y Optimización para AWS Lightsail (Ubuntu 22.04 / 24.04)
# Proyecto: GRANDPRIX
# ==============================================================================

set -euo pipefail

echo "========================================================"
echo " Instando dependencias y optimizando VPS Lightsail (2GB)"
echo "========================================================"

# 1. Actualizar el sistema
echo "[1/5] Actualizando paquetes del sistema..."
sudo apt update && sudo apt upgrade -y
sudo apt install -y curl wget git ufw htop ca-certificates gnupg lsb-release

# 2. Configurar 2GB de Memoria SWAP (Vital para VPS de 2GB)
echo "[2/5] Configurando 2GB de Swap para evitar OOM..."
if [ ! -f /swapfile ]; then
    sudo fallocate -l 2G /swapfile || sudo dd if=/dev/zero of=/swapfile bs=1M count=2048
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile
    sudo swapon /swapfile
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
    echo "Swap de 2GB activada correctamente."
else
    echo "El archivo /swapfile ya existe."
fi

# Ajustar parámetros del Kernel para Swap y memoria
sudo sysctl vm.swappiness=10
sudo sysctl vm.vfs_cache_pressure=50
sudo sed -i '/vm.swappiness/d' /etc/sysctl.conf
sudo sed -i '/vm.vfs_cache_pressure/d' /etc/sysctl.conf
echo "vm.swappiness=10" | sudo tee -a /etc/sysctl.conf
echo "vm.vfs_cache_pressure=50" | sudo tee -a /etc/sysctl.conf

# 3. Instalar Docker y Docker Compose Oficial
echo "[3/5] Instalando Docker Engine y Docker Compose..."
if ! command -v docker &> /dev/null; then
    sudo install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    sudo chmod a+r /etc/apt/keyrings/docker.gpg

    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
      $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

    sudo apt update
    sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    # Permitir al usuario actual usar docker sin sudo
    sudo usermod -aG docker "$USER" || true
    sudo systemctl enable docker
    sudo systemctl start docker
    echo "Docker instalado exitosamente."
else
    echo "Docker ya se encuentra instalado."
fi

# 4. Configurar Firewall (UFW)
echo "[4/5] Configurando reglas de Firewall..."
sudo ufw allow 22/tcp comment 'SSH'
sudo ufw allow 80/tcp comment 'HTTP Traefik'
sudo ufw allow 443/tcp comment 'HTTPS Traefik'
sudo ufw --force enable || true

# 5. Resumen final
echo "========================================================"
echo " ¡VPS Optimizado y Listo para Desplegar Grandprix!"
echo "========================================================"
echo "Pasos siguientes en el VPS:"
echo " 1. Clona o copia los archivos de este proyecto."
echo " 2. Copia y edita tu archivo .env: cp .env.example .env && nano .env"
echo " 3. Inicia el stack con: docker compose up -d --build"
echo " 4. Monitorea el estado con: docker compose ps && docker stats"
echo "========================================================"
