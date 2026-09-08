# Manual de Uso Técnico de SYNCDERO

SYNCDERO es un motor de versionamiento, monitoreo y migración de esquemas de bases de datos diseñado bajo la arquitectura conceptual y flujo de trabajo de Git.

---

## 1. Conceptos Fundamentales

- **Lotes de Migración (Batches):** Cada ejecución de sincronización agrupa los scripts pendientes bajo un número de lote secuencial (`batch`). Esto permite rastrear qué cambios ingresaron juntos y habilita reversiones atómicas completas (*Rollback*).
- **Inmutabilidad Criptográfica (Hashes SHA-256):** Al aplicar un script `.sql` o migración de Laravel `.php`, SYNCDERO genera un hash SHA-256 de su contenido y lo estampa en la tabla de control `_syncdero_meta`. Si un desarrollador edita un archivo ya aplicado, el sistema detecta una divergencia de integridad (*Drift*).
- **Entornos y Espejos (Remotes):** Cada conexión a base de datos (sea en Docker, red local o servidor baremetal) se trata como un *Remote*. El tablero principal visualiza si cada entorno está sincronizado (`SYNC`), atrasado (`BEHIND`) o con divergencia no resuelta (`DRIFT`).
- **Enlace Git Nativo:** Cada lote aplicado registra automáticamente el commit corto SHA (`git_commit`) y la rama de trabajo (`git_branch`) del repositorio del proyecto.

---

## 2. Tipos de Migraciones Soportadas

Los archivos de migración deben residir en la carpeta `migrations/` en la raíz del proyecto. Se ordenan alfabéticamente/cronológicamente por su nombre de archivo (se recomienda el prefijo numérico o timestamp `YYYYMMDDHHMMSS_nombre.ext`).

### A. Scripts SQL Puros (`.sql`)
Archivos estándar compatibles con MariaDB, MySQL, PostgreSQL o SQLite.

```sql
-- migrations/20260925_001_create_tenants.sql
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    domain VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### B. Migraciones Compatibles con Laravel (`.php`)
Clases anónimas o tradicionales de Laravel que retornan o implementan `up()`.

```php
<?php
// migrations/20260925_002_create_users.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Ejecución mediante schema builder o driver PDO
    }
    public function down(): void {
        // Rollback opcional
    }
};
```

---

## 3. Uso desde la Interfaz Web

1. **Selección de Entorno:** En el panel lateral izquierdo, selecciona el servidor o base de datos con la que deseas trabajar.
2. **Visualización de Flujo (Canvas):** En el lienzo central arrastra los nodos de batches para inspeccionar las migraciones aplicadas y pendientes.
3. **Visor de Diff:** En la barra lateral derecha ("Inspector de Cambios"), haz clic sobre cualquier archivo pendiente o divergente para ver el código SQL, hash SHA-256 y diferencias.
4. **Sincronización:** Haz clic en el botón superior **Sincronizar**. En la ventana modal puedes ingresar una nota descriptiva de la migración y verificar la rama Git actual. Si el entorno está marcado como protegido, deberás ingresar la clave configurada.
5. **Rollback (Deshacer Lote):** Usa el botón **Rollback** en la barra superior para revertir el lote más reciente aplicado en la base de datos seleccionada.
6. **Gestión de Servidores:** Mediante el botón **Servidores** puedes añadir motores MariaDB, PostgreSQL o SQLite locales o remotos, activando la opción *Auto-Discovery* para listar todas las bases de datos del host.
7. **Modo Oscuro / Claro:** Alterna el esquema de color con el botón del sol/luna en la esquina superior derecha.

---

## 4. Uso desde la Línea de Comandos (CLI)

El comando de terminal `bin/syncdero` permite automatizar tareas o integrarse en pipelines CI/CD:

```bash
# Ver estado de sincronización de todos los entornos
php bin/syncdero status

# Formato JSON para scripts y automatización
php bin/syncdero status --json

# Verificación de integridad en CI/CD (retorna código de salida 1 ante divergencias)
php bin/syncdero status --fail-on-drift

# Simulación de aplicación sin alterar la base de datos (Dry Run)
php bin/syncdero sync mariadb_local --dry-run

# Sincronización real con creación automática de backup previo
php bin/syncdero sync mariadb_local

# Revertir el último lote de migraciones aplicado
php bin/syncdero rollback mariadb_local

# Inspeccionar código SQL y hash de un archivo
php bin/syncdero diff 20260925_001_create_tenants.sql
```

---

## 5. Tabla de Metadatos del Sistema

SYNCDERO crea automáticamente una tabla ligera en cada base de datos monitoreada:

```sql
_syncdero_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    batch INT NOT NULL,
    hash VARCHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    execution_time_ms INT DEFAULT 0,
    applied_by VARCHAR(100) DEFAULT 'system',
    git_commit VARCHAR(40) NULL,
    git_branch VARCHAR(100) NULL,
    notes TEXT NULL
);
```
