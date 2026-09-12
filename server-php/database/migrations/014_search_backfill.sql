-- 013_search.sql shipped its backfill as `UPDATE contracts SET updated_at =
-- updated_at`, intending to make trg_contracts_search_vector fire for rows
-- that predate the trigger. It doesn't: the trigger is `UPDATE OF
-- contract_number, title, counterparty_name, commercial_summary, description,
-- notes`, and Postgres matches a column-specific UPDATE trigger against the
-- SET list of the statement, not against which values actually changed.
-- `updated_at` is not on that list, so the statement was a no-op and any
-- contract already present when 013 ran was left permanently unsearchable.
-- 013 is already applied on every existing database, so it cannot be edited
-- in place — this repeats the backfill correctly instead, computing the
-- vector directly with the same expression as
-- contracts_search_vector_update() rather than depending on a trigger column
-- list that could change again. Restricted to NULL rows so it never
-- overwrites a vector a later edit already computed.
UPDATE contracts
SET search_vector =
    setweight(to_tsvector('english', coalesce(contract_number, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(counterparty_name, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(commercial_summary, '')), 'C') ||
    setweight(to_tsvector('english', coalesce(description, '')), 'D') ||
    setweight(to_tsvector('english', coalesce(notes, '')), 'D')
WHERE search_vector IS NULL;
