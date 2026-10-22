-- This runs only when PostgreSQL initializes a new data volume.
DO $$
DECLARE
    application_password text := btrim(pg_read_file('/run/secrets/app_database_password'));
BEGIN
    IF length(application_password) < 32 THEN
        RAISE EXCEPTION 'The application database password must have at least 32 characters.';
    END IF;
    EXECUTE format('CREATE ROLE exopulse LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD %L', application_password);
END $$;
SELECT format('ALTER DATABASE %I OWNER TO exopulse', current_database()) \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
ALTER SCHEMA public OWNER TO exopulse;
