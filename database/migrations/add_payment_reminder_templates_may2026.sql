-- Migration: Add payment reminder WhatsApp templates for Online Approvals
-- These templates must also be registered and approved in WhatsApp Business Manager / Meta
-- before they can actually be sent.

-- 1. Single invoice payment reminder (3 variables: name, invoice number, amount)
INSERT INTO `t_wa_templates` (`name`, `display_name`, `category`, `language`, `status`, `body_text`, `variable_count`, `show_in`, `is_active`)
VALUES (
    'payment_reminder_single',
    'Payment Reminder - Single Invoice',
    'utility',
    'en',
    'approved',
    'Assalamoalikum {{1}},\n\nWe hope this message finds you well. We are writing to kindly remind you of an outstanding invoice {{2}} on your account. Please settle the payment of Rs {{3}} at your earliest convenience.\n\nIf you have already made the payment, kindly share a screenshot of the transaction so we can update our records accordingly.\n\nThank you for your understanding and cooperation',
    3,
    'online_approvals',
    1
)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), body_text = VALUES(body_text), variable_count = VALUES(variable_count);

-- 2. Multiple invoices payment reminder (3 variables: name, comma-separated invoices, total amount)
-- Template name on Meta: payment_reminder_multiples (note trailing 's')
INSERT INTO `t_wa_templates` (`name`, `display_name`, `category`, `language`, `status`, `body_text`, `variable_count`, `show_in`, `is_active`)
VALUES (
    'payment_reminder_multiples',
    'Payment Reminder - Multiple Invoices',
    'utility',
    'en',
    'approved',
    'Dear {{1}},\n\nThis is a payment reminder from Nizami Farms for invoice(s): {{2}}.\n\nTotal pending amount: PKR {{3}}.\n\nIf payment has already been made, please reply with the payment confirmation so we can update your account.\n\nThank you,\nNizami Farms',
    3,
    'online_approvals',
    1
)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), body_text = VALUES(body_text), variable_count = VALUES(variable_count);

-- Clean up old template name if it exists
DELETE FROM `t_wa_templates` WHERE `name` = 'payment_reminder_multiple' LIMIT 1;

-- Verification:
-- SELECT name, display_name, variable_count, show_in FROM t_wa_templates WHERE name LIKE 'payment_reminder%';
