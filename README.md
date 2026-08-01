# SYNCDERO - Git-Like Database Version Control & Migration Monitor

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)
[![Docker Support](https://img.shields.io/badge/Docker-Ready-2496ED.svg)](https://docker.com)

**SYNCDERO** es un motor y panel de control de versiones para bases de datos diseñado con la filosofía y flujo de trabajo de **Git**. Permite monitorear, auditar y sincronizar esquemas de bases de datos entre múltiples entornos y contenedores (Producción, Staging, Espejos de Desarrollo).

Soporta nativamente **MariaDB / MySQL**, **PostgreSQL** y **SQLite**, funcionando tanto en **Docker** como de forma **Standalone** en Windows, Linux y macOS.

---

## 🌟 Características Principales

- **Inmutabilidad por Hashes (SHA-256):** Cada migración se sella con su hash criptográfico. Si alguien altera un archivo ya aplicado en producción o desarrollo, SYNCDERO alerta de inmediato sobre una divergencia (**CONFLICT / DRIFT**).
- **Entornos como Branches/Remotes:** Monitorea y sincroniza bases de datos principales y espejos de desarrollo como si fueran ramas remotas de Git.
- **Soporte Dual de Migraciones:**
  - Archivos `.sql` planos estándar (universales y agnósticos).
  - Migraciones `.php` de Laravel (compatibilidad retroactiva).
- **Doble Interfaz:**
  - **CLI First:** Comandos de terminal `bin/syncdero status`, `sync`, `diff` optimizados para pipelines CI/CD (OneDev, GitHub Actions, GitLab CI).
  - **Web Dashboard:** Interfaz visual con temática oscura, árbol de batches/commits e inspección de diferencias.
- **Respaldos Atómicos Automáticos:** Realiza un snapshot previo antes de aplicar cualquier lote de migración.

---

## 🚀 Inicio Rápido con Docker

La forma más rápida de usar SYNCDERO en cualquier sistema:

```bash
# 1. Clonar el repositorio
git clone https://github.com/tu-usuario/syncdero.git
cd syncdero

# 2. Levantar el servicio
docker compose up -d --build

# 3. Acceder al Dashboard Web
# Abre http://localhost:8088 en tu navegador
```

---

## 💻 Uso Standalone (En Windows, Linux o Mac sin Docker)

Para usar SYNCDERO directamente en tu máquina local contra tu **MariaDB / MySQL**, **PostgreSQL** o **SQLite** local:

### Requisitos:
- PHP 8.2 o superior con las extensiones `pdo_mysql`, `pdo_pgsql` o `pdo_sqlite` habilitadas en tu `php.ini`.

### Pasos:
1. Copia el archivo de configuración base:
   ```bash
   cp config/syncdero.php config/my_config.php
   ```
2. Edita tus credenciales de base de datos en `config/syncdero.php` o exporta variables de entorno:
   ```powershell
   # En Windows PowerShell:
   $env:MARIADB_HOST="127.0.0.1"
   $env:MARIADB_DATABASE="mi_base_de_datos"
   $env:MARIADB_USER="root"
   $env:MARIADB_PASSWORD=""
   ```
3. Ejecuta los comandos CLI:
   ```bash
   # Ver estado de sincronización de todos los entornos:
   php bin/syncdero status

   # Simular aplicación de migraciones sin tocar la BD (Dry-Run):
   php bin/syncdero sync mariadb_local --dry-run

   # Aplicar migraciones con backup automático:
   php bin/syncdero sync mariadb_local
   ```
4. Para iniciar el Dashboard Web local:
   ```bash
   php -S 0.0.0.0:8088 -t public
   ```

---

## 🔄 Integración CI/CD en OneDev y Pipelines

SYNCDERO previene que se mezclen cambios con inconsistencias en ramas principales. Puedes agregarlo como un paso en tu pipeline de **OneDev**:

```yaml
- name: Verify Database Sync with SYNCDERO
  image: syncdero:latest
  commands:
    # Bloquea el pipeline (exit 1) si hay divergencias o alteraciones en los hashes
    - php /app/bin/syncdero status --fail-on-divergence --format=json
```

---

## 📁 Estructura del Proyecto

```text
syncdero/
├── bin/
│   └── syncdero           # Ejecutable CLI principal
├── config/
│   └── syncdero.php       # Configuración multimotor y ambientes
├── src/
│   ├── Core/              # StateEngine, Hashes SHA-256, Config
│   └── Drivers/           # PostgresDriver, MySqlDriver, SqliteDriver
├── public/                # Web Dashboard y UI
├── Dockerfile             # Imagen Alpine multi-driver (PHP 8.3)
└── composer.json          # Autoloading PSR-4 (Syncdero\)
```

---

## 📄 Licencia
Este proyecto es de código abierto bajo la licencia MIT.
