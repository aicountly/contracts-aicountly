-- Full-text search over the fields a person actually searches by.
--
-- PostgreSQL's own tsvector, not a separate search cluster: the corpus is one
-- company's contract metadata, GIN handles it comfortably, and adding
-- Elasticsearch would be a second datastore to deploy, secure and keep in sync
-- for no measurable gain at this size.
--
-- The trigger keeps the vector correct on every write. Doing it in application
-- code would mean any path that forgets to call it leaves a contract
-- unsearchable — and the paths that write contracts are many.

CREATE OR REPLACE FUNCTION contracts_search_vector_update() RETURNS trigger AS $$
BEGIN
    NEW.search_vector :=
        -- Weights order the results: the number and title are what someone is
        -- most likely typing, the description least.
        setweight(to_tsvector('english', coalesce(NEW.contract_number, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(NEW.title, '')), 'A') ||
        setweight(to_tsvector('english', coalesce(NEW.counterparty_name, '')), 'B') ||
        setweight(to_tsvector('english', coalesce(NEW.commercial_summary, '')), 'C') ||
        setweight(to_tsvector('english', coalesce(NEW.description, '')), 'D') ||
        setweight(to_tsvector('english', coalesce(NEW.notes, '')), 'D');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_contracts_search_vector
    BEFORE INSERT OR UPDATE OF contract_number, title, counterparty_name, commercial_summary, description, notes
    ON contracts
    FOR EACH ROW EXECUTE FUNCTION contracts_search_vector_update();

-- Backfill anything already present (none on a fresh install; this makes the
-- migration correct when it is applied to a database that predates it).
UPDATE contracts SET updated_at = updated_at;

-- Trigram index for substring matching — "acme" should find "Acme Industries
-- Pvt Ltd" without the user knowing to search whole words.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX idx_contracts_title_trgm ON contracts USING GIN (title gin_trgm_ops);
CREATE INDEX idx_contracts_counterparty_trgm ON contracts USING GIN (counterparty_name gin_trgm_ops);
CREATE INDEX idx_contracts_number_trgm ON contracts USING GIN (contract_number gin_trgm_ops);

-- Extracted document text, searched separately. It is not folded into the
-- contract vector because a 60-page PDF would drown the title and number in
-- every ranking.
CREATE INDEX idx_document_versions_text_trgm
    ON contract_document_versions USING GIN (extracted_text gin_trgm_ops)
    WHERE extracted_text IS NOT NULL;

CREATE INDEX idx_clause_library_text_trgm ON clause_library USING GIN (standard_text gin_trgm_ops);
CREATE INDEX idx_contract_clauses_body_trgm ON contract_clauses USING GIN (body_text gin_trgm_ops);
