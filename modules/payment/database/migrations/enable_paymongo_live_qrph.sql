-- Run once on the production payment database after live PayMongo keys are configured.
START TRANSACTION;

INSERT INTO payment_gateway_settings (setting_key, setting_value, description)
VALUES ('gateway_mode', 'live', 'Set to live or test mode')
ON DUPLICATE KEY UPDATE setting_value = 'live';

INSERT INTO payment_gateway_settings (setting_key, setting_value, description)
VALUES ('live_channel_qrph', '1', '1 to Enable, 0 to Disable QR Ph (Live)')
ON DUPLICATE KEY UPDATE setting_value = '1';

COMMIT;