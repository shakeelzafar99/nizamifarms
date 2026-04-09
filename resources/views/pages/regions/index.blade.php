@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />

<div class="max-w-7xl mx-auto p-4 lg:p-6">
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Delivery Regions</h1>
      <p class="text-sm text-gray-600 mt-1">Manage delivery zones, area mapping, and rider assignments</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <button onclick="openBatchModal()" style="background:#2563eb;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
        Detect Customers
      </button>
      <button onclick="openRedetectModal()" style="background:#d97706;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
        Re-detect All
      </button>
      <button onclick="openCustomerDataModal()" style="background:#4b5563;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
        More Options
      </button>
    </div>
  </div>

  <!-- Stats + Quick Actions -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
    <div class="bg-white rounded-lg shadow-sm border p-3">
      <div class="text-xs text-gray-500 uppercase font-medium">Customers w/ Region</div>
      <div class="text-lg font-bold mt-1"><span id="statCustWith">-</span><span class="text-gray-400 font-normal"> / </span><span id="statCustTotal" class="text-gray-500 font-normal">-</span></div>
    </div>
    <div class="bg-white rounded-lg shadow-sm border p-3">
      <div class="text-xs text-gray-500 uppercase font-medium">Open Orders w/ Region</div>
      <div class="text-lg font-bold mt-1"><span id="statOrderWith">-</span><span class="text-gray-400 font-normal"> / </span><span id="statOrderTotal" class="text-gray-500 font-normal">-</span></div>
    </div>
    <div class="bg-white rounded-lg shadow-sm border p-3">
      <div class="text-xs text-gray-500 uppercase font-medium">Customers Unassigned</div>
      <div class="text-lg font-bold text-red-600 mt-1" id="statCustWithout">-</div>
    </div>
    <div class="bg-white rounded-lg shadow-sm border p-3">
      <div class="text-xs text-gray-500 uppercase font-medium">Orders Unassigned</div>
      <div class="text-lg font-bold text-red-600 mt-1" id="statOrderWithout">-</div>
    </div>
  </div>
  <!-- Batch Action Bar (visible when there are unassigned records) -->
  <div id="batchActionBar" class="hidden bg-orange-50 border-2 border-orange-300 rounded-lg p-4 mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm text-orange-900">
      <strong id="batchActionMsg" class="text-base">Unassigned records found.</strong>
      <span class="block mt-1 text-orange-700">Run detection to auto-assign regions based on GPS coordinates and address data.</span>
    </div>
    <div class="flex gap-2">
      <button onclick="runQuickBatch('customers')" id="qbCust" style="background:#2563eb;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
        Detect Customers
      </button>
      <button onclick="openRedetectModal()" style="background:#d97706;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
        Re-detect All
      </button>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;border-bottom:1px solid #e5e7eb;margin-bottom:20px;gap:4px;">
    <button onclick="switchTab('regions')" id="tabRegions" style="padding:8px 16px;font-size:13px;font-weight:600;border:none;border-bottom:2px solid #2563eb;color:#2563eb;background:transparent;cursor:pointer;">Regions & Map</button>
    <button onclick="switchTab('areas')" id="tabAreas" style="padding:8px 16px;font-size:13px;font-weight:600;border:none;border-bottom:2px solid transparent;color:#6b7280;background:transparent;cursor:pointer;">Area Mapping</button>
    <button onclick="switchTab('riders')" id="tabRiders" style="padding:8px 16px;font-size:13px;font-weight:600;border:none;border-bottom:2px solid transparent;color:#6b7280;background:transparent;cursor:pointer;">Rider Assignments</button>
  </div>

  <!-- === TAB: Regions & Map === -->
  <div id="panelRegions">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
          <!-- Map toolbar -->
          <div class="p-3 border-b bg-gray-50 flex flex-wrap gap-2 items-center justify-between">
            <div class="flex gap-2 items-center flex-wrap">
              <select id="drawRegionSelect" class="text-sm border-gray-300 rounded-md px-2 py-1.5">
                <option value="">-- Select region to draw --</option>
              </select>
              <button onclick="startDrawing()" id="btnDraw" style="background:#2563eb;color:#fff;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;opacity:0.4;" disabled>
                Draw Polygon
              </button>
              <button onclick="savePolygon()" id="btnSavePolygon" style="display:none;background:#2563eb;color:#fff;padding:6px 16px;border-radius:6px;font-size:13px;font-weight:700;border:none;cursor:pointer;box-shadow:0 2px 6px rgba(37,99,235,.4);">
                💾 Save Polygon
              </button>
              <button onclick="clearDrawing()" style="background:#e5e7eb;color:#374151;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
                Clear
              </button>
            </div>
            <!-- Area search -->
            <div class="flex gap-2 items-center">
              <input type="text" id="areaSearchInput" placeholder="Search area (e.g. F-7, Bahria Phase 8)..." class="text-sm border-gray-300 rounded-md px-2 py-1.5 w-56" onkeydown="if(event.key==='Enter'){event.preventDefault();searchArea();}">
              <button onclick="searchArea()" style="background:#4f46e5;color:#fff;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
                Search Map
              </button>
            </div>
          </div>
          <div id="regionMap" style="height: 500px; width: 100%;"></div>
          <div id="mapStatus" class="p-2 bg-blue-50 border-t text-xs text-blue-700 hidden"></div>
        </div>
      </div>

      <!-- Regions list -->
      <div>
        <div class="bg-white rounded-lg shadow-sm border">
          <div class="p-3 border-b bg-gray-50 flex justify-between items-center">
            <span class="text-sm font-medium text-gray-700">Regions</span>
            <button onclick="openRegionModal()" style="background:#2563eb;color:#fff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;border:none;cursor:pointer;">+ Add</button>
          </div>
          <div id="regionsList" class="divide-y divide-gray-100 max-h-[550px] overflow-y-auto">
            <div class="p-4 text-center text-gray-400 text-sm">Loading...</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- === TAB: Area Mapping === -->
  <div id="panelAreas" class="hidden">
    <div class="bg-white rounded-lg shadow-sm border">
      <div class="p-3 border-b bg-gray-50 flex justify-between items-center flex-wrap gap-2">
        <span class="text-sm font-medium text-gray-700">Areas mapped to regions</span>
        <div class="flex gap-2">
          <select id="areaFilterRegion" onchange="loadAreas()" class="text-sm border-gray-300 rounded-md px-2 py-1">
            <option value="">All Regions</option>
          </select>
          <button onclick="openAreaModal()" style="background:#2563eb;color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;border:none;cursor:pointer;">
            + Add Area
          </button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Area Name</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Region</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Keywords</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody id="areasTableBody" class="bg-white divide-y divide-gray-200 text-sm"></tbody>
        </table>
      </div>
      <div id="areasEmpty" class="hidden text-center py-8 text-gray-400 text-sm">No areas found</div>
    </div>
  </div>

  <!-- === TAB: Rider Assignments === -->
  <div id="panelRiders" class="hidden">
    <!-- Auto-assign action bar -->
    <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-5 flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-[200px]">
        <p class="text-sm font-semibold text-indigo-800">🚴 Auto-assign Riders to Open Orders</p>
        <p class="text-xs text-indigo-600 mt-1">Uses the primary rider assignment for each region</p>
      </div>
      <button onclick="runAutoAssignFromRegions('unassigned')" style="background:#4f46e5;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Assign Unassigned</button>
      <button onclick="runAutoAssignFromRegions('reassign')" style="background:#dc2626;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Reassign ALL</button>
      <span id="autoAssignRegionsResult" class="text-xs text-indigo-800 hidden"></span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <div class="bg-white rounded-lg shadow-sm border">
        <div class="p-3 border-b bg-gray-50"><span class="text-sm font-medium text-gray-700">Assign Rider to Region</span></div>
        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Rider</label>
            <select id="assignRiderSelect" class="w-full border-gray-300 rounded-md text-sm"></select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Region</label>
            <select id="assignRegionSelect" class="w-full border-gray-300 rounded-md text-sm"></select>
          </div>
          <div class="flex items-center gap-2">
            <input type="checkbox" id="assignPrimary" class="rounded border-gray-300 text-blue-600">
            <label for="assignPrimary" class="text-sm text-gray-700">Primary region</label>
          </div>
          <button onclick="assignRider()" style="width:100%;background:#2563eb;color:#fff;padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Assign Rider</button>
        </div>
      </div>
      <div class="bg-white rounded-lg shadow-sm border">
        <div class="p-3 border-b bg-gray-50"><span class="text-sm font-medium text-gray-700">Current Assignments</span></div>
        <div id="riderAssignmentsList" class="divide-y divide-gray-100 max-h-[400px] overflow-y-auto">
          <div class="p-4 text-center text-gray-400 text-sm">Loading...</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Area Modal -->
