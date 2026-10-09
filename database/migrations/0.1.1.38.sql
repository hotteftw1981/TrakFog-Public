-- V0.1.1.38
-- Heal the over-aggressive V0.1.1.37 integration status.
-- A partial Tesla HTTP 403 never means that the saved token/history is gone.

UPDATE integrations
SET status='connected',
    last_error=NULL
WHERE type='tesla_owner_api'
  AND access_token_enc IS NOT NULL
  AND status='error'
  AND (
        last_error LIKE '%403%'
        OR last_error LIKE '%Detailabruf%'
        OR last_error LIKE '%permission%'
        OR last_error LIKE '%forbidden%'
      );

INSERT INTO settings(setting_key,setting_value)
VALUES('tesla_products_last_error',NULL)
ON DUPLICATE KEY UPDATE setting_value=setting_value;
