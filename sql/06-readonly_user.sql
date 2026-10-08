-- Rol de SOLO LECTURA para la página web (se ejecuta al final, tras crear y cargar las tablas).
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'sismos_ro') THEN
        CREATE ROLE sismos_ro LOGIN PASSWORD 'sismos_ro';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE datawarehouse TO sismos_ro;
GRANT USAGE ON SCHEMA public TO sismos_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO sismos_ro;
ALTER ROLE sismos_ro SET default_transaction_read_only = on;
ALTER ROLE sismos_ro SET statement_timeout = '5s';
