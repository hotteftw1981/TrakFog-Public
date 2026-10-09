-- V0.1.1.44
-- Product discovery warnings belong to their own diagnostic setting.
-- Clear legacy duplicates that were previously persisted as a global
-- integration error even though a token-backed Tesla connection remained active.

UPDATE integrations
SET status='connected',
    last_error=NULL
WHERE type='tesla_owner_api'
  AND access_token_enc IS NOT NULL
  AND last_error IS NOT NULL
  AND (
        last_error LIKE '%Fahrzeugliste%'
        OR last_error LIKE '%/products%'
        OR last_error LIKE '%Tesla products%'
      );
