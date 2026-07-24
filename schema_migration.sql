-- SQL migration to add indexes for mobile and email to optimize duplicate check queries
ALTER TABLE customer_master ADD INDEX idx_customer_master_mobile (mobile);
ALTER TABLE customer_master ADD INDEX idx_customer_master_email (email);
ALTER TABLE customers ADD INDEX idx_customers_mobile (mobile);
ALTER TABLE customers ADD INDEX idx_customers_email (email);
