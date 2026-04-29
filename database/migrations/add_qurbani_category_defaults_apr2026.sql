-- Add category_override column to t_crm_qurbani_field_options
-- Allows setting different defaults for qurbani_type and qurbani_paya
-- per product category_level_2 (t_crm_prod_product.attribute_2)
-- When NULL or empty: global default (current behavior)
-- When set to a category value: default only for that category

ALTER TABLE `t_crm_qurbani_field_options`
ADD COLUMN `category_override` VARCHAR(100) NULL DEFAULT NULL
COMMENT 'When set, this default applies only to this product category (attribute_2)'
AFTER `is_default`;

-- Update the unique key to allow same option_value with different category scopes
-- (e.g., "Standard Hissa" as default for "Cow Share" AND "Washed" as default for "Goat")
-- The existing unique constraint won't conflict since category_override is just a
-- metadata column on the same option row - it doesn't create new rows.
