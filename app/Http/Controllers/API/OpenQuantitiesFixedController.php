<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OpenQuantitiesFixedController extends RiderController
{
    /**
     * Mobile-only: Dynamic hierarchy tree for open quantities
     * Hierarchy is read from database (t_crm_open_quantities_settings)
     * This allows web and mobile to use the same hierarchy configuration
     */
    public function getFixedThreeLevelTree(Request $request)
    {
        // NO hierarchy_override - let getOpenOrderQuantitiesTree read from database
        // This makes hierarchy dynamic and configurable without code changes
        // Backend will read from t_crm_open_quantities_settings.hierarchy_levels
        
        return $this->getOpenOrderQuantitiesTree($request);
    }
}


