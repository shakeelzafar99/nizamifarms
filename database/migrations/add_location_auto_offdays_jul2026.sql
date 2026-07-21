-- =============================================
-- Auto Location-Request — DAY OFF setting
-- Created: 2026-07-21
-- Purpose: Add a configurable "day off" for the auto location-request WhatsApp
--          automation. On a day off, NOTHING is sent — qualifying orders are
--          queued and flushed automatically on the next working day when the
--          daily send window next opens (Option A: no message goes out on the
--          off day).
--
--          Value: CSV of ISO-8601 day numbers (1=Mon, 2=Tue … 7=Sun).
--          Default '2' = Tuesday (Nizami Farms' operational day off — the same
--          day the rider/manager shifts use as their off day).
--          Blank = never pause (old behaviour). Multiple days allowed, e.g. '2,3'.
--
--          Read by App\Services\Location\OpenOrderLocationService::offDays().
--          Edited from Messages → Automations → "Order accepted → send location
--          request" card (WhatsAppAutomationController window_proxy['offdays']).
--
-- Safe to re-run: the INSERT is guarded by NOT EXISTS.
-- =============================================

INSERT INTO t_fin_config (config_key, config_value, description)
SELECT 'location_auto_offdays', '2',
       'Days off for auto location-request sends (CSV of ISO day numbers 1=Mon..7=Sun). On these days nothing is sent; orders queue and go out the next working day. Default 2 = Tuesday. Blank = never pause.'
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'location_auto_offdays');

-- Verify:
SELECT config_key, config_value FROM t_fin_config WHERE config_key = 'location_auto_offdays';
SELECT 'Auto location-request day-off setting ready!' as status;
