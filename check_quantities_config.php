<?php
/**
 * Diagnostic script to check Open Quantities configuration
 * Run this in both dev and production to compare
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Open Quantities Configuration Check ===\n\n";

// 1. Check hierarchy settings
echo "1. Current Hierarchy Settings:\n";
$hierarchySetting = DB::table('t_crm_open_quantities_settings')
    ->where('setting_key', 'hierarchy_levels')
    ->first();

if ($hierarchySetting) {
    $hierarchy = json_decode($hierarchySetting->setting_value, true);
    echo "   Hierarchy: " . json_encode($hierarchy, JSON_PRETTY_PRINT) . "\n";
    echo "   Updated: " . $hierarchySetting->updated_at . "\n";
} else {
    echo "   ⚠️  No hierarchy setting found! Using default.\n";
    $hierarchy = ['product_type', 'product_name', 'orders'];
}

echo "\n";

// 2. Check if columns exist in the database
echo "2. Checking if hierarchy columns exist in database:\n";

// Get a sample of line items to check columns
$sampleLineItems = DB::table('t_crm_prod_order_line_item as li')
    ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
    ->where('o.order_status', '!=', 'delivered')
    ->where('o.order_status', '!=', 'cancelled')
    ->limit(1)
    ->select('li.*')
    ->first();

if ($sampleLineItems) {
    $lineItemColumns = array_keys((array)$sampleLineItems);
    
    foreach ($hierarchy as $index => $field) {
        if ($field === 'orders' || $field === 'product_name') {
            echo "   ✓ Level $index: '$field' (special field, always available)\n";
            continue;
        }
        
        if (in_array($field, $lineItemColumns)) {
            echo "   ✓ Level $index: '$field' exists in t_crm_prod_order_line_item\n";
        } else {
            echo "   ✗ Level $index: '$field' DOES NOT EXIST in t_crm_prod_order_line_item\n";
            echo "      This will cause drill-down to fail!\n";
        }
    }
} else {
    echo "   ⚠️  No line items found to check columns\n";
}

echo "\n";

// 3. Show available attribute columns
echo "3. Available attribute/category columns in t_crm_prod_order_line_item:\n";
if ($sampleLineItems) {
    $attributeColumns = array_filter($lineItemColumns, function($col) {
        return strpos($col, 'attribute_') === 0 || 
               strpos($col, 'category_') === 0 ||
               strpos($col, 'product_type') === 0;
    });
    
    foreach ($attributeColumns as $col) {
        echo "   - $col\n";
    }
    
    if (empty($attributeColumns)) {
        echo "   (No attribute/category columns found)\n";
    }
}

echo "\n";

// 4. Test a sample query with current hierarchy
echo "4. Testing sample query with current hierarchy:\n";
try {
    $testQuery = DB::table('t_crm_prod_order_line_item as li')
        ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
        ->where('o.order_status', '!=', 'delivered')
        ->where('o.order_status', '!=', 'cancelled')
        ->limit(5);
    
    // Add hierarchy fields to select
    $selectFields = ['li.id', 'li.order_id', 'li.line_item_name'];
    foreach ($hierarchy as $field) {
        if ($field !== 'orders' && $field !== 'product_name') {
            $selectFields[] = "li.$field";
        }
    }
    
    $testQuery->select($selectFields);
    $results = $testQuery->get();
    
    echo "   ✓ Query executed successfully\n";
    echo "   Sample data (first row):\n";
    if ($results->count() > 0) {
        $first = $results->first();
        foreach ($hierarchy as $field) {
            if ($field === 'orders') {
                echo "      - $field: (order_id = {$first->order_id})\n";
            } elseif ($field === 'product_name') {
                echo "      - $field: {$first->line_item_name}\n";
            } else {
                $value = $first->{$field} ?? 'NULL';
                echo "      - $field: $value\n";
                if ($value === 'NULL' || $value === null || $value === '') {
                    echo "         ⚠️  This field is NULL/empty - will show as 'Uncategorized'\n";
                }
            }
        }
    }
} catch (\Exception $e) {
    echo "   ✗ Query failed: " . $e->getMessage() . "\n";
}

echo "\n";
echo "=== Summary ===\n";
echo "If any hierarchy fields are marked with ✗ or show as NULL,\n";
echo "you need to update your hierarchy settings to match your actual database columns.\n";
echo "\nTo fix, go to: Orders → Open Order Quantities → Settings (gear icon)\n";
echo "Or run this SQL to update directly:\n";
echo "\nUPDATE t_crm_open_quantities_settings \n";
echo "SET setting_value = '[\"your\",\"column\",\"names\",\"orders\"]' \n";
echo "WHERE setting_key = 'hierarchy_levels';\n";