<div id="areaModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;" onclick="closeAreaModal()">
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; border-radius:12px; width:90%; max-width:500px;" onclick="event.stopPropagation()">
    <div class="p-6">
      <div class="flex justify-between items-center mb-4">
        <h2 id="areaModalTitle" class="text-lg font-semibold">Add Area</h2>
        <button onclick="closeAreaModal()" style="color:#9ca3af;font-size:24px;line-height:1;background:none;border:none;cursor:pointer;">&times;</button>
      </div>
      <form onsubmit="saveArea(event)" class="space-y-4">
        <input type="hidden" id="areaId">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Region *</label>
          <select id="areaRegionId" required class="w-full border-gray-300 rounded-md text-sm"></select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Area Name *</label>
          <input type="text" id="areaName" required placeholder="e.g. F-6, DHA Phase 3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Keywords (comma-separated)</label>
          <textarea id="areaKeywords" rows="3" placeholder="f-6, f6, f 6, sector f-6" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"></textarea>
          <p class="text-xs text-gray-500 mt-1">Matched against customer addresses</p>
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" onclick="closeAreaModal()" style="background:#e5e7eb;color:#374151;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Cancel</button>
          <button type="submit" style="background:#2563eb;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Save Area</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Batch Detect Modal (only unassigned) -->
<div id="batchModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;" onclick="closeBatchModal()">
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; border-radius:14px; width:90%; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,.3);" onclick="event.stopPropagation()">
    <div class="p-6">
      <div class="flex justify-between items-center mb-3">
        <h2 class="text-lg font-bold text-gray-900">Detect Customer Regions</h2>
        <button onclick="closeBatchModal()" style="color:#9ca3af;font-size:24px;line-height:1;background:none;border:none;cursor:pointer;">&times;</button>
      </div>
      <p class="text-sm text-gray-600 mb-5">Assigns regions to customers that don't have one yet, based on GPS coordinates and address matching. Processes all unassigned customers in one go.</p>
      <div class="space-y-3">
        <button onclick="runBatch('batch-detect','customers')" id="batchDetectBtn" style="width:100%;background:#2563eb;color:#fff;padding:12px 16px;border-radius:8px;font-size:14px;font-weight:700;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
          Detect Unassigned Customers
        </button>
        <div id="batchResult" class="hidden p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800 font-medium"></div>
      </div>
    </div>
  </div>
</div>

