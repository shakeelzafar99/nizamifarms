-- Debug why REQ-202510-0027 isn't showing in dashboard

-- Check the request details
SELECT 
    r.id,
    r.request_number,
    r.title,
    r.status,
    r.requires_level_1,
    r.level_1_status,
    r.category_id,
    r.payment_source_account_id,
    c.category_code,
    c.category_name,
    a.account_code as payment_source_code,
    a.account_name as payment_source_name
FROM t_req_master r
LEFT JOIN t_req_category c ON r.category_id = c.id
LEFT JOIN t_fin_accounts a ON r.payment_source_account_id = a.id
WHERE r.request_number = 'REQ-202510-0027';

-- Check if category exists
SELECT 'Category Check' as '';
SELECT * FROM t_req_category WHERE id = 3;

-- Simulate the dashboard query
SELECT 'Dashboard Query Simulation' as '';
SELECT 
    r.id,
    r.request_number,
    r.status,
    r.requires_level_1,
    r.level_1_status,
    CASE 
        WHEN r.requires_level_1 = 1 AND r.level_1_status = 'pending' THEN 'YES - Should show'
        ELSE 'NO - Will not show'
    END as should_show_in_dashboard
FROM t_req_master r
WHERE r.request_number = 'REQ-202510-0027';

