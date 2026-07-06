-- ============================================================
-- ============================================================
--   SISTEMA DE CARTERA UNIVERSITARIA + CONTABILIDAD PUC
--   Base de datos UNIFICADA - PostgreSQL
--   Decreto 2650/1993 (PUC Colombia) adaptado a IES
-- ============================================================
-- ============================================================
--
--   Este script crea TODAS las tablas del sistema en el orden
--   correcto:
--     1) Módulo de Cartera (estudiantes, facturas, pagos...)
--     2) Módulo Contable PUC (plan de cuentas, comprobantes...)
--     3) Funciones de generación automática de asientos
--     4) Triggers que conectan Cartera -> Contabilidad
--     5) Datos iniciales (PUC, usuarios, ejemplos)
--
--   Instalación:
--     createdb -U postgres cartera_universidad
--     psql -U postgres -d cartera_universidad -f 00_base_datos_completa.sql
--
-- ============================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ############################################################
-- # PARTE 1 — MÓDULO DE CARTERA UNIVERSITARIA
-- ############################################################

-- Programas académicos
CREATE TABLE programas (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nombre VARCHAR(200) NOT NULL,
    facultad VARCHAR(150) NOT NULL,
    nivel VARCHAR(30) CHECK (nivel IN ('pregrado','posgrado','especializacion','maestria','doctorado')) NOT NULL,
    semestres_duracion INT NOT NULL DEFAULT 8,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Periodos académicos
CREATE TABLE periodos (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    fecha_vencimiento_pago DATE NOT NULL,
    activo BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Conceptos de cobro
CREATE TABLE conceptos_cobro (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(30) UNIQUE NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    tipo VARCHAR(30) CHECK (tipo IN ('matricula','derechos_academicos','servicios','descuento','otro')) NOT NULL,
    aplica_smmlv BOOLEAN DEFAULT FALSE,
    porcentaje_smmlv NUMERIC(8,4),
    valor_fijo NUMERIC(15,2),
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Usuarios del sistema
CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    rol VARCHAR(30) CHECK (rol IN ('admin','financiero','cajero','consulta')) NOT NULL DEFAULT 'consulta',
    activo BOOLEAN DEFAULT TRUE,
    ultimo_acceso TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Estudiantes
CREATE TABLE estudiantes (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    tipo_documento VARCHAR(20) CHECK (tipo_documento IN ('CC','TI','CE','PASAPORTE','PEP')) NOT NULL,
    numero_documento VARCHAR(20) UNIQUE NOT NULL,
    primer_nombre VARCHAR(80) NOT NULL,
    segundo_nombre VARCHAR(80),
    primer_apellido VARCHAR(80) NOT NULL,
    segundo_apellido VARCHAR(80),
    email VARCHAR(150) NOT NULL,
    email_institucional VARCHAR(150),
    telefono VARCHAR(20),
    celular VARCHAR(20),
    direccion TEXT,
    municipio VARCHAR(100),
    departamento VARCHAR(100),
    estrato INT CHECK (estrato BETWEEN 1 AND 6),
    programa_id INT REFERENCES programas(id),
    semestre_actual INT,
    estado VARCHAR(30) CHECK (estado IN ('activo','inactivo','graduado','retirado','suspendido')) DEFAULT 'activo',
    fecha_ingreso DATE,
    foto_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Facturas (liquidaciones de matrícula)
CREATE TABLE facturas (
    id SERIAL PRIMARY KEY,
    numero_factura VARCHAR(30) UNIQUE NOT NULL,
    estudiante_id INT REFERENCES estudiantes(id) NOT NULL,
    periodo_id INT REFERENCES periodos(id) NOT NULL,
    fecha_emision DATE NOT NULL DEFAULT CURRENT_DATE,
    fecha_vencimiento DATE NOT NULL,
    subtotal NUMERIC(15,2) NOT NULL DEFAULT 0,
    descuentos NUMERIC(15,2) NOT NULL DEFAULT 0,
    total NUMERIC(15,2) NOT NULL DEFAULT 0,
    saldo NUMERIC(15,2) NOT NULL DEFAULT 0,
    estado VARCHAR(30) CHECK (estado IN ('pendiente','parcial','pagada','vencida','anulada')) DEFAULT 'pendiente',
    observaciones TEXT,
    generada_por INT REFERENCES usuarios(id),
    anulada_por INT REFERENCES usuarios(id),
    fecha_anulacion TIMESTAMP,
    motivo_anulacion TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(estudiante_id, periodo_id)
);

-- Items de factura
CREATE TABLE factura_items (
    id SERIAL PRIMARY KEY,
    factura_id INT REFERENCES facturas(id) ON DELETE CASCADE,
    concepto_id INT REFERENCES conceptos_cobro(id),
    descripcion VARCHAR(255) NOT NULL,
    cantidad INT DEFAULT 1,
    valor_unitario NUMERIC(15,2) NOT NULL,
    valor_total NUMERIC(15,2) NOT NULL,
    es_descuento BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Medios de pago
CREATE TABLE medios_pago (
    id SERIAL PRIMARY KEY,
    codigo VARCHAR(20) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    requiere_referencia BOOLEAN DEFAULT TRUE,
    activo BOOLEAN DEFAULT TRUE
);

-- Pagos
CREATE TABLE pagos (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE,
    factura_id INT REFERENCES facturas(id) NOT NULL,
    medio_pago_id INT REFERENCES medios_pago(id) NOT NULL,
    numero_recibo VARCHAR(30) UNIQUE NOT NULL,
    fecha_pago TIMESTAMP NOT NULL DEFAULT NOW(),
    valor NUMERIC(15,2) NOT NULL,
    referencia_bancaria VARCHAR(100),
    banco VARCHAR(100),
    observaciones TEXT,
    estado VARCHAR(20) CHECK (estado IN ('aplicado','reversado','pendiente')) DEFAULT 'aplicado',
    registrado_por INT REFERENCES usuarios(id),
    reversado_por INT REFERENCES usuarios(id),
    fecha_reverso TIMESTAMP,
    motivo_reverso TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Acuerdos de pago (cuotas)
CREATE TABLE acuerdos_pago (
    id SERIAL PRIMARY KEY,
    factura_id INT REFERENCES facturas(id) NOT NULL,
    fecha_acuerdo DATE NOT NULL DEFAULT CURRENT_DATE,
    valor_total NUMERIC(15,2) NOT NULL,
    numero_cuotas INT NOT NULL,
    observaciones TEXT,
    estado VARCHAR(20) CHECK (estado IN ('vigente','cumplido','incumplido')) DEFAULT 'vigente',
    creado_por INT REFERENCES usuarios(id),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Cuotas de acuerdo
CREATE TABLE cuotas_acuerdo (
    id SERIAL PRIMARY KEY,
    acuerdo_id INT REFERENCES acuerdos_pago(id) ON DELETE CASCADE,
    numero_cuota INT NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    valor NUMERIC(15,2) NOT NULL,
    valor_pagado NUMERIC(15,2) DEFAULT 0,
    estado VARCHAR(20) CHECK (estado IN ('pendiente','pagada','vencida')) DEFAULT 'pendiente',
    pago_id INT REFERENCES pagos(id),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Descuentos y becas
CREATE TABLE descuentos (
    id SERIAL PRIMARY KEY,
    estudiante_id INT REFERENCES estudiantes(id),
    concepto_id INT REFERENCES conceptos_cobro(id),
    periodo_id INT REFERENCES periodos(id),
    porcentaje NUMERIC(5,2) NOT NULL,
    valor_maximo NUMERIC(15,2),
    descripcion TEXT,
    soporte_url VARCHAR(255),
    aprobado_por INT REFERENCES usuarios(id),
    fecha_aprobacion TIMESTAMP,
    vigente BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Auditoría
CREATE TABLE auditoria (
    id SERIAL PRIMARY KEY,
    usuario_id INT REFERENCES usuarios(id),
    tabla VARCHAR(100),
    accion VARCHAR(20) CHECK (accion IN ('INSERT','UPDATE','DELETE','LOGIN','LOGOUT')),
    registro_id INT,
    datos_anteriores JSONB,
    datos_nuevos JSONB,
    ip_address INET,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Índices cartera
CREATE INDEX idx_estudiantes_codigo ON estudiantes(codigo);
CREATE INDEX idx_estudiantes_documento ON estudiantes(numero_documento);
CREATE INDEX idx_facturas_estudiante ON facturas(estudiante_id);
CREATE INDEX idx_facturas_periodo ON facturas(periodo_id);
CREATE INDEX idx_facturas_estado ON facturas(estado);
CREATE INDEX idx_pagos_factura ON pagos(factura_id);
CREATE INDEX idx_pagos_fecha ON pagos(fecha_pago);
CREATE INDEX idx_auditoria_usuario ON auditoria(usuario_id);

-- ============================================================
-- FUNCIONES Y TRIGGERS — MÓDULO CARTERA
-- ============================================================

-- Actualizar saldo de factura tras un pago
CREATE OR REPLACE FUNCTION actualizar_saldo_factura()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' AND NEW.estado = 'aplicado' THEN
        UPDATE facturas
        SET saldo = saldo - NEW.valor,
            estado = CASE
                WHEN (saldo - NEW.valor) <= 0 THEN 'pagada'
                WHEN (saldo - NEW.valor) < total THEN 'parcial'
                ELSE estado
            END,
            updated_at = NOW()
        WHERE id = NEW.factura_id;
    ELSIF TG_OP = 'UPDATE' AND NEW.estado = 'reversado' AND OLD.estado = 'aplicado' THEN
        UPDATE facturas
        SET saldo = saldo + NEW.valor,
            estado = CASE
                WHEN (saldo + NEW.valor) >= total THEN 'pendiente'
                ELSE 'parcial'
            END,
            updated_at = NOW()
        WHERE id = NEW.factura_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_pago_saldo
AFTER INSERT OR UPDATE ON pagos
FOR EACH ROW EXECUTE FUNCTION actualizar_saldo_factura();

-- Generar número de factura
CREATE OR REPLACE FUNCTION generar_numero_factura(periodo_codigo VARCHAR)
RETURNS VARCHAR AS $$
DECLARE
    consecutivo INT;
    numero VARCHAR(30);
BEGIN
    SELECT COALESCE(MAX(CAST(SUBSTRING(numero_factura FROM 9) AS INT)), 0) + 1
    INTO consecutivo
    FROM facturas
    WHERE numero_factura LIKE 'FAC-' || periodo_codigo || '-%';

    numero := 'FAC-' || periodo_codigo || '-' || LPAD(consecutivo::TEXT, 6, '0');
    RETURN numero;
END;
$$ LANGUAGE plpgsql;

-- Marcar facturas vencidas
CREATE OR REPLACE FUNCTION marcar_facturas_vencidas()
RETURNS INT AS $$
DECLARE
    afectadas INT;
BEGIN
    UPDATE facturas
    SET estado = 'vencida', updated_at = NOW()
    WHERE estado IN ('pendiente', 'parcial')
      AND fecha_vencimiento < CURRENT_DATE;
    GET DIAGNOSTICS afectadas = ROW_COUNT;
    RETURN afectadas;
END;
$$ LANGUAGE plpgsql;

-- ############################################################
-- # PARTE 2 — MÓDULO CONTABLE PUC (Decreto 2650/1993)
-- #          Adaptado para Instituciones de Educación Superior
-- ############################################################

-- Plan Único de Cuentas
CREATE TABLE puc_cuentas (
    id                  SERIAL PRIMARY KEY,
    codigo              VARCHAR(20) UNIQUE NOT NULL,
    nombre              VARCHAR(300) NOT NULL,
    naturaleza          CHAR(1) CHECK (naturaleza IN ('D','C')) NOT NULL,
    nivel               INT CHECK (nivel BETWEEN 1 AND 6) NOT NULL,
    clase               CHAR(1) NOT NULL,
    codigo_padre        VARCHAR(20) REFERENCES puc_cuentas(codigo),
    acepta_movimiento   BOOLEAN DEFAULT FALSE,
    requiere_tercero    BOOLEAN DEFAULT FALSE,
    activa              BOOLEAN DEFAULT TRUE,
    descripcion         TEXT,
    created_at          TIMESTAMP DEFAULT NOW()
);

-- Centros de costo
CREATE TABLE centros_costo (
    id          SERIAL PRIMARY KEY,
    codigo      VARCHAR(20) UNIQUE NOT NULL,
    nombre      VARCHAR(200) NOT NULL,
    tipo        VARCHAR(30) DEFAULT 'administrativo',
    activo      BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP DEFAULT NOW()
);

-- Terceros (NIT/CC para contrapartidas)
CREATE TABLE terceros (
    id               SERIAL PRIMARY KEY,
    tipo_persona     CHAR(1) DEFAULT 'N',
    tipo_documento   VARCHAR(10) NOT NULL,
    numero_documento VARCHAR(20) UNIQUE NOT NULL,
    razon_social     VARCHAR(200),
    nombres          VARCHAR(150),
    apellidos        VARCHAR(150),
    email            VARCHAR(150),
    telefono         VARCHAR(20),
    municipio        VARCHAR(100),
    activo           BOOLEAN DEFAULT TRUE,
    created_at       TIMESTAMP DEFAULT NOW()
);

-- Comprobantes contables
CREATE TABLE comprobantes (
    id               SERIAL PRIMARY KEY,
    numero           VARCHAR(30) UNIQUE NOT NULL,
    tipo             VARCHAR(30) CHECK (tipo IN (
                         'comprobante_ingreso','comprobante_egreso',
                         'nota_contable','causacion','apertura','cierre','ajuste'
                     )) NOT NULL,
    fecha            DATE NOT NULL DEFAULT CURRENT_DATE,
    periodo_contable VARCHAR(7) NOT NULL,
    descripcion      TEXT NOT NULL,
    total_debitos    NUMERIC(18,2) DEFAULT 0,
    total_creditos   NUMERIC(18,2) DEFAULT 0,
    estado           VARCHAR(20) CHECK (estado IN ('borrador','contabilizado','anulado')) DEFAULT 'borrador',
    -- Trazabilidad con cartera
    pago_id          INT REFERENCES pagos(id),
    factura_id       INT REFERENCES facturas(id),
    -- Control
    elaborado_por    INT REFERENCES usuarios(id),
    aprobado_por     INT REFERENCES usuarios(id),
    fecha_aprobacion TIMESTAMP,
    anulado_por      INT REFERENCES usuarios(id),
    fecha_anulacion  TIMESTAMP,
    motivo_anulacion TEXT,
    observaciones    TEXT,
    es_automatico    BOOLEAN DEFAULT FALSE,
    created_at       TIMESTAMP DEFAULT NOW()
);

-- Movimientos contables (partida doble)
CREATE TABLE movimientos_contables (
    id              SERIAL PRIMARY KEY,
    comprobante_id  INT REFERENCES comprobantes(id) ON DELETE CASCADE NOT NULL,
    linea           INT NOT NULL,
    cuenta_codigo   VARCHAR(20) REFERENCES puc_cuentas(codigo) NOT NULL,
    tercero_id      INT REFERENCES terceros(id),
    centro_costo_id INT REFERENCES centros_costo(id),
    descripcion     VARCHAR(400) NOT NULL,
    debito          NUMERIC(18,2) DEFAULT 0 CHECK (debito >= 0),
    credito         NUMERIC(18,2) DEFAULT 0 CHECK (credito >= 0),
    CONSTRAINT solo_un_lado CHECK (
        (debito > 0 AND credito = 0) OR
        (credito > 0 AND debito = 0) OR
        (debito = 0 AND credito = 0)
    ),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Mapeo de cuentas del sistema (config)
CREATE TABLE config_cuentas_sistema (
    id            SERIAL PRIMARY KEY,
    concepto      VARCHAR(60) UNIQUE NOT NULL,
    cuenta_codigo VARCHAR(20) REFERENCES puc_cuentas(codigo),
    descripcion   VARCHAR(200)
);

-- Índices contables
CREATE INDEX idx_puc_codigo      ON puc_cuentas(codigo);
CREATE INDEX idx_puc_padre       ON puc_cuentas(codigo_padre);
CREATE INDEX idx_puc_clase       ON puc_cuentas(clase);
CREATE INDEX idx_mov_comprobante ON movimientos_contables(comprobante_id);
CREATE INDEX idx_mov_cuenta      ON movimientos_contables(cuenta_codigo);
CREATE INDEX idx_comp_fecha      ON comprobantes(fecha);
CREATE INDEX idx_comp_periodo    ON comprobantes(periodo_contable);
CREATE INDEX idx_comp_estado     ON comprobantes(estado);
CREATE INDEX idx_comp_factura    ON comprobantes(factura_id);
CREATE INDEX idx_comp_pago       ON comprobantes(pago_id);

-- ============================================================
-- FUNCIONES — MÓDULO CONTABLE
-- ============================================================

-- Número de comprobante por tipo/fecha
CREATE OR REPLACE FUNCTION fn_numero_comprobante(p_tipo VARCHAR, p_fecha DATE)
RETURNS VARCHAR AS $$
DECLARE
    v_pref VARCHAR := 'CD';
    v_anio VARCHAR := TO_CHAR(p_fecha,'YYYY');
    v_mes  VARCHAR := TO_CHAR(p_fecha,'MM');
    v_cons INT;
BEGIN
    v_pref := CASE p_tipo
        WHEN 'comprobante_ingreso' THEN 'CI'
        WHEN 'comprobante_egreso'  THEN 'CE'
        WHEN 'nota_contable'       THEN 'NC'
        WHEN 'causacion'           THEN 'CA'
        WHEN 'apertura'            THEN 'AP'
        WHEN 'cierre'              THEN 'CL'
        WHEN 'ajuste'              THEN 'AJ'
        ELSE 'CD'
    END;
    SELECT COALESCE(MAX(CAST(
        SUBSTRING(numero FROM LENGTH(v_pref||'-'||v_anio||'-'||v_mes||'-')+1)
        AS INT)),0)+1
    INTO v_cons
    FROM comprobantes
    WHERE numero LIKE v_pref||'-'||v_anio||'-'||v_mes||'-%';
    RETURN v_pref||'-'||v_anio||'-'||v_mes||'-'||LPAD(v_cons::TEXT,4,'0');
END;
$$ LANGUAGE plpgsql;

-- Recalcular totales de un comprobante
CREATE OR REPLACE FUNCTION fn_recalcular_comprobante(p_id INT)
RETURNS VOID AS $$
BEGIN
    UPDATE comprobantes
    SET total_debitos  = (SELECT COALESCE(SUM(debito),0)  FROM movimientos_contables WHERE comprobante_id=p_id),
        total_creditos = (SELECT COALESCE(SUM(credito),0) FROM movimientos_contables WHERE comprobante_id=p_id)
    WHERE id = p_id;
END;
$$ LANGUAGE plpgsql;

-- Obtiene (o crea) el tercero correspondiente a un estudiante
CREATE OR REPLACE FUNCTION fn_obtener_tercero_estudiante(p_estudiante_id INT)
RETURNS INT AS $$
DECLARE
    v_est RECORD;
    v_tercero_id INT;
BEGIN
    SELECT numero_documento, tipo_documento,
           primer_nombre||' '||COALESCE(segundo_nombre,'')||' '||primer_apellido||' '||COALESCE(segundo_apellido,'') AS nombre_completo,
           email
    INTO v_est
    FROM estudiantes WHERE id = p_estudiante_id;

    SELECT id INTO v_tercero_id FROM terceros WHERE numero_documento = v_est.numero_documento;

    IF v_tercero_id IS NULL THEN
        INSERT INTO terceros (tipo_documento, numero_documento, nombres, tipo_persona, email)
        VALUES (v_est.tipo_documento, v_est.numero_documento, TRIM(v_est.nombre_completo), 'N', v_est.email)
        RETURNING id INTO v_tercero_id;
    END IF;

    RETURN v_tercero_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- ASIENTO AUTOMÁTICO: CAUSACIÓN DE MATRÍCULA (al generar factura)
--   DÉBITO  131001 CxC Matrículas Pregrado (por el TOTAL)
--   CRÉDITO 410101 Ingreso Matrículas Pregrado (por el SUBTOTAL)
--   Si hay descuento:
--     DÉBITO  534502 Descuentos matrícula otorgados
--     CRÉDITO 131001 CxC Matrículas (reduce la cartera)
-- ============================================================
CREATE OR REPLACE FUNCTION fn_asiento_causacion_matricula(p_factura_id INT)
RETURNS INT AS $$
DECLARE
    v_fac RECORD;
    v_comp_id INT;
    v_numero VARCHAR;
    v_tercero_id INT;
    v_cta_cxc VARCHAR;
    v_cta_ingreso VARCHAR;
    v_linea INT := 1;
BEGIN
    SELECT f.*, pr.nivel AS nivel_programa
    INTO v_fac
    FROM facturas f
    JOIN estudiantes e ON e.id = f.estudiante_id
    JOIN programas pr ON pr.id = e.programa_id
    WHERE f.id = p_factura_id;

    -- Evitar doble causación
    IF EXISTS (SELECT 1 FROM comprobantes WHERE factura_id = p_factura_id AND tipo = 'causacion' AND estado != 'anulado') THEN
        RETURN NULL;
    END IF;

    v_tercero_id := fn_obtener_tercero_estudiante(v_fac.estudiante_id);

    -- Cuenta CxC e ingreso según nivel del programa
    IF v_fac.nivel_programa = 'pregrado' THEN
        v_cta_cxc     := '131001';
        v_cta_ingreso := '410101';
    ELSE
        v_cta_cxc     := '131002';
        v_cta_ingreso := '410102';
    END IF;

    v_numero := fn_numero_comprobante('causacion', v_fac.fecha_emision);

    INSERT INTO comprobantes (numero, tipo, fecha, periodo_contable, descripcion, factura_id, estado, es_automatico)
    VALUES (v_numero, 'causacion', v_fac.fecha_emision, TO_CHAR(v_fac.fecha_emision,'YYYY-MM'),
            'Causación liquidación de matrícula '||v_fac.numero_factura,
            p_factura_id, 'contabilizado', TRUE)
    RETURNING id INTO v_comp_id;

    -- Débito CxC por el total a pagar por el estudiante
    INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, descripcion, debito)
    VALUES (v_comp_id, v_linea, v_cta_cxc, v_tercero_id, 'CxC matrícula '||v_fac.numero_factura, v_fac.total);
    v_linea := v_linea + 1;

    -- Crédito Ingreso por el subtotal (valor bruto antes de descuentos)
    INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, descripcion, credito)
    VALUES (v_comp_id, v_linea, v_cta_ingreso, v_tercero_id, 'Ingreso matrícula '||v_fac.numero_factura, v_fac.subtotal);
    v_linea := v_linea + 1;

    -- Si hay descuentos: Débito gasto descuento / Crédito mismo ingreso (para que cuadre)
    IF v_fac.descuentos > 0 THEN
        INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, descripcion, debito)
        VALUES (v_comp_id, v_linea, '534502', v_tercero_id, 'Descuento otorgado '||v_fac.numero_factura, v_fac.descuentos);
        v_linea := v_linea + 1;

        INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, descripcion, credito)
        VALUES (v_comp_id, v_linea, v_cta_ingreso, v_tercero_id, 'Reverso ingreso por descuento '||v_fac.numero_factura, v_fac.descuentos);
        v_linea := v_linea + 1;
    END IF;

    PERFORM fn_recalcular_comprobante(v_comp_id);
    RETURN v_comp_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- ASIENTO AUTOMÁTICO: RECAUDO DE PAGO (al registrar un pago)
--   DÉBITO  Caja/Bancos según medio de pago
--   CRÉDITO CxC Matrículas (abono a cartera del estudiante)
-- ============================================================
CREATE OR REPLACE FUNCTION fn_asiento_pago_cartera(p_pago_id INT)
RETURNS INT AS $$
DECLARE
    v_pago RECORD;
    v_comp_id INT;
    v_numero VARCHAR;
    v_tercero_id INT;
    v_cta_caja VARCHAR;
    v_cta_cxc  VARCHAR;
BEGIN
    SELECT pg.*, f.estudiante_id, f.numero_factura,
           e.numero_documento, e.tipo_documento, pr.nivel AS nivel_programa,
           mp.codigo AS medio_codigo
    INTO v_pago
    FROM pagos pg
    JOIN facturas f ON f.id = pg.factura_id
    JOIN estudiantes e ON e.id = f.estudiante_id
    JOIN programas pr ON pr.id = e.programa_id
    JOIN medios_pago mp ON mp.id = pg.medio_pago_id
    WHERE pg.id = p_pago_id;

    IF EXISTS (SELECT 1 FROM comprobantes WHERE pago_id = p_pago_id AND estado != 'anulado') THEN
        RETURN NULL;
    END IF;

    v_tercero_id := fn_obtener_tercero_estudiante(v_pago.estudiante_id);

    -- Cuenta de caja/banco según medio de pago
    v_cta_caja := CASE v_pago.medio_codigo
        WHEN 'EFECTIVO' THEN '11050101'
        WHEN 'PSE'      THEN '11100501'
        WHEN 'TRANSF'   THEN '11100501'
        WHEN 'CHEQUE'   THEN '11100501'
        WHEN 'TARJETA'  THEN '11100501'
        WHEN 'CONVENIO' THEN '11100501'
        ELSE '11050101'
    END;

    -- Cuenta CxC según nivel del programa
    v_cta_cxc := CASE WHEN v_pago.nivel_programa = 'pregrado' THEN '131001' ELSE '131002' END;

    v_numero := fn_numero_comprobante('comprobante_ingreso', v_pago.fecha_pago::date);

    INSERT INTO comprobantes (numero, tipo, fecha, periodo_contable, descripcion, pago_id, estado, es_automatico)
    VALUES (v_numero, 'comprobante_ingreso', v_pago.fecha_pago::date, TO_CHAR(v_pago.fecha_pago,'YYYY-MM'),
            'Recaudo matrícula — Recibo '||v_pago.numero_recibo||' / Factura '||v_pago.numero_factura,
            p_pago_id, 'contabilizado', TRUE)
    RETURNING id INTO v_comp_id;

    -- Débito Caja/Banco
    INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, descripcion, debito)
    VALUES (v_comp_id, 1, v_cta_caja, v_tercero_id, 'Recaudo recibo '||v_pago.numero_recibo, v_pago.valor);

    -- Crédito CxC (abono cartera)
    INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, descripcion, credito)
    VALUES (v_comp_id, 2, v_cta_cxc, v_tercero_id, 'Abono cartera — recibo '||v_pago.numero_recibo, v_pago.valor);

    PERFORM fn_recalcular_comprobante(v_comp_id);
    RETURN v_comp_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- ASIENTO AUTOMÁTICO: REVERSO DE PAGO
--   Genera una NOTA CONTABLE que invierte el comprobante
--   de ingreso original (débito/crédito intercambiados)
-- ============================================================
CREATE OR REPLACE FUNCTION fn_asiento_reverso_pago(p_pago_id INT)
RETURNS INT AS $$
DECLARE
    v_pago RECORD;
    v_comp_orig RECORD;
    v_comp_id INT;
    v_numero VARCHAR;
    v_mov RECORD;
    v_linea INT := 1;
BEGIN
    SELECT * INTO v_pago FROM pagos WHERE id = p_pago_id;

    SELECT * INTO v_comp_orig FROM comprobantes
    WHERE pago_id = p_pago_id AND tipo='comprobante_ingreso' AND estado='contabilizado'
    LIMIT 1;

    IF v_comp_orig IS NULL THEN RETURN NULL; END IF;

    v_numero := fn_numero_comprobante('nota_contable', CURRENT_DATE);

    INSERT INTO comprobantes (numero, tipo, fecha, periodo_contable, descripcion, pago_id, estado, es_automatico)
    VALUES (v_numero, 'nota_contable', CURRENT_DATE, TO_CHAR(CURRENT_DATE,'YYYY-MM'),
            'Reverso de pago — Recibo '||v_pago.numero_recibo||' ('||COALESCE(v_pago.motivo_reverso,'sin motivo')||')',
            p_pago_id, 'contabilizado', TRUE)
    RETURNING id INTO v_comp_id;

    -- Invertir cada línea del comprobante original
    FOR v_mov IN
        SELECT * FROM movimientos_contables WHERE comprobante_id = v_comp_orig.id ORDER BY linea
    LOOP
        INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, centro_costo_id, descripcion, debito, credito)
        VALUES (v_comp_id, v_linea, v_mov.cuenta_codigo, v_mov.tercero_id, v_mov.centro_costo_id,
                'Reverso: '||v_mov.descripcion,
                v_mov.credito,  -- intercambiados
                v_mov.debito);
        v_linea := v_linea + 1;
    END LOOP;

    PERFORM fn_recalcular_comprobante(v_comp_id);
    RETURN v_comp_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- ASIENTO AUTOMÁTICO: ANULACIÓN DE FACTURA SIN PAGOS
--   Genera nota contable que reversa la causación original
-- ============================================================
CREATE OR REPLACE FUNCTION fn_asiento_reverso_causacion(p_factura_id INT)
RETURNS INT AS $$
DECLARE
    v_fac RECORD;
    v_comp_orig RECORD;
    v_comp_id INT;
    v_numero VARCHAR;
    v_mov RECORD;
    v_linea INT := 1;
BEGIN
    SELECT * INTO v_fac FROM facturas WHERE id = p_factura_id;

    SELECT * INTO v_comp_orig FROM comprobantes
    WHERE factura_id = p_factura_id AND tipo='causacion' AND estado='contabilizado'
    LIMIT 1;

    IF v_comp_orig IS NULL THEN RETURN NULL; END IF;

    v_numero := fn_numero_comprobante('nota_contable', CURRENT_DATE);

    INSERT INTO comprobantes (numero, tipo, fecha, periodo_contable, descripcion, factura_id, estado, es_automatico)
    VALUES (v_numero, 'nota_contable', CURRENT_DATE, TO_CHAR(CURRENT_DATE,'YYYY-MM'),
            'Reverso causación por anulación — Factura '||v_fac.numero_factura||' ('||COALESCE(v_fac.motivo_anulacion,'sin motivo')||')',
            p_factura_id, 'contabilizado', TRUE)
    RETURNING id INTO v_comp_id;

    FOR v_mov IN
        SELECT * FROM movimientos_contables WHERE comprobante_id = v_comp_orig.id ORDER BY linea
    LOOP
        INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, tercero_id, centro_costo_id, descripcion, debito, credito)
        VALUES (v_comp_id, v_linea, v_mov.cuenta_codigo, v_mov.tercero_id, v_mov.centro_costo_id,
                'Reverso: '||v_mov.descripcion, v_mov.credito, v_mov.debito);
        v_linea := v_linea + 1;
    END LOOP;

    PERFORM fn_recalcular_comprobante(v_comp_id);
    RETURN v_comp_id;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- TRIGGERS DE INTEGRACIÓN CARTERA -> CONTABILIDAD
-- ============================================================

-- Al INSERTAR una factura (no anulada) -> causar ingreso
CREATE OR REPLACE FUNCTION trg_fn_factura_causacion()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado != 'anulada' THEN
        PERFORM fn_asiento_causacion_matricula(NEW.id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_factura_causacion
AFTER INSERT ON facturas
FOR EACH ROW EXECUTE FUNCTION trg_fn_factura_causacion();

-- Al marcar una factura como 'anulada' -> reversar causación (si no tiene pagos)
CREATE OR REPLACE FUNCTION trg_fn_factura_anulacion()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado = 'anulada' AND OLD.estado != 'anulada' THEN
        PERFORM fn_asiento_reverso_causacion(NEW.id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_factura_anulacion
AFTER UPDATE ON facturas
FOR EACH ROW
WHEN (NEW.estado IS DISTINCT FROM OLD.estado)
EXECUTE FUNCTION trg_fn_factura_anulacion();

-- Al INSERTAR un pago aplicado -> generar comprobante de ingreso
CREATE OR REPLACE FUNCTION trg_fn_pago_contabilizar()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado = 'aplicado' THEN
        PERFORM fn_asiento_pago_cartera(NEW.id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_pago_contabilizar
AFTER INSERT ON pagos
FOR EACH ROW EXECUTE FUNCTION trg_fn_pago_contabilizar();

-- Al reversar un pago -> generar nota contable de reverso
CREATE OR REPLACE FUNCTION trg_fn_pago_reverso_contable()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado = 'reversado' AND OLD.estado = 'aplicado' THEN
        PERFORM fn_asiento_reverso_pago(NEW.id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_pago_reverso_contable
AFTER UPDATE ON pagos
FOR EACH ROW
WHEN (NEW.estado IS DISTINCT FROM OLD.estado)
EXECUTE FUNCTION trg_fn_pago_reverso_contable();

-- ============================================================
-- VISTAS CONTABLES
-- ============================================================

CREATE OR REPLACE VIEW v_balance_prueba AS
SELECT
    c.codigo, c.nombre, c.clase, c.nivel, c.naturaleza,
    COALESCE(SUM(m.debito),0)  AS mov_debito,
    COALESCE(SUM(m.credito),0) AS mov_credito,
    CASE c.naturaleza WHEN 'D' THEN GREATEST(0, COALESCE(SUM(m.debito),0)-COALESCE(SUM(m.credito),0)) ELSE 0 END AS saldo_debito,
    CASE c.naturaleza WHEN 'C' THEN GREATEST(0, COALESCE(SUM(m.credito),0)-COALESCE(SUM(m.debito),0)) ELSE 0 END AS saldo_credito
FROM puc_cuentas c
LEFT JOIN movimientos_contables m ON m.cuenta_codigo=c.codigo
LEFT JOIN comprobantes cp ON cp.id=m.comprobante_id AND cp.estado='contabilizado'
WHERE c.acepta_movimiento=TRUE
GROUP BY c.codigo,c.nombre,c.clase,c.nivel,c.naturaleza
ORDER BY c.codigo;

CREATE OR REPLACE VIEW v_libro_mayor AS
SELECT
    c.codigo, c.nombre AS cuenta,
    cp.numero AS comprobante, cp.fecha, cp.tipo,
    cp.descripcion AS desc_comp,
    m.descripcion  AS desc_mov,
    COALESCE(t.razon_social, t.nombres||' '||COALESCE(t.apellidos,'')) AS tercero,
    cc.nombre AS centro_costo,
    m.debito, m.credito,
    m.linea, m.id AS mov_id
FROM movimientos_contables m
JOIN puc_cuentas c   ON c.codigo=m.cuenta_codigo
JOIN comprobantes cp ON cp.id=m.comprobante_id AND cp.estado='contabilizado'
LEFT JOIN terceros t     ON t.id=m.tercero_id
LEFT JOIN centros_costo cc ON cc.id=m.centro_costo_id
ORDER BY c.codigo, cp.fecha, cp.numero, m.linea;

CREATE OR REPLACE VIEW v_estado_resultados AS
SELECT
    CASE WHEN c.clase='4' THEN 'Ingresos' WHEN c.clase='5' THEN 'Gastos' ELSE 'Costos' END AS tipo,
    c.codigo, c.nombre,
    CASE c.naturaleza
        WHEN 'C' THEN GREATEST(0, COALESCE(SUM(m.credito),0)-COALESCE(SUM(m.debito),0))
        ELSE GREATEST(0, COALESCE(SUM(m.debito),0)-COALESCE(SUM(m.credito),0))
    END AS valor
FROM puc_cuentas c
LEFT JOIN movimientos_contables m ON m.cuenta_codigo=c.codigo
LEFT JOIN comprobantes cp ON cp.id=m.comprobante_id AND cp.estado='contabilizado'
WHERE c.clase IN ('4','5','6') AND c.nivel = 4
GROUP BY c.clase,c.codigo,c.nombre,c.naturaleza
HAVING COALESCE(SUM(m.debito),0)+COALESCE(SUM(m.credito),0) > 0
ORDER BY c.clase, c.codigo;

-- ############################################################
-- # PARTE 3 — PLAN ÚNICO DE CUENTAS (PUC) PARA LA UNIVERSIDAD
-- ############################################################

-- Clase 1: ACTIVOS
INSERT INTO puc_cuentas(codigo,nombre,naturaleza,nivel,clase,codigo_padre,acepta_movimiento) VALUES
('1','ACTIVOS','D',1,'1',NULL,FALSE),
('11','DISPONIBLE','D',2,'1','1',FALSE),
('1105','Caja','D',3,'1','11',FALSE),
('110501','Caja general','D',4,'1','1105',FALSE),
('11050101','Caja principal','D',5,'1','110501',TRUE),
('11050102','Caja auxiliar tesorería','D',5,'1','110501',TRUE),
('1110','Depósitos en instituciones financieras','D',3,'1','11',FALSE),
('111005','Cuenta corriente','D',4,'1','1110',FALSE),
('11100501','Bancolombia cta cte principal','D',5,'1','111005',TRUE),
('11100502','Davivienda cta cte','D',5,'1','111005',TRUE),
('111010','Cuenta de ahorros','D',4,'1','1110',FALSE),
('11101001','Bancolombia ahorros','D',5,'1','111010',TRUE),
('11101002','Nequi institucional','D',5,'1','111010',TRUE),
('111015','Fondos especiales','D',4,'1','1110',TRUE),
('12','INVERSIONES','D',2,'1','1',FALSE),
('1205','Acciones y cuotas partes','D',3,'1','12',TRUE),
('1255','Títulos de renta fija','D',3,'1','12',TRUE),
('13','DEUDORES','D',2,'1','1',FALSE),
('1305','Clientes','D',3,'1','13',FALSE),
('130505','Deudores servicios educativos','D',4,'1','1305',TRUE),
('1310','Cuentas por cobrar matrículas','D',3,'1','13',FALSE),
('131001','CxC matrículas pregrado','D',4,'1','1310',TRUE),
('131002','CxC matrículas posgrado','D',4,'1','1310',TRUE),
('131003','CxC derechos académicos','D',4,'1','1310',TRUE),
('131004','CxC servicios universitarios','D',4,'1','1310',TRUE),
('131005','CxC convenios educativos','D',4,'1','1310',TRUE),
('131010','Cartera vencida matrículas','D',4,'1','1310',TRUE),
('1315','Provisión deudores educación','D',3,'1','13',FALSE),
('131501','Provisión cartera matrículas','D',4,'1','1315',TRUE),
('1320','Anticipos y avances','D',3,'1','13',FALSE),
('132001','Anticipos a contratistas','D',4,'1','1320',TRUE),
('132002','Anticipos a proveedores','D',4,'1','1320',TRUE),
('1325','Cuentas por cobrar trabajadores','D',3,'1','13',FALSE),
('132501','Créditos a empleados','D',4,'1','1325',TRUE),
('132502','Fondos de caja menor','D',4,'1','1325',TRUE),
('14','INVENTARIOS','D',2,'1','1',FALSE),
('1405','Materiales y suministros','D',3,'1','14',FALSE),
('140501','Papelería y útiles de oficina','D',4,'1','1405',TRUE),
('140502','Materiales de laboratorio','D',4,'1','1405',TRUE),
('140503','Libros y publicaciones','D',4,'1','1405',TRUE),
('1410','Bienes para la venta','D',3,'1','14',FALSE),
('141001','Publicaciones y textos propios','D',4,'1','1410',TRUE),
('15','PROPIEDADES PLANTA Y EQUIPO','D',2,'1','1',FALSE),
('1504','Terrenos','D',3,'1','15',FALSE),
('150401','Terrenos campus universitario','D',4,'1','1504',TRUE),
('1516','Construcciones y edificaciones','D',3,'1','15',FALSE),
('151601','Edificios administrativos','D',4,'1','1516',TRUE),
('151602','Aulas y bloques académicos','D',4,'1','1516',TRUE),
('151603','Laboratorios','D',4,'1','1516',TRUE),
('151604','Biblioteca','D',4,'1','1516',TRUE),
('151605','Bienestar y canchas','D',4,'1','1516',TRUE),
('1520','Maquinaria y equipo','D',3,'1','15',FALSE),
('152001','Equipos de laboratorio','D',4,'1','1520',TRUE),
('152002','Equipos médicos y odontológicos','D',4,'1','1520',TRUE),
('1524','Equipo de cómputo y comunicación','D',3,'1','15',FALSE),
('152401','Computadores y servidores','D',4,'1','1524',TRUE),
('152402','Redes y comunicaciones','D',4,'1','1524',TRUE),
('152403','Equipos audiovisuales','D',4,'1','1524',TRUE),
('1528','Muebles y enseres','D',3,'1','15',FALSE),
('152801','Mobiliario aulas y oficinas','D',4,'1','1528',TRUE),
('152802','Mobiliario biblioteca','D',4,'1','1528',TRUE),
('1532','Flota y equipo de transporte','D',3,'1','15',FALSE),
('153201','Vehículos institucionales','D',4,'1','1532',TRUE),
('1592','Depreciación acumulada','C',3,'1','15',FALSE),
('159201','Dep. acum. edificaciones','C',4,'1','1592',TRUE),
('159202','Dep. acum. equipo de cómputo','C',4,'1','1592',TRUE),
('159203','Dep. acum. maquinaria y equipo','C',4,'1','1592',TRUE),
('159204','Dep. acum. muebles y enseres','C',4,'1','1592',TRUE),
('159205','Dep. acum. vehículos','C',4,'1','1592',TRUE),
('16','INTANGIBLES','D',2,'1','1',FALSE),
('1605','Software y licencias','D',3,'1','16',FALSE),
('160501','Sistema de información académica','D',4,'1','1605',TRUE),
('160502','ERP administrativo-financiero','D',4,'1','1605',TRUE),
('160503','Plataformas e-learning','D',4,'1','1605',TRUE),
('1610','Derechos de autor y patentes','D',3,'1','16',TRUE),
('1695','Amortización acumulada intangibles','C',3,'1','16',TRUE),
('17','ACTIVOS DIFERIDOS','D',2,'1','1',FALSE),
('1705','Gastos pagados por anticipado','D',3,'1','17',FALSE),
('170501','Seguros pagados por anticipado','D',4,'1','1705',TRUE),
('170502','Arrendamientos anticipados','D',4,'1','1705',TRUE),
('1710','Cargos diferidos investigación','D',3,'1','17',TRUE);

-- Clase 2: PASIVOS
INSERT INTO puc_cuentas(codigo,nombre,naturaleza,nivel,clase,codigo_padre,acepta_movimiento) VALUES
('2','PASIVOS','C',1,'2',NULL,FALSE),
('21','OBLIGACIONES FINANCIERAS','C',2,'2','2',FALSE),
('2105','Sobregiros y pagarés','C',3,'2','21',FALSE),
('210501','Sobregiros bancarios','C',4,'2','2105',TRUE),
('210505','Pagarés bancarios','C',4,'2','2105',TRUE),
('22','PROVEEDORES','C',2,'2','2',FALSE),
('2205','Proveedores nacionales','C',3,'2','22',FALSE),
('220501','Proveedores de bienes','C',4,'2','2205',TRUE),
('220502','Proveedores de servicios','C',4,'2','2205',TRUE),
('220503','Proveedores tecnológicos','C',4,'2','2205',TRUE),
('23','CUENTAS POR PAGAR','C',2,'2','2',FALSE),
('2305','Costos y gastos por pagar','C',3,'2','23',FALSE),
('230501','Honorarios por pagar','C',4,'2','2305',TRUE),
('230502','Servicios por pagar','C',4,'2','2305',TRUE),
('230503','Arrendamientos por pagar','C',4,'2','2305',TRUE),
('2335','Retención en la fuente','C',3,'2','23',FALSE),
('233501','Retefuente salarios','C',4,'2','2335',TRUE),
('233502','Retefuente honorarios 10%','C',4,'2','2335',TRUE),
('233503','Retefuente servicios 4%','C',4,'2','2335',TRUE),
('233504','Retefuente compras 2.5%','C',4,'2','2335',TRUE),
('233505','Retefuente arrendamientos 3.5%','C',4,'2','2335',TRUE),
('2336','IVA retenido','C',3,'2','23',FALSE),
('233601','Reteiva 15%','C',4,'2','2336',TRUE),
('233602','Reteiva 100% bienes excluidos','C',4,'2','2336',TRUE),
('2337','ICA retenido','C',3,'2','23',FALSE),
('233701','ReteICA Barranquilla','C',4,'2','2337',TRUE),
('233702','ReteICA otras ciudades','C',4,'2','2337',TRUE),
('2370','Nómina por pagar','C',3,'2','23',FALSE),
('237001','Salarios por pagar docentes','C',4,'2','2370',TRUE),
('237002','Salarios por pagar admin.','C',4,'2','2370',TRUE),
('237003','Cesantías consolidadas','C',4,'2','2370',TRUE),
('237004','Intereses cesantías','C',4,'2','2370',TRUE),
('237005','Vacaciones consolidadas','C',4,'2','2370',TRUE),
('237006','Prima de servicios','C',4,'2','2370',TRUE),
('2380','Aportes parafiscales por pagar','C',3,'2','23',FALSE),
('238001','EPS — aporte empleador','C',4,'2','2380',TRUE),
('238002','ARL','C',4,'2','2380',TRUE),
('238003','Caja compensación familiar','C',4,'2','2380',TRUE),
('238004','SENA','C',4,'2','2380',TRUE),
('238005','ICBF','C',4,'2','2380',TRUE),
('238006','Fondos pensiones — empleador','C',4,'2','2380',TRUE),
('24','IMPUESTOS GRAVÁMENES Y TASAS','C',2,'2','2',FALSE),
('2404','IVA por pagar','C',3,'2','24',FALSE),
('240401','IVA generado 19%','C',4,'2','2404',TRUE),
('240402','IVA descontable','D',4,'2','2404',TRUE),
('2408','ICA por pagar','C',3,'2','24',TRUE),
('2412','Impuesto de renta','C',3,'2','24',FALSE),
('241201','Renta corriente','C',4,'2','2412',TRUE),
('2420','Predial y vehículos','C',3,'2','24',TRUE),
('25','OBLIGACIONES LABORALES','C',2,'2','2',FALSE),
('2510','Cesantías consolidadas LP','C',3,'2','25',TRUE),
('2515','Intereses cesantías LP','C',3,'2','25',TRUE),
('2520','Prima de servicios LP','C',3,'2','25',TRUE),
('2525','Vacaciones LP','C',3,'2','25',TRUE),
('26','PASIVOS ESTIMADOS Y PROVISIONES','C',2,'2','2',FALSE),
('2605','Provisión litigios y demandas','C',3,'2','26',TRUE),
('2610','Provisión obligaciones fiscales','C',3,'2','26',TRUE),
('27','INGRESOS RECIBIDOS POR ANTICIPADO','C',2,'2','2',FALSE),
('2705','Matrículas recibidas por anticipado','C',3,'2','27',FALSE),
('270501','Matrículas anticipadas pregrado','C',4,'2','2705',TRUE),
('270502','Matrículas anticipadas posgrado','C',4,'2','2705',TRUE),
('270503','Derechos académicos anticipados','C',4,'2','2705',TRUE);

-- Clase 3: PATRIMONIO
INSERT INTO puc_cuentas(codigo,nombre,naturaleza,nivel,clase,codigo_padre,acepta_movimiento) VALUES
('3','PATRIMONIO','C',1,'3',NULL,FALSE),
('31','CAPITAL','C',2,'3','3',FALSE),
('3105','Capital suscrito y pagado','C',3,'3','31',TRUE),
('3115','Aportes estatales Ley 30','C',3,'3','31',TRUE),
('32','FONDOS INSTITUCIONALES','C',2,'3','3',FALSE),
('3205','Fondo de desarrollo institucional','C',3,'3','32',FALSE),
('320501','Fondo de investigación','C',4,'3','3205',TRUE),
('320502','Fondo de bienestar','C',4,'3','3205',TRUE),
('320503','Fondo de extensión','C',4,'3','3205',TRUE),
('3210','Donaciones recibidas','C',3,'3','32',TRUE),
('33','RESERVAS','C',2,'3','3',FALSE),
('3305','Reservas obligatorias','C',3,'3','33',TRUE),
('3310','Reservas estatutarias','C',3,'3','33',TRUE),
('36','RESULTADOS DEL EJERCICIO','C',2,'3','3',FALSE),
('3605','Utilidad del ejercicio','C',3,'3','36',TRUE),
('3610','Pérdida del ejercicio','D',3,'3','36',TRUE),
('37','RESULTADOS EJERCICIOS ANTERIORES','C',2,'3','3',FALSE),
('3705','Utilidades acumuladas','C',3,'3','37',TRUE),
('3710','Pérdidas acumuladas','D',3,'3','37',TRUE);

-- Clase 4: INGRESOS
INSERT INTO puc_cuentas(codigo,nombre,naturaleza,nivel,clase,codigo_padre,acepta_movimiento) VALUES
('4','INGRESOS','C',1,'4',NULL,FALSE),
('41','INGRESOS OPERACIONALES — EDUCACIÓN','C',2,'4','4',FALSE),
('4101','Derechos de matrícula','C',3,'4','41',FALSE),
('410101','Matrículas pregrado','C',4,'4','4101',TRUE),
('410102','Matrículas especialización','C',4,'4','4101',TRUE),
('410103','Matrículas maestría','C',4,'4','4101',TRUE),
('410104','Matrículas doctorado','C',4,'4','4101',TRUE),
('410105','Derechos de grado','C',4,'4','4101',TRUE),
('4105','Derechos académicos complementarios','C',3,'4','41',FALSE),
('410501','Bienestar universitario','C',4,'4','4105',TRUE),
('410502','Servicios de biblioteca','C',4,'4','4105',TRUE),
('410503','Uso de laboratorios','C',4,'4','4105',TRUE),
('410504','Seguro estudiantil','C',4,'4','4105',TRUE),
('410505','Servicios tecnológicos académicos','C',4,'4','4105',TRUE),
('4110','Educación continua y extensión','C',3,'4','41',FALSE),
('411001','Diplomados y cursos cortos','C',4,'4','4110',TRUE),
('411002','Educación virtual','C',4,'4','4110',TRUE),
('411003','Seminarios y talleres','C',4,'4','4110',TRUE),
('4115','Certificados y constancias','C',3,'4','41',FALSE),
('411501','Certificados académicos','C',4,'4','4115',TRUE),
('411502','Constancias de estudio','C',4,'4','4115',TRUE),
('42','INGRESOS INVESTIGACIÓN Y EXTENSIÓN','C',2,'4','4',FALSE),
('4201','Convenios de investigación','C',3,'4','42',FALSE),
('420101','Recursos MinCiencias','C',4,'4','4201',TRUE),
('420102','Convenios empresa-universidad','C',4,'4','4201',TRUE),
('420103','Proyectos internacionales','C',4,'4','4201',TRUE),
('4205','Servicios de extensión','C',3,'4','42',FALSE),
('420501','Consultoría y asesoría','C',4,'4','4205',TRUE),
('420502','Servicios de laboratorio externos','C',4,'4','4205',TRUE),
('420503','Arrendamiento instalaciones','C',4,'4','4205',TRUE),
('43','TRANSFERENCIAS Y APORTES ESTADO','C',2,'4','4',FALSE),
('4301','Aportes Nación — Ley 30','C',3,'4','43',FALSE),
('430101','Transferencias corrientes Nación','C',4,'4','4301',TRUE),
('430102','Transferencias de inversión Nación','C',4,'4','4301',TRUE),
('4305','Aportes entidades territoriales','C',3,'4','43',FALSE),
('430501','Transferencias departamento','C',4,'4','4305',TRUE),
('430502','Transferencias municipio','C',4,'4','4305',TRUE),
('48','INGRESOS NO OPERACIONALES','C',2,'4','4',FALSE),
('4805','Financieros','C',3,'4','48',FALSE),
('480501','Intereses bancarios','C',4,'4','4805',TRUE),
('480502','Rendimientos inversiones','C',4,'4','4805',TRUE),
('4815','Donaciones recibidas','C',3,'4','48',FALSE),
('481501','Donaciones en efectivo','C',4,'4','4815',TRUE),
('4835','Recuperaciones y reintegros','C',3,'4','48',FALSE),
('483501','Recuperación provisiones','C',4,'4','4835',TRUE),
('483502','Reintegro costos y gastos','C',4,'4','4835',TRUE),
('4895','Otros ingresos','C',3,'4','48',FALSE),
('489501','Sobrantes en caja','C',4,'4','4895',TRUE),
('489502','Diversos','C',4,'4','4895',TRUE);

-- Clase 5: GASTOS
INSERT INTO puc_cuentas(codigo,nombre,naturaleza,nivel,clase,codigo_padre,acepta_movimiento) VALUES
('5','GASTOS','D',1,'5',NULL,FALSE),
('51','GASTOS PERSONAL ACADÉMICO','D',2,'5','5',FALSE),
('5101','Salarios y honorarios docentes','D',3,'5','51',FALSE),
('510101','Salarios docentes planta TC','D',4,'5','5101',TRUE),
('510102','Salarios docentes planta MT','D',4,'5','5101',TRUE),
('510103','Honorarios docentes cátedra','D',4,'5','5101',TRUE),
('510104','Docentes ocasionales','D',4,'5','5101',TRUE),
('5105','Prestaciones sociales docentes','D',3,'5','51',FALSE),
('510501','Cesantías docentes','D',4,'5','5105',TRUE),
('510502','Intereses cesantías docentes','D',4,'5','5105',TRUE),
('510503','Prima servicios docentes','D',4,'5','5105',TRUE),
('510504','Vacaciones docentes','D',4,'5','5105',TRUE),
('5110','Parafiscales docentes','D',3,'5','51',FALSE),
('511001','EPS docentes','D',4,'5','5110',TRUE),
('511002','ARL docentes','D',4,'5','5110',TRUE),
('511003','Pensiones docentes','D',4,'5','5110',TRUE),
('511004','SENA docentes','D',4,'5','5110',TRUE),
('511005','ICBF docentes','D',4,'5','5110',TRUE),
('511006','Caja compensación docentes','D',4,'5','5110',TRUE),
('52','GASTOS PERSONAL ADMINISTRATIVO','D',2,'5','5',FALSE),
('5201','Salarios administrativos','D',3,'5','52',FALSE),
('520101','Salarios planta administrativa','D',4,'5','5201',TRUE),
('520102','Salarios directivos','D',4,'5','5201',TRUE),
('520103','Honorarios asesores','D',4,'5','5201',TRUE),
('5205','Prestaciones sociales admin.','D',3,'5','52',FALSE),
('520501','Cesantías administrativos','D',4,'5','5205',TRUE),
('520502','Intereses cesantías admin.','D',4,'5','5205',TRUE),
('520503','Prima servicios admin.','D',4,'5','5205',TRUE),
('520504','Vacaciones admin.','D',4,'5','5205',TRUE),
('5210','Parafiscales administrativos','D',3,'5','52',FALSE),
('521001','EPS administrativos','D',4,'5','5210',TRUE),
('521002','ARL administrativos','D',4,'5','5210',TRUE),
('521003','Pensiones administrativos','D',4,'5','5210',TRUE),
('521004','SENA administrativos','D',4,'5','5210',TRUE),
('521005','ICBF administrativos','D',4,'5','5210',TRUE),
('521006','Caja compensación admin.','D',4,'5','5210',TRUE),
('53','GASTOS GENERALES','D',2,'5','5',FALSE),
('5301','Arrendamientos','D',3,'5','53',FALSE),
('530101','Arrendamiento instalaciones','D',4,'5','5301',TRUE),
('530102','Arrendamiento equipos','D',4,'5','5301',TRUE),
('5305','Seguros','D',3,'5','53',FALSE),
('530501','Seguro bienes inmuebles','D',4,'5','5305',TRUE),
('530502','Seguro estudiantil colectivo','D',4,'5','5305',TRUE),
('530503','Seguro vida colectivo','D',4,'5','5305',TRUE),
('5310','Servicios públicos','D',3,'5','53',FALSE),
('531001','Energía eléctrica','D',4,'5','5310',TRUE),
('531002','Acueducto y alcantarillado','D',4,'5','5310',TRUE),
('531003','Telefonía e internet','D',4,'5','5310',TRUE),
('531004','Gas natural','D',4,'5','5310',TRUE),
('5315','Mantenimiento y reparaciones','D',3,'5','53',FALSE),
('531501','Mantenimiento edificios','D',4,'5','5315',TRUE),
('531502','Mantenimiento laboratorios','D',4,'5','5315',TRUE),
('531503','Mantenimiento equipos cómputo','D',4,'5','5315',TRUE),
('531504','Mantenimiento vehículos','D',4,'5','5315',TRUE),
('5320','Gastos de viaje','D',3,'5','53',FALSE),
('532001','Tiquetes y transporte','D',4,'5','5320',TRUE),
('532002','Viáticos nacionales','D',4,'5','5320',TRUE),
('532003','Viáticos internacionales','D',4,'5','5320',TRUE),
('5325','Depreciaciones','D',3,'5','53',FALSE),
('532501','Dep. edificios y construcciones','D',4,'5','5325',TRUE),
('532502','Dep. equipo de cómputo','D',4,'5','5325',TRUE),
('532503','Dep. maquinaria y equipo','D',4,'5','5325',TRUE),
('532504','Dep. muebles y enseres','D',4,'5','5325',TRUE),
('5330','Amortizaciones','D',3,'5','53',FALSE),
('533001','Amort. software académico','D',4,'5','5330',TRUE),
('533002','Amort. investigaciones diferidas','D',4,'5','5330',TRUE),
('5335','Provisiones','D',3,'5','53',FALSE),
('533501','Provisión cartera matrículas','D',4,'5','5335',TRUE),
('533502','Provisión litigios','D',4,'5','5335',TRUE),
('5340','Materiales y suministros','D',3,'5','53',FALSE),
('534001','Papelería y útiles','D',4,'5','5340',TRUE),
('534002','Materiales de laboratorio','D',4,'5','5340',TRUE),
('534003','Libros y publicaciones','D',4,'5','5340',TRUE),
('534004','Dotación y uniformes','D',4,'5','5340',TRUE),
('5345','Gastos académicos especiales','D',3,'5','53',FALSE),
('534501','Becas y auxilios estudiantiles','D',4,'5','5345',TRUE),
('534502','Descuentos matrícula otorgados','D',4,'5','5345',TRUE),
('534503','Movilidad académica','D',4,'5','5345',TRUE),
('534504','Acreditación y certificaciones','D',4,'5','5345',TRUE),
('5350','Comunicaciones y mercadeo','D',3,'5','53',FALSE),
('535001','Publicidad y propaganda','D',4,'5','5350',TRUE),
('535002','Suscripciones y afiliaciones','D',4,'5','5350',TRUE),
('535003','Correo y mensajería','D',4,'5','5350',TRUE),
('5355','Servicios contratados','D',3,'5','53',FALSE),
('535501','Aseo y vigilancia','D',4,'5','5355',TRUE),
('535502','Cafetería y casino','D',4,'5','5355',TRUE),
('535503','Procesamiento electrónico datos','D',4,'5','5355',TRUE),
('54','GASTOS DE INVESTIGACIÓN','D',2,'5','5',FALSE),
('5401','Proyectos de investigación','D',3,'5','54',FALSE),
('540101','Investigación básica','D',4,'5','5401',TRUE),
('540102','Investigación aplicada','D',4,'5','5401',TRUE),
('540103','Desarrollo tecnológico','D',4,'5','5401',TRUE),
('5405','Divulgación científica','D',3,'5','54',FALSE),
('540501','Publicaciones indexadas','D',4,'5','5405',TRUE),
('540502','Eventos y congresos','D',4,'5','5405',TRUE),
('55','GASTOS BIENESTAR UNIVERSITARIO','D',2,'5','5',FALSE),
('5501','Programas de bienestar','D',3,'5','55',FALSE),
('550101','Actividades culturales y deportivas','D',4,'5','5501',TRUE),
('550102','Servicios médicos y psicológicos','D',4,'5','5501',TRUE),
('550103','Apoyo alimentario estudiantes','D',4,'5','5501',TRUE),
('58','IMPUESTOS Y GRAVÁMENES','D',2,'5','5',FALSE),
('5804','IVA no descontable','D',3,'5','58',TRUE),
('5808','Impuesto ICA','D',3,'5','58',TRUE),
('5812','Impuesto predial','D',3,'5','58',TRUE),
('59','GASTOS FINANCIEROS','D',2,'5','5',FALSE),
('5905','Gastos bancarios','D',3,'5','59',FALSE),
('590501','Comisiones bancarias','D',4,'5','5905',TRUE),
('590502','GMF cuatro por mil','D',4,'5','5905',TRUE),
('5910','Intereses y mora','D',3,'5','59',FALSE),
('591001','Intereses sobre créditos','D',4,'5','5910',TRUE),
('591002','Intereses mora proveedores','D',4,'5','5910',TRUE);

-- Centros de costo
INSERT INTO centros_costo(codigo,nombre,tipo) VALUES
('CC-RECT','Rectoría','administrativo'),
('CC-VIAC','Vicerrectoría Académica','academico'),
('CC-VIAD','Vicerrectoría Administrativa','administrativo'),
('CC-FINZ','Dependencia Financiera','administrativo'),
('CC-ADM','Administración de Empresas','academico'),
('CC-DER','Facultad de Derecho','academico'),
('CC-ING','Facultad de Ingeniería de Sistemas','academico'),
('CC-MED','Facultad de Medicina','academico'),
('CC-PSI','Facultad de Psicología','academico'),
('CC-CON','Facultad de Contaduría','academico'),
('CC-BIEC','Bienestar Universitario','bienestar'),
('CC-INV','Instituto de Investigaciones','investigacion'),
('CC-EXT','Extensión y Educación Continua','extension'),
('CC-BIB','Biblioteca','academico'),
('CC-TI','Sistemas y TI','administrativo'),
('CC-MAN','Mantenimiento Planta Física','administrativo');

-- Configuración de mapeo de cuentas del sistema
INSERT INTO config_cuentas_sistema(concepto,cuenta_codigo,descripcion) VALUES
('cxc_matriculas_pregrado','131001','CxC matrículas pregrado'),
('cxc_matriculas_posgrado','131002','CxC matrículas posgrado'),
('cxc_derechos_academicos','131003','CxC derechos académicos'),
('provision_cartera','131501','Provisión cartera matrículas'),
('caja_general','11050101','Caja general principal'),
('bancos_cte','11100501','Bancolombia cta cte'),
('bancos_ahorros','11101001','Bancolombia ahorros'),
('ingreso_matricula_pre','410101','Matrículas pregrado'),
('ingreso_matricula_pos','410102','Matrículas especialización'),
('ingreso_bienestar','410501','Bienestar universitario'),
('ingreso_biblioteca','410502','Servicios de biblioteca'),
('ingreso_laboratorio','410503','Uso de laboratorios'),
('ingreso_seguro','410504','Seguro estudiantil'),
('gasto_descuento_mat','534502','Descuentos matrícula otorgados'),
('gasto_becas','534501','Becas y auxilios estudiantiles'),
('gasto_provision_cart','533501','Provisión cartera matrículas'),
('retefuente_honorarios','233502','Retefuente honorarios 10%'),
('retefuente_servicios','233503','Retefuente servicios 4%'),
('retefuente_compras','233504','Retefuente compras 2.5%');

-- ############################################################
-- # PARTE 4 — DATOS INICIALES DEL SISTEMA DE CARTERA
-- ############################################################

-- Usuario administrador y de prueba (password: password)
INSERT INTO usuarios (username, password_hash, nombre, apellido, email, rol) VALUES
('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'Sistema', 'admin@universidad.edu.co', 'admin'),
('financiero1', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'María', 'González', 'financiero@universidad.edu.co', 'financiero'),
('cajero1', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Carlos', 'Martínez', 'cajero@universidad.edu.co', 'cajero');

-- Medios de pago
INSERT INTO medios_pago (codigo, nombre, requiere_referencia) VALUES
('EFECTIVO', 'Efectivo en Caja', FALSE),
('TRANSF', 'Transferencia Bancaria', TRUE),
('PSE', 'PSE - Pagos en Línea', TRUE),
('CHEQUE', 'Cheque', TRUE),
('TARJETA', 'Tarjeta Débito/Crédito', TRUE),
('CONVENIO', 'Convenio Empresarial', TRUE);

-- Programas académicos
INSERT INTO programas (codigo, nombre, facultad, nivel, semestres_duracion) VALUES
('ADM-001', 'Administración de Empresas', 'Ciencias Económicas y Administrativas', 'pregrado', 8),
('DER-001', 'Derecho', 'Ciencias Jurídicas y Políticas', 'pregrado', 10),
('ING-001', 'Ingeniería de Sistemas', 'Ingeniería', 'pregrado', 10),
('MED-001', 'Medicina', 'Ciencias de la Salud', 'pregrado', 12),
('PSI-001', 'Psicología', 'Ciencias Humanas y Sociales', 'pregrado', 10),
('CON-001', 'Contaduría Pública', 'Ciencias Económicas y Administrativas', 'pregrado', 8),
('MAE-ADM', 'Maestría en Administración', 'Ciencias Económicas y Administrativas', 'maestria', 4),
('ESP-DER', 'Especialización en Derecho Comercial', 'Ciencias Jurídicas y Políticas', 'especializacion', 2);

-- Período académico vigente
INSERT INTO periodos (codigo, nombre, fecha_inicio, fecha_fin, fecha_vencimiento_pago, activo) VALUES
('2024-2', 'Segundo Semestre 2024', '2024-07-15', '2024-12-15', '2024-08-15', FALSE),
('2025-1', 'Primer Semestre 2025', '2025-01-20', '2025-06-20', '2025-02-20', FALSE),
('2025-2', 'Segundo Semestre 2025', '2025-07-14', '2025-12-13', '2025-08-14', TRUE);

-- Conceptos de cobro (basados en SMMLV Colombia 2025 = $1,423,500)
INSERT INTO conceptos_cobro (codigo, nombre, tipo, aplica_smmlv, porcentaje_smmlv, valor_fijo) VALUES
('MAT-PRE', 'Matrícula Pregrado', 'matricula', TRUE, 150.00, NULL),
('MAT-POS', 'Matrícula Posgrado', 'matricula', TRUE, 300.00, NULL),
('BIENESTAR', 'Derechos de Bienestar', 'derechos_academicos', FALSE, NULL, 85000),
('BIBLIOTECA', 'Servicios de Biblioteca', 'servicios', FALSE, NULL, 45000),
('LABORATORIO', 'Uso de Laboratorios', 'servicios', FALSE, NULL, 120000),
('SEGURO', 'Seguro Estudiantil', 'servicios', FALSE, NULL, 35000),
('CARNET', 'Carné Estudiantil', 'servicios', FALSE, NULL, 25000),
('DESC-ICETEX', 'Descuento ICETEX', 'descuento', TRUE, -50.00, NULL),
('DESC-BECA', 'Beca Académica', 'descuento', TRUE, -100.00, NULL),
('DESC-HERMANO', 'Descuento Hermano', 'descuento', FALSE, NULL, -200000);

-- Estudiantes de ejemplo
INSERT INTO estudiantes (codigo, tipo_documento, numero_documento, primer_nombre, primer_apellido, segundo_apellido, email, email_institucional, celular, estrato, programa_id, semestre_actual, estado, fecha_ingreso) VALUES
('20230001', 'CC', '1098765432', 'Laura', 'Rodríguez', 'García', 'laura.rodriguez@gmail.com', 'l.rodriguez@universidad.edu.co', '3101234567', 3, 1, 4, 'activo', '2023-01-23'),
('20230002', 'CC', '1020304050', 'Andrés', 'Pérez', 'López', 'andres.perez@outlook.com', 'a.perez@universidad.edu.co', '3157654321', 2, 3, 3, 'activo', '2023-01-23'),
('20220015', 'CC', '1143526789', 'Valentina', 'Torres', 'Mora', 'valentina.torres@gmail.com', 'v.torres@universidad.edu.co', '3209876543', 4, 4, 7, 'activo', '2022-07-18'),
('20240088', 'TI', '1012345678', 'Santiago', 'Gómez', 'Ramos', 'santiago.gomez@gmail.com', 's.gomez@universidad.edu.co', '3142345678', 1, 2, 1, 'activo', '2024-07-15'),
('20190033', 'CC', '52876543', 'Carmen', 'Hernández', 'Díaz', 'carmen.hdz@hotmail.com', 'c.hernandez@universidad.edu.co', '3016543210', 3, 7, 2, 'activo', '2024-07-15');

-- ############################################################
-- # PARTE 5 — SALDOS INICIALES (Comprobante de Apertura)
-- #   Ejemplo de saldos de apertura del ejercicio contable
-- #   (Ajustar valores según balance real de la institución)
-- ############################################################

DO $$
DECLARE
    v_comp_id INT;
    v_numero VARCHAR;
    v_periodo VARCHAR := TO_CHAR(CURRENT_DATE,'YYYY-MM');
BEGIN
    v_numero := fn_numero_comprobante('apertura', CURRENT_DATE);

    INSERT INTO comprobantes (numero, tipo, fecha, periodo_contable, descripcion, estado, es_automatico)
    VALUES (v_numero, 'apertura', CURRENT_DATE, v_periodo,
            'Saldos de apertura del ejercicio contable', 'contabilizado', FALSE)
    RETURNING id INTO v_comp_id;

    -- Activos (Débito)
    INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, descripcion, debito) VALUES
    (v_comp_id, 1, '11050101', 'Saldo inicial caja principal', 15000000),
    (v_comp_id, 2, '11100501', 'Saldo inicial Bancolombia cta cte', 250000000),
    (v_comp_id, 3, '11101001', 'Saldo inicial Bancolombia ahorros', 180000000),
    (v_comp_id, 4, '151602', 'Saldo inicial aulas y bloques académicos', 3500000000),
    (v_comp_id, 5, '152401', 'Saldo inicial equipos de cómputo', 420000000),
    (v_comp_id, 6, '160501', 'Saldo inicial sistema de información académica', 80000000);

    -- Depreciación acumulada y pasivos/patrimonio (Crédito)
    INSERT INTO movimientos_contables (comprobante_id, linea, cuenta_codigo, descripcion, credito) VALUES
    (v_comp_id, 7, '159202', 'Depreciación acumulada equipos cómputo', 150000000),
    (v_comp_id, 8, '220501', 'Saldo inicial proveedores de bienes', 45000000),
    (v_comp_id, 9, '237001', 'Saldo inicial nómina docentes por pagar', 120000000),
    (v_comp_id, 10, '3115', 'Aportes estatales Ley 30 (patrimonio)', 1850000000),
    (v_comp_id, 11, '3705', 'Utilidades acumuladas ejercicios anteriores', 2160000000);

    PERFORM fn_recalcular_comprobante(v_comp_id);
END $$;

-- ============================================================
-- FIN DEL SCRIPT — Base de datos lista para usar
-- ============================================================

-- ============================================================
-- AMPLIACIÓN: PATROCINADORES, CRÉDITOS, MORA
-- Módulos adicionales del sistema de cartera
-- ============================================================

CREATE TABLE IF NOT EXISTS patrocinadores (
    id                SERIAL PRIMARY KEY,
    tipo              VARCHAR(20) CHECK (tipo IN ('empresa','entidad_publica','fundacion','icetex','convenio','otro')) DEFAULT 'empresa',
    nit               VARCHAR(20),
    nombre            VARCHAR(200) NOT NULL,
    contacto_nombre   VARCHAR(150),
    contacto_email    VARCHAR(150),
    contacto_telefono VARCHAR(20),
    porcentaje_max    NUMERIC(5,2),
    valor_max_periodo NUMERIC(15,2),
    requiere_soporte  BOOLEAN DEFAULT TRUE,
    observaciones     TEXT,
    activo            BOOLEAN DEFAULT TRUE,
    created_at        TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS patrocinios (
    id               SERIAL PRIMARY KEY,
    estudiante_id    INT REFERENCES estudiantes(id) NOT NULL,
    patrocinador_id  INT REFERENCES patrocinadores(id) NOT NULL,
    periodo_id       INT REFERENCES periodos(id),
    factura_id       INT REFERENCES facturas(id),
    porcentaje       NUMERIC(5,2),
    valor            NUMERIC(15,2) NOT NULL,
    fecha_inicio     DATE,
    fecha_fin        DATE,
    soporte_doc      VARCHAR(255),
    estado           VARCHAR(20) CHECK (estado IN ('vigente','pagado','cancelado','vencido')) DEFAULT 'vigente',
    aprobado_por     INT REFERENCES usuarios(id),
    observaciones    TEXT,
    created_at       TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS creditos_estudiantiles (
    id                  SERIAL PRIMARY KEY,
    estudiante_id       INT REFERENCES estudiantes(id) NOT NULL,
    tipo                VARCHAR(30) CHECK (tipo IN ('icetex','interno','banco','otro')) DEFAULT 'interno',
    entidad             VARCHAR(150),
    numero_credito      VARCHAR(50),
    monto_aprobado      NUMERIC(15,2) NOT NULL,
    monto_desembolsado  NUMERIC(15,2) DEFAULT 0,
    tasa_interes        NUMERIC(6,4) DEFAULT 0,
    plazo_meses         INT,
    fecha_aprobacion    DATE,
    fecha_inicio        DATE,
    fecha_vencimiento   DATE,
    estado              VARCHAR(20) CHECK (estado IN ('aprobado','vigente','pagado','mora','cancelado')) DEFAULT 'aprobado',
    soporte_doc         VARCHAR(255),
    observaciones       TEXT,
    aprobado_por        INT REFERENCES usuarios(id),
    created_at          TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS desembolsos_credito (
    id             SERIAL PRIMARY KEY,
    credito_id     INT REFERENCES creditos_estudiantiles(id) ON DELETE CASCADE,
    factura_id     INT REFERENCES facturas(id),
    fecha          DATE NOT NULL DEFAULT CURRENT_DATE,
    valor          NUMERIC(15,2) NOT NULL,
    descripcion    VARCHAR(300),
    registrado_por INT REFERENCES usuarios(id),
    created_at     TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS config_mora (
    id             SERIAL PRIMARY KEY,
    nombre         VARCHAR(100) DEFAULT 'Configuración vigente',
    tasa_diaria    NUMERIC(8,6) DEFAULT 0.0,
    tasa_mensual   NUMERIC(8,4) DEFAULT 0.0,
    dias_gracia    INT DEFAULT 0,
    aplica_a       VARCHAR(20) CHECK (aplica_a IN ('todas','vencidas')) DEFAULT 'vencidas',
    activa         BOOLEAN DEFAULT FALSE,
    vigente_desde  DATE,
    created_at     TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS mora_registrada (
    id              SERIAL PRIMARY KEY,
    factura_id      INT REFERENCES facturas(id) NOT NULL,
    fecha_calculo   DATE NOT NULL DEFAULT CURRENT_DATE,
    dias_mora       INT NOT NULL,
    capital_vencido NUMERIC(15,2) NOT NULL,
    valor_mora      NUMERIC(15,2) NOT NULL,
    tasa_aplicada   NUMERIC(8,6) NOT NULL,
    cobrada         BOOLEAN DEFAULT FALSE,
    pago_id         INT REFERENCES pagos(id),
    created_at      TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_patrocinios_est ON patrocinios(estudiante_id);
CREATE INDEX IF NOT EXISTS idx_patrocinios_pat ON patrocinios(patrocinador_id);
CREATE INDEX IF NOT EXISTS idx_creditos_est    ON creditos_estudiantiles(estudiante_id);
CREATE INDEX IF NOT EXISTS idx_mora_factura    ON mora_registrada(factura_id);

-- Función: calcular mora automática
CREATE OR REPLACE FUNCTION fn_calcular_mora(p_fecha DATE DEFAULT CURRENT_DATE)
RETURNS TABLE (
    factura_id     INT,
    numero_factura VARCHAR,
    estudiante     VARCHAR,
    saldo          NUMERIC,
    dias_mora      INT,
    valor_mora     NUMERIC
) AS $$
DECLARE v_config config_mora%ROWTYPE;
BEGIN
    SELECT * INTO v_config FROM config_mora WHERE activa=TRUE ORDER BY id DESC LIMIT 1;
    IF v_config.id IS NULL OR v_config.tasa_diaria=0 THEN RETURN; END IF;
    RETURN QUERY
    SELECT f.id, f.numero_factura,
           (e.primer_nombre||' '||e.primer_apellido)::VARCHAR,
           f.saldo,
           GREATEST(0,(p_fecha-f.fecha_vencimiento)-v_config.dias_gracia)::INT,
           ROUND(f.saldo*v_config.tasa_diaria/100.0*GREATEST(0,(p_fecha-f.fecha_vencimiento)-v_config.dias_gracia),0)
    FROM facturas f JOIN estudiantes e ON e.id=f.estudiante_id
    WHERE f.estado IN ('vencida','parcial') AND f.fecha_vencimiento<p_fecha
      AND (p_fecha-f.fecha_vencimiento)>v_config.dias_gracia AND f.saldo>0;
END;
$$ LANGUAGE plpgsql;

-- Datos semilla
INSERT INTO config_mora (nombre,tasa_diaria,tasa_mensual,dias_gracia,aplica_a,activa,vigente_desde)
VALUES ('Mora bancaria corriente',0.082200,2.5,5,'vencidas',FALSE,CURRENT_DATE)
ON CONFLICT DO NOTHING;

INSERT INTO patrocinadores (tipo,nit,nombre,contacto_email,porcentaje_max,activo) VALUES
('icetex','830125996-5','ICETEX','atencion@icetex.gov.co',100.00,TRUE),
('entidad_publica','899999034-1','Ministerio de Educación Nacional','becas@mineducacion.gov.co',100.00,TRUE),
('fundacion','860017570-1','Fundación Bavaria','becas@fundacionbavaria.org',75.00,TRUE),
('empresa','900123456-7','Empresa Demo S.A.S.','convenios@empresademo.com',50.00,TRUE)
ON CONFLICT DO NOTHING;

-- ============================================================
-- MEJORAS BASADAS EN FORMATOS U-PCA
-- Formatos_Credito_y_Cartera.xlsx — Julio 2025
-- ============================================================

-- ── 1. Tipo de admisión en estudiantes ───────────────────────
ALTER TABLE estudiantes ADD COLUMN IF NOT EXISTS
    tipo_admision VARCHAR(30) CHECK (tipo_admision IN (
        'nuevo','reintegro','transferencia_externa','transferencia_interna',
        'ciclo_propedeutico','otro'
    )) DEFAULT 'nuevo';

ALTER TABLE estudiantes ADD COLUMN IF NOT EXISTS
    opcion_grado VARCHAR(30) CHECK (opcion_grado IN (
        'por_programa','por_semestre','otro'
    ));

-- ── 2. Configuración institucional (NIT, DIAN, factura electrónica) ──
CREATE TABLE IF NOT EXISTS config_institucion (
    id                   SERIAL PRIMARY KEY,
    nombre               VARCHAR(300) NOT NULL DEFAULT 'Universidad',
    nit                  VARCHAR(30),
    regimen_tributario   VARCHAR(100) DEFAULT 'Régimen Tributario Especial Art 19 ET',
    responsable_iva      BOOLEAN DEFAULT FALSE,
    direccion            VARCHAR(300),
    telefono             VARCHAR(50),
    email                VARCHAR(150),
    ciudad               VARCHAR(100) DEFAULT 'Barranquilla',
    -- Factura electrónica DIAN
    prefijo_factura      VARCHAR(10) DEFAULT 'FAC',
    resolucion_dian      VARCHAR(100),
    rango_desde          INT DEFAULT 1,
    rango_hasta          INT DEFAULT 50000,
    vigencia_desde       DATE,
    vigencia_hasta       DATE,
    -- Logo
    logo_url             VARCHAR(255),
    activa               BOOLEAN DEFAULT TRUE,
    created_at           TIMESTAMP DEFAULT NOW()
);

INSERT INTO config_institucion (nombre, nit, regimen_tributario, responsable_iva,
    direccion, telefono, email, ciudad, prefijo_factura, resolucion_dian,
    rango_desde, rango_hasta, vigencia_desde, vigencia_hasta)
VALUES (
    'Corporación Universitaria Politécnico de la Costa Atlántica U-PCA',
    '800.036.652-1',
    'Régimen Tributario Especial Art 19 ET - No Responsable IVA Art 476 ET',
    FALSE,
    'Cra. 38 # 79A - 167',
    '(605) 3198672',
    'info@pca.edu.co',
    'Barranquilla - Colombia',
    'PCA',
    'Autorización 187600010005 de 2025-01-07',
    1, 50000,
    '2025-01-07', '2026-01-07'
) ON CONFLICT DO NOTHING;

-- ── 3. Centros de costo U-PCA completos (por Acuerdo CD No.087/2024) ──
-- Limpiar y recargar con la estructura real
TRUNCATE centros_costo RESTART IDENTITY CASCADE;

INSERT INTO centros_costo (codigo, nombre, tipo) VALUES
-- Sala General
('00',  'Sala General',                                    'administrativo'),
('001', 'Presidencia',                                     'administrativo'),
('003', 'Revisoría Fiscal',                                'administrativo'),
('005', 'Consejo Directivo',                               'administrativo'),
('007', 'Consejo Académico',                               'administrativo'),
('009', 'Consejo de Investigación',                        'administrativo'),
-- Rectoría
('10',  'Rectoría',                                        'administrativo'),
('101', 'Despacho Rector',                                 'administrativo'),
('102', 'Secretaría General',                              'administrativo'),
('11',  'Infraestructura Física',                          'administrativo'),
('111', 'Gerencia de Infraestructura Física',              'administrativo'),
('112', 'Personal de Aseo y Cafetería',                    'administrativo'),
('113', 'Sección de Mantenimiento',                        'administrativo'),
('12',  'Planeación Institucional',                        'administrativo'),
('121', 'Unidad de Planeación Institucional',              'administrativo'),
('130', 'Evaluación y Aseguramiento de la Calidad',        'administrativo'),
('131', 'Centro de Evaluación y Aseguramiento',            'administrativo'),
('14',  'Mercadeo y Comunicaciones',                       'administrativo'),
('141', 'Centro de Mercadeo y Comunicaciones',             'administrativo'),
('15',  'Tecnologías de la Información',                   'administrativo'),
('151', 'Director TIC',                                    'administrativo'),
('152', 'Canal Interno',                                   'administrativo'),
('153', 'Centro de Recursos Informáticos',                 'administrativo'),
('154', 'Sección Talleres y Laboratorios',                 'administrativo'),
('16',  'Departamento de Idiomas',                         'academico'),
('161', 'Instituto de Idiomas',                            'academico'),
('163', 'Departamento de Idiomas',                         'academico'),
-- Vicerrectoría Académica
('20',  'Vicerrectoría Académica y de Investigación',      'academico'),
('201', 'Unidad de Planeación y Gestión Académica',        'academico'),
('203', 'Comité Académico de Selección y Evaluación',      'academico'),
('205', 'Unidad de Virtualización',                        'academico'),
('21',  'Investigación y Desarrollo Tecnológico CINDETP',  'investigacion'),
('211', 'Unidad de Investigación CINDETP',                 'investigacion'),
('212', 'Comité Científico y de Ética',                    'investigacion'),
('213', 'Desarrollo Empresarial',                          'extension'),
('22',  'Humanidades y Ciencias Sociales',                 'academico'),
('221', 'Dpto Humanidades y Ciencias Sociales',            'academico'),
('223', 'Departamento de Ciencias Básicas',                'academico'),
('23',  'Admisiones, Registro y Control Académico',        'administrativo'),
('231', 'Oficina de Admisiones, Registro y Control',       'administrativo'),
('233', 'Centro Digital',                                  'administrativo'),
('235', 'Centro de Estudios Pedagógicos',                  'academico'),
('237', 'Biblioteca y Hemeroteca',                         'academico'),
-- Programas Tecnológicos Ciencias Administrativas
('25',  'Facultad Tecnológica Ciencias Administrativas',   'academico'),
('251', 'Facultad de Ciencias Económicas - Tecnología',    'academico'),
('252', 'Tecnología en Gestión Contable (52936)',           'academico'),
('253', 'Tecnología en Gestión Financiera (52903)',         'academico'),
('254', 'Tecnología en Gestión Logística Internacional',   'academico'),
('255', 'Tecnología en Gestión de Negocios Internacionales','academico'),
('256', 'Tecnología en Gestión de Exportaciones',          'academico'),
-- Facultad Ingeniería Tecnológica
('26',  'Facultad de Ingeniería y Afines',                 'academico'),
('261', 'Decano Facultad de Ingeniería',                   'academico'),
('262', 'Tecnología en Gestión y Analítica de Datos',      'academico'),
('263', 'Tecnología en Desarrollo Sistemas Electrónicos',  'academico'),
('264', 'Tecnología en Procesos Industriales (54060)',      'academico'),
('265', 'Tecnología en Desarrollo de Software (104475)',   'academico'),
-- Facultad Mercadeo Tecnológica
('27',  'Facultad de Mercadeo y Publicidad Tecnológica',   'academico'),
('271', 'Decano Mercadotecnia y Publicidad',               'academico'),
('272', 'Tecnología en Gestión de Mercadeo (52792)',        'academico'),
('273', 'Tecnología en Mercadeo y Publicidad',             'academico'),
('274', 'Tecnología en Producción Gráfica Digital',        'academico'),
('275', 'Tecnología en Gestión de Publicidad y Medios',    'academico'),
-- Facultad Ciencias Humanas Tecnológica
('28',  'Facultad de Ciencias Humanas Tecnológica',        'academico'),
('281', 'Decano Facultad de Ciencias Humanas',             'academico'),
('282', 'Tecnología en Gestión Humana (111692)',            'academico'),
('283', 'Tecnología en Gestión del Talento Humano',        'academico'),
('284', 'Tecnología en Gestión SST (108652)',               'academico'),
-- Programas Profesionales
('3',   'Programas Universitarios',                        'academico'),
('301', 'Comité Curricular de Programas',                  'academico'),
('35',  'Facultad de Ciencias Económicas Profesional',     'academico'),
('351', 'Decano Facultad de Ciencias Económicas',          'academico'),
('352', 'Contaduría Pública (SNIES 52935)',                 'academico'),
('353', 'Administración de Empresas (SNIES 53538)',         'academico'),
('354', 'Administración Logística (SNIES 106376)',          'academico'),
('355', 'Administración de Negocios Internacionales',      'academico'),
-- Facultad Ingeniería Profesional
('37',  'Facultad de Ingeniería Profesional',              'academico'),
('371', 'Decano Facultad de Ingeniería',                   'academico'),
('372', 'Ingeniería en Ciencia de Datos',                  'academico'),
('373', 'Ingeniería Electrónica (SNIES 53065)',             'academico'),
('374', 'Ingeniería Industrial (SNIES 54088)',              'academico'),
('375', 'Ingeniería de Sistemas (SNIES 53534)',             'academico'),
('379', 'Comité Curricular Ingeniería',                    'academico'),
-- Facultad Mercadeo Profesional
('38',  'Facultad de Mercadeo y Publicidad Profesional',   'academico'),
('381', 'Decano Mercadeo y Publicidad',                    'academico'),
('385', 'Publicidad (SNIES 106256)',                        'academico'),
('386', 'Administración de Mercadeo (SNIES 52951)',         'academico'),
-- Facultad Ciencias Humanas Profesional
('39',  'Facultad de Ciencias Humanas Profesional',        'academico'),
('391', 'Decano Facultad de Ciencias Humanas',             'academico'),
('393', 'Administración de Talento Humano (SNIES 111693)', 'academico'),
('394', 'Seguridad y Salud en el Trabajo (SNIES 109072)',  'academico'),
-- Posgrado
('4',   'Posgrado',                                        'academico'),
('401', 'Comité Curricular de Programas Posgrado',         'academico'),
('45',  'Facultad de Ciencias Económicas Posgrado',        'academico'),
('451', 'Decano Ciencias Económicas Posgrado',             'academico'),
('452', 'Especialización en Gerencia Tributaria',          'academico'),
('453', 'Especialización en Gerencia Financiera (116427)', 'academico'),
('454', 'Especialización Gerencia Logística y RRNN',       'academico'),
('456', 'Especialización en Gerencia de Proyectos (116423)','academico'),
('48',  'Facultad de Mercadeo y Publicidad Posgrado',      'academico'),
('481', 'Decano Mercadeo y Publicidad Posgrado',           'academico'),
('483', 'Especialización en Gerencia de Mercadeo (116425)','academico'),
-- Vicerrectoría Extensión
('50',  'Vicerrectoría Extensión y Proyección Social',     'extension'),
('501', 'Vicerrector de Extensión',                        'extension'),
('502', 'Comité de Extensión y Proyección Social',         'extension'),
('511', 'Oficina de Relaciones Internacionales',           'extension'),
('521', 'CENDEP - Educación Continuada y Permanente',      'extension'),
('531', 'Oficina de Egresados',                            'extension'),
-- Vicerrectoría Administrativa
('55',  'Vicerrectoría Administrativa y Financiera',       'administrativo'),
('551', 'Vicerrector Administrativo y Financiero',         'administrativo'),
('552', 'Comité Administrativo y Financiero',              'administrativo'),
('561', 'Unidad Administrativa',                           'administrativo'),
('562', 'Gestión Humana',                                  'administrativo'),
('563', 'SGSST y Medio Ambiente',                          'administrativo'),
('564', 'Archivo Institucional',                           'administrativo'),
('571', 'Dirección Contable',                              'administrativo'),
('572', 'Sección de Crédito y Cartera',                    'administrativo'),
('573', 'Sección de Tesorería y Pagaduría',                'administrativo'),
('574', 'Nómina y Prestaciones Sociales',                  'administrativo'),
('575', 'Almacén y Suministros',                           'administrativo'),
-- Bienestar
('581', 'Unidad de Bienestar Institucional',               'bienestar'),
('582', 'Permanencia y Acompañamiento',                    'bienestar'),
('583', 'Salud y Bienestar',                               'bienestar'),
('584', 'Cultura y Desarrollo Artístico',                  'bienestar'),
('585', 'Deportes y Recreación',                           'bienestar'),
-- Convenios Becas
('99',  'Convenio Becas Universitarias',                   'academico'),
('991', 'Becas Grupos Indígenas, Afrodescendientes y ROM', 'academico'),
('992', 'Becas Grupos Familiares',                         'academico'),
('993', 'Becas Empleados e Hijos U-PCA',                   'academico'),
('994', 'Becas Fuerzas Militares',                         'academico'),
('995', 'Becas Institucionales',                           'academico')
ON CONFLICT (codigo) DO NOTHING;

-- ── 4. Vincular programas con centros de costo SNIES ─────────
ALTER TABLE programas ADD COLUMN IF NOT EXISTS centro_costo_codigo VARCHAR(20) REFERENCES centros_costo(codigo);
ALTER TABLE programas ADD COLUMN IF NOT EXISTS codigo_snies VARCHAR(20);

-- ── 5. Mejoras tabla creditos_estudiantiles ───────────────────
ALTER TABLE creditos_estudiantiles ADD COLUMN IF NOT EXISTS tasa_mora_mensual NUMERIC(6,4) DEFAULT 0;
ALTER TABLE creditos_estudiantiles ADD COLUMN IF NOT EXISTS tipo_credito_interno VARCHAR(30)
    CHECK (tipo_credito_interno IN ('consumo','matricula','alimentacion','materiales','otro')) DEFAULT 'matricula';

-- ── 6. Mejorar cuotas_acuerdo para tabla de amortización real ─
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS fecha_pago_real DATE;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS valor_capital NUMERIC(15,2) DEFAULT 0;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS valor_interes NUMERIC(15,2) DEFAULT 0;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS tasa_interes_aplicada NUMERIC(8,6) DEFAULT 0;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS dias_mora INT DEFAULT 0;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS valor_mora NUMERIC(15,2) DEFAULT 0;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS valor_total_pagar NUMERIC(15,2) DEFAULT 0;
ALTER TABLE cuotas_acuerdo ADD COLUMN IF NOT EXISTS saldo_capital NUMERIC(15,2) DEFAULT 0;

-- ── 7. Tabla de importación de cartera inicial ────────────────
CREATE TABLE IF NOT EXISTS cartera_inicial_importada (
    id               SERIAL PRIMARY KEY,
    cuenta_puc       VARCHAR(20),
    id_tercero       VARCHAR(20)  NOT NULL,   -- CC del estudiante
    nombre_estudiante VARCHAR(200),
    nombre_patrocinador VARCHAR(200),
    id_patrocinador  VARCHAR(20),             -- NIT patrocinador
    centro_costo     VARCHAR(20),             -- código U-PCA
    cuenta_cobro_no  VARCHAR(30),
    fecha_cuenta     DATE,
    fecha_vencimiento DATE,
    num_cuotas_credito INT DEFAULT 0,
    semestre         VARCHAR(10),
    saldo_por_factura NUMERIC(15,2) DEFAULT 0,
    saldo_total      NUMERIC(15,2) DEFAULT 0,
    importado        BOOLEAN DEFAULT FALSE,
    estudiante_id    INT REFERENCES estudiantes(id),
    factura_id       INT REFERENCES facturas(id),
    observaciones    TEXT,
    importado_por    INT REFERENCES usuarios(id),
    fecha_importacion TIMESTAMP,
    created_at       TIMESTAMP DEFAULT NOW()
);

-- ── 8. Tabla de conceptos pecuniarios especiales ──────────────
CREATE TABLE IF NOT EXISTS conceptos_pecuniarios (
    id          SERIAL PRIMARY KEY,
    codigo      VARCHAR(30) UNIQUE NOT NULL,  -- Ej: 43053801
    nombre      VARCHAR(200) NOT NULL,
    tipo        VARCHAR(30) CHECK (tipo IN (
        'vacacional','suficiencia','diferido','habilitacion','otro'
    )) NOT NULL,
    prefijo_cc  CHAR(1),   -- V, S, D, H
    cuenta_puc  VARCHAR(20) REFERENCES puc_cuentas(codigo),
    valor       NUMERIC(15,2) DEFAULT 0,
    activo      BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMP DEFAULT NOW()
);

INSERT INTO conceptos_pecuniarios (codigo, nombre, tipo, prefijo_cc) VALUES
('43053801', 'Cursos Vacacionales',       'vacacional',   'V'),
('43053802', 'Exámenes de Suficiencia',   'suficiencia',  'S'),
('43053803', 'Exámenes Diferidos',        'diferido',     'D'),
('43053804', 'Exámenes de Habilitación',  'habilitacion', 'H'),
('430550',   'Otros Conceptos Pecuniarios','otro',        NULL)
ON CONFLICT (codigo) DO NOTHING;

-- ── 9. Función: tabla de amortización sistema francés ─────────
CREATE OR REPLACE FUNCTION fn_tabla_amortizacion(
    p_capital       NUMERIC,
    p_tasa_mensual  NUMERIC,   -- decimal, ej: 0.028
    p_plazo         INT,       -- número de cuotas
    p_mora_mensual  NUMERIC DEFAULT 0  -- tasa mora mensual decimal
) RETURNS TABLE (
    cuota_no       INT,
    saldo_inicial  NUMERIC,
    valor_cuota    NUMERIC,
    valor_interes  NUMERIC,
    valor_capital  NUMERIC,
    saldo_final    NUMERIC
) AS $$
DECLARE
    v_cuota    NUMERIC;
    v_saldo    NUMERIC := p_capital;
    v_interes  NUMERIC;
    v_capital  NUMERIC;
    i          INT;
BEGIN
    IF p_tasa_mensual <= 0 THEN
        v_cuota := ROUND(p_capital / p_plazo, 0);
    ELSE
        v_cuota := ROUND(p_capital * p_tasa_mensual / (1 - POWER(1 + p_tasa_mensual, -p_plazo)), 0);
    END IF;

    FOR i IN 1..p_plazo LOOP
        v_interes := ROUND(v_saldo * p_tasa_mensual, 0);
        v_capital  := v_cuota - v_interes;
        IF i = p_plazo THEN
            v_capital := v_saldo;   -- última cuota ajusta el centavo
            v_cuota   := v_capital + v_interes;
        END IF;
        RETURN QUERY SELECT i, v_saldo, v_cuota, v_interes, v_capital, v_saldo - v_capital;
        v_saldo := v_saldo - v_capital;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- ── Índices adicionales ───────────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_estudiantes_tipo_admision ON estudiantes(tipo_admision);
CREATE INDEX IF NOT EXISTS idx_cartera_importada_tercero ON cartera_inicial_importada(id_tercero);
CREATE INDEX IF NOT EXISTS idx_cc_codigo ON centros_costo(codigo);