<!-- Re-detect Modal (all customers, with reset option) -->
<div id="redetectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;" onclick="closeRedetectModal()">
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; border-radius:14px; width:90%; max-width:500px; box-shadow:0 20px 60px rgba(0,0,0,.3);" onclick="event.stopPropagation()">
    <div class="p-6">
      <div class="flex justify-between items-center mb-3">
        <h2 class="text-lg font-bold text-gray-900">Re-detect All Regions</h2>
        <button onclick="closeRedetectModal()" style="color:#9ca3af;font-size:24px;line-height:1;background:none;border:none;cursor:pointer;">&times;</button>
      </div>
      <p class="text-sm text-gray-600 mb-5">Re-evaluates ALL customer regions using current polygon and area configuration. Use this after changing region boundaries.</p>

      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-4">
        <p class="text-sm font-semibold text-amber-900 mb-2">Choose what to reset:</p>
        <label class="flex items-start gap-3 p-3 bg-white rounded-lg border border-gray-200 cursor-pointer mb-2 hover:border-blue-300 transition">
          <input type="radio" name="redetectMode" value="preserve_manual" checked class="mt-0.5 text-blue-600">
          <div>
            <span class="text-sm font-semibold text-gray-800">Only auto-detected</span>
            <span class="block text-xs text-gray-500 mt-0.5">Keep manually set regions, only re-detect auto-assigned ones</span>
          </div>
        </label>
        <label class="flex items-start gap-3 p-3 bg-white rounded-lg border border-gray-200 cursor-pointer hover:border-red-300 transition">
          <input type="radio" name="redetectMode" value="reset_all" class="mt-0.5 text-red-600">
          <div>
            <span class="text-sm font-semibold text-red-700">Reset ALL including manual</span>
            <span class="block text-xs text-gray-500 mt-0.5">Clears everything and re-detects from scratch — use with caution</span>
          </div>
        </label>
      </div>

      <div class="space-y-3">
        <button onclick="runRedetect()" id="redetectBtn" style="width:100%;background:#d97706;color:#fff;padding:12px 16px;border-radius:8px;font-size:14px;font-weight:700;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.15);">
          Re-detect All Customers
        </button>
        <div id="redetectResult" class="hidden p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800 font-medium"></div>
      </div>
    </div>
  </div>
</div>

<!-- Customer Data Modal -->
<div id="customerDataModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;" onclick="closeCustomerDataModal()">
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; border-radius:12px; width:95%; max-width:800px; max-height:85vh; display:flex; flex-direction:column;" onclick="event.stopPropagation()">
    <div class="p-4 border-b flex justify-between items-center">
      <h2 class="text-lg font-semibold">Customer Address Analysis</h2>
      <button onclick="closeCustomerDataModal()" style="color:#9ca3af;font-size:24px;line-height:1;background:none;border:none;cursor:pointer;">&times;</button>
    </div>
    <div style="flex:1; overflow-y:auto; padding:16px;">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Top Cities (by customer count)</h3>
      <div id="citiesData" class="mb-6"><div class="text-sm text-gray-400">Loading...</div></div>
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Recent Customer Addresses (last 200)</h3>
      <div id="addressesData"><div class="text-sm text-gray-400">Loading...</div></div>
    </div>
  </div>
</div>

<!-- Region Edit/Create Modal -->
<div id="regionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;" onclick="closeRegionModal()">
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:white; border-radius:14px; width:90%; max-width:440px; box-shadow:0 20px 60px rgba(0,0,0,.3);" onclick="event.stopPropagation()">
    <div class="p-6">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-bold text-gray-900" id="regionModalTitle">Add New Region</h2>
        <button onclick="closeRegionModal()" style="color:#9ca3af;font-size:24px;line-height:1;background:none;border:none;cursor:pointer;">&times;</button>
      </div>
      <input type="hidden" id="regionModalId" value="">
      <div class="mb-3">
        <label class="block text-sm font-medium text-gray-700 mb-1">Region Name</label>
        <input type="text" id="regionModalName" placeholder="e.g. Islamabad, DHA Phase 2" class="w-full text-sm border-gray-300 rounded-md px-3 py-2">
      </div>
      <div class="mb-3">
        <label class="block text-sm font-medium text-gray-700 mb-1">Code (short)</label>
        <input type="text" id="regionModalCode" placeholder="e.g. isb, rwp, dha2" class="w-full text-sm border-gray-300 rounded-md px-3 py-2">
      </div>
      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">Color</label>
        <div class="flex gap-2 flex-wrap" id="regionColorPicker">
          <span onclick="selectRegionColor('#6366f1')" style="width:32px;height:32px;border-radius:50%;background:#6366f1;cursor:pointer;border:3px solid #111827;" data-color="#6366f1"></span>
          <span onclick="selectRegionColor('#dc2626')" style="width:32px;height:32px;border-radius:50%;background:#dc2626;cursor:pointer;border:2px solid #e5e7eb;" data-color="#dc2626"></span>
          <span onclick="selectRegionColor('#f59e0b')" style="width:32px;height:32px;border-radius:50%;background:#f59e0b;cursor:pointer;border:2px solid #e5e7eb;" data-color="#f59e0b"></span>
          <span onclick="selectRegionColor('#059669')" style="width:32px;height:32px;border-radius:50%;background:#059669;cursor:pointer;border:2px solid #e5e7eb;" data-color="#059669"></span>
          <span onclick="selectRegionColor('#0ea5e9')" style="width:32px;height:32px;border-radius:50%;background:#0ea5e9;cursor:pointer;border:2px solid #e5e7eb;" data-color="#0ea5e9"></span>
          <span onclick="selectRegionColor('#8b5cf6')" style="width:32px;height:32px;border-radius:50%;background:#8b5cf6;cursor:pointer;border:2px solid #e5e7eb;" data-color="#8b5cf6"></span>
          <span onclick="selectRegionColor('#ec4899')" style="width:32px;height:32px;border-radius:50%;background:#ec4899;cursor:pointer;border:2px solid #e5e7eb;" data-color="#ec4899"></span>
          <span onclick="selectRegionColor('#64748b')" style="width:32px;height:32px;border-radius:50%;background:#64748b;cursor:pointer;border:2px solid #e5e7eb;" data-color="#64748b"></span>
        </div>
      </div>
      <input type="hidden" id="regionModalColor" value="#6366f1">
      <div class="flex justify-end gap-2">
        <button onclick="closeRegionModal()" style="background:#e5e7eb;color:#374151;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Cancel</button>
        <button onclick="saveRegionFromModal()" id="regionModalSaveBtn" style="background:#2563eb;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer;">Save Region</button>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
let map, drawnItems, currentDrawHandler;
let regionsData = [];
let regionPolygonLayers = {};
let searchMarker, searchPolygonLayer;
let clickMarker, clickBoundaryLayer;

