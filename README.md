# 🎓 SisCartera Universidad · Guía de Instalación

Sistema web de gestión de **cartera estudiantil + contabilidad PUC Colombia**, desarrollado en **PHP 8.x + PostgreSQL**.

Incluye módulo financiero de cartera (matrículas, facturación, pagos) **integrado de forma automática** con un módulo contable que sigue el **Plan Único de Cuentas (PUC)** colombiano — Decreto 2650/1993, adaptado para Instituciones de Educación Superior (IES).

---

## 📋 Requisitos

- PHP 8.1+ con extensiones: `pdo`, `pdo_pgsql`, `mbstring`, `json`
- PostgreSQL 14+
- Servidor web: Apache 2.4+ o Nginx
- Composer (opcional, para dependencias futuras)

---

## 🚀 Instalación

### 1. Base de datos

Todo el modelo de datos (cartera + contabilidad PUC) está unificado en **un solo script**:

```bash
# Crear base de datos
createdb -U postgres cartera_universidad

# Ejecutar el script unificado (única fuente de verdad)
psql -U postgres -d cartera_universidad -f sql/00_base_datos_completa.sql
```

Este script crea, en orden:
1. Tablas del módulo de **cartera** (estudiantes, programas, periodos, facturas, pagos, acuerdos de pago, descuentos, auditoría, usuarios).
2. Tablas del módulo **contable PUC** (plan de cuentas, comprobantes, movimientos, terceros, centros de costo).
3. **Triggers automáticos** que generan asientos contables cuando se factura o se paga cartera (ver sección siguiente).
4. Datos semilla: usuarios demo, programas académicos, plan de cuentas PUC completo, centros de costo.

### 2. Configuración

Edite `config/database.php` y ajuste las credenciales:

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'cartera_universidad');
define('DB_USER', 'postgres');
define('DB_PASS', 'SU_CONTRASEÑA');
define('APP_URL', 'http://localhost/cartera_universidad');
```

### 3. Servidor web (Apache)

Coloque la carpeta en el directorio del servidor y asegúrese de que el rewrite esté habilitado.

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

---

## 👤 Usuarios de Acceso

| Usuario      | Contraseña | Rol         |
|-------------|------------|-------------|
| admin       | password   | Administrador |
| financiero1 | password   | Financiero   |
| cajero1     | password   | Cajero       |

> ⚠️ **Cambie las contraseñas en producción.**

```php
echo password_hash('NuevaContraseña123', PASSWORD_BCRYPT, ['cost' => 12]);
```

---

## 🗂️ Estructura del Proyecto

```
cartera_universidad/
├── config/
│   └── database.php              # Configuración DB y constantes
├── includes/
│   ├── helpers.php               # Funciones utilitarias
│   ├── header.php                # Layout header + sidebar
│   └── footer.php                # Layout footer
├── modules/
│   ├── estudiantes/               # CRUD estudiantes
│   ├── facturas/                  # Liquidaciones de matrícula
│   ├── pagos/                     # Registro de pagos
│   ├── reportes/                  # Cartera, recaudos, morosos
│   ├── usuarios/                  # Gestión de usuarios
│   ├── configuracion/             # Períodos y conceptos
│   └── contabilidad/              # ★ Módulo PUC Colombia
│       ├── comprobantes.php          # Listado de comprobantes
│       ├── nuevo_comprobante.php     # Crear comprobante (partida doble)
│       ├── editar_comprobante.php    # Editar comprobante en borrador
│       ├── ver_comprobante.php       # Detalle + impresión
│       ├── plan_cuentas.php          # Navegador del PUC
│       ├── libro_mayor.php           # Mayor por cuenta
│       ├── balance_prueba.php        # Balance de comprobación
│       └── estados_financieros.php   # Balance General + Edo. Resultados
├── assets/
│   ├── css/app.css
│   └── js/app.js
├── sql/
│   └── 00_base_datos_completa.sql # ★ Único script SQL (cartera + PUC)
├── index.php
├── login.php
├── dashboard.php
└── logout.php
```

---

## 💡 Módulo de Cartera

- CRUD de estudiantes con búsqueda, filtros y estado.
- Liquidaciones de matrícula con cálculo automático sobre **SMMLV** (Ley colombiana), soporte de descuentos y becas.
- Registro de pagos con múltiples medios (efectivo, PSE, transferencia, etc.), pagos parciales/totales, recibo imprimible.
- Reportes: cartera por cobrar con antigüedad, recaudo por período, listado de morosos, exportación CSV.
- Seguridad: sesiones con timeout, CSRF, roles (admin/financiero/cajero/consulta), auditoría completa, prepared statements.

## 📊 Módulo Contable — PUC Colombia

El módulo contable implementa el **Plan Único de Cuentas** (Decreto 2650/1993) adaptado a universidades, con las siguientes características:

### Plan de cuentas
- Clases 1 a 9 completas (Activo, Pasivo, Patrimonio, Ingresos, Gastos, Costos, Cuentas de Orden).
- Cuentas específicas para IES: matrículas por programa/nivel, derechos académicos, bienestar universitario, fondos de investigación, transferencias Ley 30, becas y descuentos.
- Navegador jerárquico (`plan_cuentas.php`) con creación de cuentas nuevas y detección automática de nivel por longitud de código.

### Comprobantes y partida doble
- Tipos: Comprobante de Ingreso (CI), Egreso (CE), Nota Contable (NC), Causación (CA), Ajuste (AJ), Apertura (AP), Cierre (CL).
- Numeración automática por tipo/año/mes (`fn_numero_comprobante`).
- Editor de comprobantes con validación de partida doble en tiempo real (JavaScript) y en servidor.
- Flujo borrador → contabilizado → (anulado), con control de roles.

### Integración automática cartera ↔ contabilidad
Mediante **triggers de PostgreSQL**, el sistema genera asientos contables sin intervención manual:

| Evento en Cartera                  | Asiento Generado                                              |
|-------------------------------------|-----------------------------------------------------------------|
| Se genera una factura de matrícula  | Causación: Débito CxC Matrículas / Crédito Ingresos por Matrícula |
| Se anula una factura                | Reverso de la causación                                        |
| Se registra un pago                 | Débito Caja/Bancos / Crédito CxC Matrículas                    |
| Se reversa un pago                  | Reverso del asiento de pago                                    |

### Reportes financieros
- **Libro Mayor**: movimientos detallados por cuenta, con saldo inicial/corrido/final.
- **Balance de Prueba**: comprobación de saldos por período, valida que Débitos = Créditos.
- **Estados Financieros**: Balance General (Activo = Pasivo + Patrimonio) y Estado de Resultados (Ingresos − Gastos), con verificación automática de cuadre.

---

## ⚙️ Configuraciones Colombia

- **SMMLV 2025**: $1.423.500
- **Zona horaria**: America/Bogota
- **Formato moneda**: $ 1.423.500
- **Formato fecha**: dd/mm/aaaa
- **PUC**: Decreto 2650/1993, adaptado IES

---

## 📊 Extensiones sugeridas

- [ ] Integración PSE / pasarela de pago real
- [ ] Notificaciones por email (PHPMailer)
- [ ] API REST para portal estudiantil
- [ ] Exógena DIAN / Reportes tributarios
- [ ] Cierre contable de período automatizado

---

*Desarrollado para uso universitario en Colombia · PHP 8 + PostgreSQL · PUC Decreto 2650/1993*
