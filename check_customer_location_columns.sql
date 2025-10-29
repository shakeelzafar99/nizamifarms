-- Check customer table structure for location fields
SHOW COLUMNS FROM t_crm_prod_customer WHERE Field LIKE '%lat%' OR Field LIKE '%long%' OR Field LIKE '%location%' OR Field LIKE '%address%';