document.addEventListener('DOMContentLoaded', () => { initMap(); loadAll(); });

function initMap() {
  map = L.map('regionMap').setView([33.62, 73.05], 11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap', maxZoom: 18
  }).addTo(map);
  drawnItems = new L.FeatureGroup();
  map.addLayer(drawnItems);

  map.on('click', onMapClick);
}

async function onMapClick(e) {
  if (currentDrawHandler) return;
  const {lat, lng} = e.latlng;

  if (clickMarker) { map.removeLayer(clickMarker); clickMarker = null; }
  if (clickBoundaryLayer) { map.removeLayer(clickBoundaryLayer); clickBoundaryLayer = null; }

  clickMarker = L.circleMarker([lat, lng], {radius: 6, color: '#dc2626', fillColor: '#dc2626', fillOpacity: 0.8}).addTo(map);
  showMapStatus('Identifying area at ' + lat.toFixed(4) + ', ' + lng.toFixed(4) + '...', 'info');

  try {
    const url = `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json&zoom=14&polygon_geojson=1`;
    const resp = await fetch(url, { headers: { 'User-Agent': 'NizamiFarms/1.0' } });
    const result = await resp.json();

    if (!result || result.error) {
      showMapStatus('Could not identify this location.', 'error');
      return;
    }

    const areaName = result.address?.suburb || result.address?.neighbourhood || result.address?.city_district || result.address?.town || result.name || 'Unknown area';
    const displayName = result.display_name || areaName;

    if (result.geojson && (result.geojson.type === 'Polygon' || result.geojson.type === 'MultiPolygon')) {
      clickBoundaryLayer = L.geoJSON(result.geojson, { style: { color: '#dc2626', weight: 3, fillOpacity: 0.15, dashArray: '6,4' } }).addTo(map);
      map.fitBounds(clickBoundaryLayer.getBounds().pad(0.1));
    }

    map.removeLayer(clickMarker);
    clickMarker = L.marker([lat, lng], {
      icon: L.divIcon({ className: '', html: '<div style="background:#dc2626;color:#fff;padding:2px 10px;border-radius:4px;font-size:12px;font-weight:600;white-space:nowrap;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);">' + esc(areaName) + '</div>', iconAnchor: [40, 15] })
    }).addTo(map);

    showAssignRegionPopup(areaName, lat, lng, displayName);
  } catch(err) {
    showMapStatus('Reverse geocode error: ' + err.message, 'error');
  }
}

function showAssignRegionPopup(areaName, lat, lng, displayName) {
  const regionOptions = regionsData.map(r => `<option value="${r.id}">${esc(r.name)}</option>`).join('');
  const popupHtml = `
    <div style="min-width:220px;">
      <div style="font-weight:600;margin-bottom:6px;font-size:13px;">${esc(areaName)}</div>
      <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">${esc(displayName).substring(0,80)}</div>
      <select id="popupRegionSelect" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;margin-bottom:8px;">
        <option value="">-- Assign to region --</option>
        ${regionOptions}
      </select>
      <button onclick="assignAreaFromPopup('${esc(areaName).replace(/'/g,"\\'")}', ${lat}, ${lng})" style="width:100%;padding:6px;background:#2563eb;color:#fff;border:none;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;">
        Add Area to Region
      </button>
    </div>`;
  clickMarker.bindPopup(popupHtml, { maxWidth: 280 }).openPopup();
}

async function assignAreaFromPopup(areaName, lat, lng) {
  const regionId = document.getElementById('popupRegionSelect')?.value;
  if (!regionId) { alert('Select a region first'); return; }

  const keywords = [areaName.toLowerCase()];
  const parts = areaName.toLowerCase().replace(/[-]/g,' ').split(' ').filter(Boolean);
  if (parts.length > 1) keywords.push(parts.join(''));

  try {
    const d = await (await fetch('/regions/areas/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        region_id: regionId,
        area_name: areaName,
        keywords: JSON.stringify(keywords),
        center_lat: lat, center_lng: lng, is_active: 1
      })
    })).json();

    if (d.success) {
      const regionName = regionsData.find(r => r.id == regionId)?.name || '';
      showMapStatus('"' + areaName + '" added to ' + regionName + '!', 'success');
      if (clickMarker) { map.removeLayer(clickMarker); clickMarker = null; }
      if (clickBoundaryLayer) { map.removeLayer(clickBoundaryLayer); clickBoundaryLayer = null; }
      loadAreas(); loadRegions();
    } else alert(d.message || 'Error saving area');
  } catch(e) { alert('Error: ' + e.message); }
}

function showMapStatus(msg, type) {
  const el = document.getElementById('mapStatus');
  el.textContent = msg;
  el.className = 'p-2 border-t text-xs ' + (type === 'error' ? 'bg-red-50 text-red-700' : type === 'success' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700');
  el.classList.remove('hidden');
  if (type !== 'error') setTimeout(() => el.classList.add('hidden'), 5000);
}

async function loadAll() {
  await loadRegions();
  loadAreas(); loadRiderAssignments(); loadRiders(); loadStats();
}

async function loadStats() {
  try {
    const d = await (await fetch('/regions/stats')).json();
    if (d.success) {
      document.getElementById('statCustWith').textContent = d.stats.customers_with_region;
      document.getElementById('statCustTotal').textContent = d.stats.total_customers;
      document.getElementById('statOrderWith').textContent = d.stats.orders_with_region;
      document.getElementById('statOrderTotal').textContent = d.stats.total_open_orders;
      document.getElementById('statCustWithout').textContent = d.stats.customers_without_region;
      document.getElementById('statOrderWithout').textContent = d.stats.orders_without_region;
      const bar = document.getElementById('batchActionBar');
      if (d.stats.customers_without_region > 0 || d.stats.orders_without_region > 0) {
        document.getElementById('batchActionMsg').textContent =
          (d.stats.customers_without_region || 0) + ' customers and ' + (d.stats.orders_without_region || 0) + ' orders without regions.';
        bar.classList.remove('hidden');
      } else {
        bar.classList.add('hidden');
      }
    }
  } catch(e) { console.error(e); }
}

async function runQuickBatch(type) {
  const btn = document.getElementById(type === 'customers' ? 'qbCust' : 'qbOrders');
  if (!btn) return;
  const origText = btn.textContent;
  btn.disabled = true; btn.textContent = 'Processing...'; btn.style.opacity = '0.6';
  try {
    const d = await (await fetch('/regions/batch-detect', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ type, limit: 15000 })
    })).json();
    showMapStatus(d.message || 'Done', 'success');
    loadStats(); loadRegions();
  } catch(e) { showMapStatus('Error: ' + e.message, 'error'); }
  finally { btn.disabled = false; btn.textContent = origText; btn.style.opacity = '1'; }
}

