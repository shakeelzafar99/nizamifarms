<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OpenQuantitiesFixedController extends RiderController
{
    /**
     * Mobile-only: fixed hierarchy tree for open quantities
     * Hierarchy: attribute_1 -> attribute_2 -> attribute_3 -> product_name -> orders
     */
    public function getFixedThreeLevelTree(Request $request)
    {
        // Fixed hierarchy: attribute_1 -> attribute_2 -> attribute_3 -> product_name -> orders
        // This matches the Open Quantities configuration in production.
        $request->merge([
            'hierarchy_override' => [
                'attribute_1',      // Level 1: Mutton, Chicken, Beef, Lamb, etc.
                'attribute_2',      // Level 2: Whole Chicken, Boneless, Wings, etc.
                'attribute_3',      // Level 3: Deeper cuts (Karahi Cut, Qorma Cut, etc.)
                'product_name',     // Level 4: Individual products
                'orders',           // Level 5: Orders
            ],
        ]);

        return $this->getOpenOrderQuantitiesTree($request);
    }
}