async function loadRegions() {
  try {
    const d = await (await fetch('/regions/data')).json();
    if (d.success) { regionsData = d.regions; renderRegionsList(); renderRegionsOnMap(); populateRegionDropdowns(); }
  } catch(e) { console.error(e); }
}

function renderRegionsList() {
  const el = document.getElementById('regionsList');
  if (!regionsData.length) { el.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">No regions</div>'; return; }
  el.innerHTML = regionsData.map(r => `
    <div class="p-3 hover:bg-gray-50 cursor-pointer" onclick="focusRegion(${r.id})">
      <div class="flex items-center gap-2 mb-1">
        <span class="w-3 h-3 rounded-full shrink-0" style="background:${esc(r.color)}"></span>
        <span class="font-medium text-sm text-gray-900">${esc(r.name)}</span>
        <span class="text-xs text-gray-400 ml-1">${esc(r.code)}</span>
        <button onclick="event.stopPropagation();openRegionModal(${r.id})" style="margin-left:auto;color:#6b7280;font-size:11px;font-weight:500;background:none;border:1px solid #d1d5db;border-radius:4px;padding:1px 8px;cursor:pointer;">Edit</button>
      </div>
      <div class="flex gap-3 text-xs text-gray-500 flex-wrap">
        <span>${r.area_count} areas</span><span>${r.rider_count} riders</span>
        <span>${r.customer_count} cust</span><span>${r.open_order_count} orders</span>
      </div>
      <div class="text-xs mt-1">
        ${r.polygon_coordinates ? '<span class="text-green-600 font-medium">' + getPolygonCount(r) + ' polygon(s) set</span>' : '<span class="text-amber-600">No polygon — select this region and draw one</span>'}
      </div>
    </div>
  `).join('');
}

function focusRegion(id) {
  const layers = regionPolygonLayers[id];
  if (layers && layers.length) {
    const group = L.featureGroup(layers);
    map.fitBounds(group.getBounds().pad(0.1));
  }
  document.getElementById('drawRegionSelect').value = id;
  const drawBtn = document.getElementById('btnDraw');
  drawBtn.disabled = false; drawBtn.style.opacity = '1';
}

function parsePolygons(jsonStr) {
  try {
    const data = JSON.parse(jsonStr);
    if (!Array.isArray(data) || !data.length) return [];
    if (Array.isArray(data[0]) && data[0].length === 2 && typeof data[0][0] === 'number') {
      return [data];
    }
    return data;
  } catch(e) { return []; }
}

function getPolygonCount(r) {
  if (!r.polygon_coordinates) return 0;
  return parsePolygons(r.polygon_coordinates).length;
}

function renderRegionsOnMap() {
  Object.values(regionPolygonLayers).forEach(layers => {
    if (Array.isArray(layers)) layers.forEach(l => map.removeLayer(l));
    else map.removeLayer(layers);
  });
  regionPolygonLayers = {};
  regionsData.forEach(r => {
    if (!r.polygon_coordinates) return;
    const polygons = parsePolygons(r.polygon_coordinates);
    if (!polygons.length) return;
    regionPolygonLayers[r.id] = [];
    polygons.forEach((coords, idx) => {
      if (!Array.isArray(coords) || coords.length < 3) return;
      const layer = L.polygon(coords, { color: r.color, weight: 2, fillOpacity: 0.15, fillColor: r.color });
      const label = polygons.length > 1 ? r.name + ' (#' + (idx + 1) + ')' : r.name;
      layer.bindTooltip(label, { permanent: false, direction: 'center' });
      layer.on('click', function() {
        if (confirm('Remove polygon #' + (idx + 1) + ' from ' + r.name + '?')) {
          removePolygon(r.id, idx);
        }
      });
      layer.addTo(map);
      regionPolygonLayers[r.id].push(layer);
    });
  });
  const allLayers = Object.values(regionPolygonLayers).flat();
  if (allLayers.length) map.fitBounds(L.featureGroup(allLayers).getBounds().pad(0.1));
}

async function removePolygon(regionId, polygonIndex) {
  try {
    const d = await (await fetch('/regions/remove-polygon', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ region_id: regionId, polygon_index: polygonIndex })
    })).json();
    if (d.success) {
      showMapStatus(d.message, 'success');
      await loadRegions();
      await loadStats();
    } else alert(d.message || 'Error');
  } catch(e) { alert('Error removing polygon'); }
}

function populateRegionDropdowns() {
  ['drawRegionSelect','areaFilterRegion','areaRegionId','assignRegionSelect'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const first = el.querySelector('option:first-child');
    el.innerHTML = `<option value="">${first?.textContent || '-- Select --'}</option>` +
      regionsData.map(r => `<option value="${r.id}">${esc(r.name)}</option>`).join('');
  });
  document.getElementById('drawRegionSelect').onchange = function() {
    const drawBtn = document.getElementById('btnDraw');
    drawBtn.disabled = !this.value;
    drawBtn.style.opacity = this.value ? '1' : '0.4';
  };
}

// ===== AREA SEARCH ON MAP (Nominatim) =====
async function searchArea() {
  const q = document.getElementById('areaSearchInput').value.trim();
  if (!q) return;
  showMapStatus('Searching for "' + q + '"...', 'info');

  if (searchMarker) { map.removeLayer(searchMarker); searchMarker = null; }
  if (searchPolygonLayer) { map.removeLayer(searchPolygonLayer); searchPolygonLayer = null; }

  try {
    const url = `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(q + ', Islamabad Rawalpindi, Pakistan')}&format=json&limit=1&polygon_geojson=1`;
    const resp = await fetch(url, { headers: { 'User-Agent': 'NizamiFarms/1.0' } });
    const results = await resp.json();

    if (!results.length) {
      showMapStatus('No results found for "' + q + '". Try a different name.', 'error');
      return;
    }
    const r = results[0];
    const lat = parseFloat(r.lat), lng = parseFloat(r.lon);

    searchMarker = L.marker([lat, lng], {
      title: r.display_name,
      icon: L.divIcon({ className: '', html: '<div style="background:#6366F1;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.3);">' + esc(q) + '</div>', iconAnchor: [30, 15] })
    }).addTo(map).bindPopup(`<b>${esc(r.display_name)}</b><br><small>${lat.toFixed(5)}, ${lng.toFixed(5)}</small>`);

    if (r.geojson && (r.geojson.type === 'Polygon' || r.geojson.type === 'MultiPolygon')) {
      searchPolygonLayer = L.geoJSON(r.geojson, { style: { color: '#6366F1', weight: 3, fillOpacity: 0.2, dashArray: '5,5' } }).addTo(map);
      map.fitBounds(searchPolygonLayer.getBounds().pad(0.1));
      showMapStatus('Found "' + r.display_name + '" with boundary. Now draw your region polygon covering this area.', 'success');
    } else {
      map.setView([lat, lng], 14);
      showMapStatus('Found "' + r.display_name + '" (point only, no boundary available). You can draw a polygon around this location.', 'success');
    }
  } catch(e) {
    showMapStatus('Search error: ' + e.message, 'error');
  }
}

// ===== POLYGON DRAWING =====
function startDrawing() {
  if (currentDrawHandler) currentDrawHandler.disable();
  drawnItems.clearLayers();
  const regionId = document.getElementById('drawRegionSelect').value;
  const region = regionsData.find(r => r.id == regionId);
  if (!region) { alert('Select a region first'); return; }

  currentDrawHandler = new L.Draw.Polygon(map, {
    shapeOptions: { color: region.color, weight: 3, fillOpacity: 0.25 }
  });
  currentDrawHandler.enable();
  showMapStatus('Click on the map to place polygon points. Double-click to finish.', 'info');

  map.off('draw:created');
  map.on('draw:created', function(e) {
    drawnItems.clearLayers();
    drawnItems.addLayer(e.layer);
    document.getElementById('btnSavePolygon').style.display = 'inline-block';
    showMapStatus('Polygon drawn! Click "Save Polygon" to save it for ' + region.name, 'success');
  });
}

function clearDrawing() {
  drawnItems.clearLayers();
  if (currentDrawHandler) { currentDrawHandler.disable(); currentDrawHandler = null; }
  document.getElementById('btnSavePolygon').style.display = 'none';
  document.getElementById('mapStatus').classList.add('hidden');
}

async function savePolygon() {
  const regionId = document.getElementById('drawRegionSelect').value;
  if (!regionId) { alert('Select a region first'); return; }
  const layers = drawnItems.getLayers();
  if (!layers.length) { alert('Draw a polygon first'); return; }

  const region = regionsData.find(r => r.id == regionId);
  const latlngs = layers[0].getLatLngs()[0];
  const coords = latlngs.map(ll => [ll.lat, ll.lng]);
  const center = layers[0].getBounds().getCenter();

  try {
    const d = await (await fetch('/regions/save-polygon', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        region_id: regionId,
        polygon_coordinates: JSON.stringify(coords),
        center_lat: center.lat,
        center_lng: center.lng,
      })
    })).json();

    if (d.success) {
      showMapStatus(d.message + ' for ' + region.name, 'success');
      clearDrawing();
      await loadRegions();
      await loadStats();
      if (confirm(d.message + ' for ' + region.name + '! Run batch detection now?')) {
        await runQuickBatch('customers');
        await runQuickBatch('orders');
      }
    } else alert(d.message || 'Error');
  } catch(e) { alert('Error saving polygon'); }
}

// ===== AREAS =====
async function loadAreas() {
  try {
    const rid = document.getElementById('areaFilterRegion')?.value || '';
    const url = rid ? `/regions/areas?region_id=${rid}` : '/regions/areas';
    const d = await (await fetch(url)).json();
    if (d.success) renderAreasTable(d.areas);
  } catch(e) { console.error(e); }
}

function renderAreasTable(areas) {
  const tbody = document.getElementById('areasTableBody');
  const empty = document.getElementById('areasEmpty');
  if (!areas.length) { tbody.innerHTML = ''; empty.classList.remove('hidden'); return; }
  empty.classList.add('hidden');
  tbody.innerHTML = areas.map(a => {
    const kw = a.keywords ? JSON.parse(a.keywords) : [];
    const aJson = JSON.stringify(a).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
    return `<tr class="hover:bg-gray-50">
      <td class="px-4 py-2 font-medium text-gray-900">${esc(a.area_name)}</td>
      <td class="px-4 py-2"><span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full" style="background:${esc(a.region_color)}"></span>${esc(a.region_name)}</span></td>
      <td class="px-4 py-2 text-gray-500 text-xs max-w-xs truncate">${kw.slice(0,5).map(k=>esc(k)).join(', ')}${kw.length>5?'...':''}</td>
      <td class="px-4 py-2 space-x-2">
        <button onclick='editArea(${aJson})' style="color:#2563eb;font-size:12px;font-weight:500;background:none;border:none;cursor:pointer;text-decoration:underline;">Edit</button>
        <button onclick="deleteArea(${a.id},'${esc(a.area_name)}')" style="color:#dc2626;font-size:12px;font-weight:500;background:none;border:none;cursor:pointer;text-decoration:underline;">Delete</button>
      </td>
    </tr>`;
  }).join('');
}

function openAreaModal(a) {
  document.getElementById('areaModalTitle').textContent = a ? 'Edit Area' : 'Add Area';
  document.getElementById('areaId').value = a?.id || '';
  document.getElementById('areaRegionId').value = a?.region_id || '';
  document.getElementById('areaName').value = a?.area_name || '';
  document.getElementById('areaKeywords').value = a?.keywords ? JSON.parse(a.keywords).join(', ') : '';
  document.getElementById('areaModal').style.display = 'block';
}
function closeAreaModal() { document.getElementById('areaModal').style.display = 'none'; }
function editArea(a) { openAreaModal(typeof a === 'string' ? JSON.parse(a) : a); }

async function saveArea(e) {
  e.preventDefault();
  const kw = document.getElementById('areaKeywords').value;
  const keywords = kw ? kw.split(',').map(k => k.trim().toLowerCase()).filter(Boolean) : [];
  try {
    const d = await (await fetch('/regions/areas/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({
        id: document.getElementById('areaId').value || null,
        region_id: document.getElementById('areaRegionId').value,
        area_name: document.getElementById('areaName').value,
        keywords: JSON.stringify(keywords), is_active: 1
      })
    })).json();
    if (d.success) { closeAreaModal(); loadAreas(); loadRegions(); } else alert(d.message);
  } catch(e) { alert('Error'); }
}

async function deleteArea(id, name) {
  if (!confirm('Delete area "' + name + '"?')) return;
  try {
    const d = await (await fetch('/regions/areas/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF } })).json();
    if (d.success) { loadAreas(); loadRegions(); }
  } catch(e) { alert('Error'); }
}

// ===== RIDERS =====
async function loadRiders() {
  try {
    const d = await (await fetch('/regions/riders/active')).json();
    if (d.success) {
      document.getElementById('assignRiderSelect').innerHTML = '<option value="">-- Select rider --</option>' +
        d.riders.map(r => `<option value="${r.id}">${esc(r.fullname)}</option>`).join('');
    }
  } catch(e) {}
}

async function loadRiderAssignments() {
  try {
    const d = await (await fetch('/regions/riders')).json();
    if (!d.success) return;
    const el = document.getElementById('riderAssignmentsList');
    if (!d.assignments.length) { el.innerHTML = '<div class="p-4 text-center text-gray-400 text-sm">No assignments yet</div>'; return; }
    el.innerHTML = d.assignments.map(a => `
      <div class="p-3 flex items-center justify-between hover:bg-gray-50">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-sm font-medium">${esc(a.rider_name)}</span>
          <span class="text-gray-300">&#8594;</span>
          <span class="inline-flex items-center gap-1 text-sm"><span class="w-2 h-2 rounded-full" style="background:${esc(a.region_color)}"></span>${esc(a.region_name)}</span>
          ${a.is_primary ? '<span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">Primary</span>' : ''}
        </div>
        <button onclick="removeRiderAssignment(${a.id})" style="color:#dc2626;font-size:12px;font-weight:500;background:none;border:none;cursor:pointer;text-decoration:underline;">Remove</button>
      </div>
    `).join('');
  } catch(e) {}
}

async function assignRider() {
  const rid = document.getElementById('assignRiderSelect').value;
  const regid = document.getElementById('assignRegionSelect').value;
  if (!rid || !regid) { alert('Select both rider and region'); return; }
  try {
    const d = await (await fetch('/regions/riders/assign', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ rider_user_id: rid, region_id: regid, is_primary: document.getElementById('assignPrimary').checked })
    })).json();
    if (d.success) { loadRiderAssignments(); loadRegions(); } else alert(d.message);
  } catch(e) { alert('Error'); }
}

async function removeRiderAssignment(id) {
  if (!confirm('Remove this assignment?')) return;
  try { await fetch('/regions/riders/' + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF } }); loadRiderAssignments(); loadRegions(); } catch(e) {}
}

// ===== AUTO-ASSIGN RIDERS =====
async function runAutoAssignFromRegions(mode) {
  const msg = mode === 'reassign'
    ? 'REASSIGN ALL open orders (except out for delivery) to riders based on region mapping?'
    : 'Assign riders to unassigned open orders based on region mapping?';
  if (!confirm(msg)) return;
  const el = document.getElementById('autoAssignRegionsResult');
  el.textContent = 'Processing...'; el.classList.remove('hidden');
  try {
    const d = await (await fetch('/regions/auto-assign-riders', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ mode })
    })).json();
    el.textContent = d.message || 'Done';
  } catch(e) { el.textContent = 'Error'; }
}

// ===== BATCH DETECT =====
function openBatchModal() { document.getElementById('batchModal').style.display = 'block'; document.getElementById('batchResult').classList.add('hidden'); }
function closeBatchModal() { document.getElementById('batchModal').style.display = 'none'; }
function openRedetectModal() { document.getElementById('redetectModal').style.display = 'block'; document.getElementById('redetectResult').classList.add('hidden'); }
function closeRedetectModal() { document.getElementById('redetectModal').style.display = 'none'; }

async function runBatch(action, type) {
  const btn = document.getElementById('batchDetectBtn');
  if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
  const res = document.getElementById('batchResult');
  res.textContent = 'Processing...'; res.classList.remove('hidden');

  try {
    const d = await (await fetch('/regions/' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ type, limit: 15000 })
    })).json();
    res.textContent = d.message || 'Done';
    loadStats(); loadRegions();
  } catch(e) { res.textContent = 'Error: ' + e.message; }
  finally { if (btn) { btn.disabled = false; btn.style.opacity = '1'; } }
}

async function runRedetect() {
  const mode = document.querySelector('input[name="redetectMode"]:checked')?.value || 'preserve_manual';
  const resetManual = mode === 'reset_all';

  if (resetManual && !confirm('This will CLEAR all regions (including manually set) and re-detect from scratch. Are you sure?')) return;

  const btn = document.getElementById('redetectBtn');
  btn.disabled = true; btn.textContent = 'Processing all customers...'; btn.style.opacity = '0.5';
  const res = document.getElementById('redetectResult');
  res.textContent = 'Processing... this may take a minute for large datasets.'; res.classList.remove('hidden');

  try {
    const d = await (await fetch('/regions/redetect-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ type: 'customers', reset_manual: resetManual })
    })).json();
    res.textContent = d.message || 'Done';
    loadStats(); loadRegions();
  } catch(e) { res.textContent = 'Error: ' + e.message; res.style.background = '#fef2f2'; res.style.borderColor = '#fecaca'; res.style.color = '#991b1b'; }
  finally { btn.disabled = false; btn.textContent = 'Re-detect All Customers'; btn.style.opacity = '1'; }
}

// ===== CUSTOMER DATA =====
function openCustomerDataModal() { document.getElementById('customerDataModal').style.display = 'block'; loadCustomerData(); }
function closeCustomerDataModal() { document.getElementById('customerDataModal').style.display = 'none'; }

async function loadCustomerData() {
  document.getElementById('citiesData').innerHTML = '<div class="text-sm text-gray-400">Loading...</div>';
  document.getElementById('addressesData').innerHTML = '<div class="text-sm text-gray-400">Loading...</div>';
  try {
    const d = await (await fetch('/regions/customer-cities')).json();
    if (!d.success) return;

    // Cities
    let html = '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50"><tr><th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500">City</th><th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500">Customers</th></tr></thead><tbody class="divide-y">';
    d.cities.forEach(c => {
      html += `<tr class="hover:bg-gray-50"><td class="px-3 py-1.5">${esc(c.city)}</td><td class="px-3 py-1.5 font-medium">${c.customer_count}</td></tr>`;
    });
    html += '</tbody></table></div>';
    document.getElementById('citiesData').innerHTML = html;

    // Addresses
    let ahtml = '<div class="overflow-x-auto max-h-64 overflow-y-auto"><table class="min-w-full text-sm"><thead class="bg-gray-50 sticky top-0"><tr><th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500">Address</th><th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500">City</th><th class="px-3 py-1.5 text-left text-xs font-medium text-gray-500">Region</th></tr></thead><tbody class="divide-y">';
    d.recent_addresses.forEach(a => {
      const regionName = a.delivery_region_id ? (regionsData.find(r => r.id == a.delivery_region_id)?.name || 'ID:' + a.delivery_region_id) : '<span class="text-red-500">None</span>';
      ahtml += `<tr class="hover:bg-gray-50"><td class="px-3 py-1.5 max-w-xs truncate">${esc(a.address1)}</td><td class="px-3 py-1.5">${esc(a.city||'')}</td><td class="px-3 py-1.5">${regionName}</td></tr>`;
    });
    ahtml += '</tbody></table></div>';
    document.getElementById('addressesData').innerHTML = ahtml;
  } catch(e) { document.getElementById('citiesData').innerHTML = '<div class="text-sm text-red-500">Error loading data</div>'; }
}

// ===== REGION CREATE/EDIT =====
let selectedRegionColor = '#6366f1';

function openRegionModal(regionId) {
  if (regionId) {
    const r = regionsData.find(x => x.id == regionId);
    if (!r) return;
    document.getElementById('regionModalTitle').textContent = 'Edit Region';
    document.getElementById('regionModalId').value = r.id;
    document.getElementById('regionModalName').value = r.name;
    document.getElementById('regionModalCode').value = r.code;
    selectRegionColor(r.color || '#6366f1');
  } else {
    document.getElementById('regionModalTitle').textContent = 'Add New Region';
    document.getElementById('regionModalId').value = '';
    document.getElementById('regionModalName').value = '';
    document.getElementById('regionModalCode').value = '';
    selectRegionColor('#6366f1');
  }
  document.getElementById('regionModal').style.display = 'block';
}

function closeRegionModal() { document.getElementById('regionModal').style.display = 'none'; }

function selectRegionColor(color) {
  selectedRegionColor = color;
  document.getElementById('regionModalColor').value = color;
  document.querySelectorAll('#regionColorPicker span').forEach(el => {
    el.style.border = el.getAttribute('data-color') === color ? '3px solid #111827' : '2px solid #e5e7eb';
  });
}

async function saveRegionFromModal() {
  const id = document.getElementById('regionModalId').value;
  const name = document.getElementById('regionModalName').value.trim();
  const code = document.getElementById('regionModalCode').value.trim();
  const color = selectedRegionColor;
  if (!name) { alert('Region name is required'); return; }
  if (!code) { alert('Region code is required'); return; }

  const btn = document.getElementById('regionModalSaveBtn');
  btn.disabled = true; btn.style.opacity = '0.5'; btn.textContent = 'Saving...';

  const payload = { name, code: code.toLowerCase(), color, is_active: true };
  if (id) {
    payload.id = parseInt(id);
    payload.sort_order = regionsData.find(x => x.id == id)?.sort_order || 0;
  } else {
    payload.sort_order = regionsData.length;
  }

  try {
    const d = await (await fetch('/regions/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(payload)
    })).json();
    if (d.success) {
      closeRegionModal();
      loadRegions();
    } else {
      alert(d.message || 'Failed to save');
    }
  } catch(e) { alert('Error: ' + e.message); }
  finally { btn.disabled = false; btn.style.opacity = '1'; btn.textContent = 'Save Region'; }
}

// ===== TABS =====
function switchTab(tab) {
  ['regions','areas','riders'].forEach(t => {
    document.getElementById('panel'+t.charAt(0).toUpperCase()+t.slice(1)).classList.toggle('hidden', t !== tab);
    const btn = document.getElementById('tab'+t.charAt(0).toUpperCase()+t.slice(1));
    btn.style.borderBottomColor = t === tab ? '#2563eb' : 'transparent';
    btn.style.color = t === tab ? '#2563eb' : '#6b7280';
  });
  if (tab === 'regions') setTimeout(() => map.invalidateSize(), 100);
}

function esc(s) { if(s==null)return''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
@endsection
