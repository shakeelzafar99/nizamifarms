   {{-- NF: Phase 1 sidebar visual polish. Pure CSS; no JS/ID/class names removed. --}}
   <style>
       /* Sidebar surface polish (phase 1) */
       #sidebar .kt-menu-link { transition: background-color .15s ease, color .15s ease, border-color .15s ease; }
       #sidebar .kt-menu-link:hover { background-color: #EFF6FF !important; } /* blue-50 */
       #sidebar .kt-menu-link.nf-active {
           background-color: #EFF6FF !important; /* blue-50 */
           border-color: transparent !important;
           box-shadow: inset 2px 0 0 #2563EB; /* blue-600 left accent */
       }
       #sidebar .kt-menu-link.nf-active .kt-menu-title { color: #1D4ED8 !important; font-weight: 600 !important; } /* blue-700 */
       #sidebar .kt-menu-link.nf-active .kt-menu-icon,
       #sidebar .kt-menu-link.nf-active .kt-menu-icon i { color: #1D4ED8 !important; }
       /* Section headings: quieter + tighter so the nav has structure */
       #sidebar .kt-menu-heading {
           font-size: 11px !important;
           font-weight: 600 !important;
           letter-spacing: 0.06em !important;
           color: #9CA3AF !important; /* gray-400 */
       }
       /* While the quick-search is active, force the Administration wrapper to be visible
          so matches inside a collapsed section are still findable. */
       #sidebar_menu.nf-searching #nf-admin-items { display: block !important; }
   </style>
   <!-- Sidebar -->
   <div class="kt-sidebar bg-white border-e border-gray-200 fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]" data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0" id="sidebar">
       <div class="kt-sidebar-header hidden lg:flex flex-col items-stretch relative px-3 lg:px-6 shrink-0 border-b border-gray-200 pb-3" id="sidebar_header">
           <div class="flex items-center justify-between py-3">
               <a class="text-gray-900 font-medium uppercase" href="/dashboard">
                   Nizami Farms
               </a>
               <button class="kt-btn kt-btn-outline kt-btn-icon size-[30px] absolute start-full top-2/4 -translate-x-2/4 -translate-y-2/4 rtl:translate-x-2/4" data-kt-toggle="body" data-kt-toggle-class="kt-sidebar-collapse" id="sidebar_toggle">
                   <i class="ki-filled ki-black-left-line kt-toggle-active:rotate-180 transition-all duration-300 rtl:translate rtl:rotate-180 rtl:kt-toggle-active:rotate-0">
                   </i>
               </button>
           </div>
           
          <!-- (moved) user badge placed within menu area to avoid clipping -->
       </div>
      <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2 bg-white overflow-hidden" id="sidebar_content">
              <div class="kt-scrollable-y-hover grow shrink-0 flex ps-2 lg:ps-5 pe-1 lg:pe-3 overflow-y-auto" 
                   style="scrollbar-width: thin; scrollbar-color: #9ca3af #f3f4f6;"
                   data-kt-scrollable="true" data-kt-scrollable-dependencies="#sidebar_header" data-kt-scrollable-height="auto" data-kt-scrollable-offset="0px" data-kt-scrollable-wrappers="#sidebar_content" id="sidebar_scrollable">
               <!-- Sidebar Menu -->
                  <div class="kt-menu flex flex-col grow gap-1" data-kt-menu="true" data-kt-menu-accordion-expand-all="false" id="sidebar_menu">
                      @if(auth()->check())
                      <div class="flex items-center gap-2 px-2 py-2 mb-1 mt-2 rounded-md">
                          <div class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white font-medium text-[10px] shrink-0">
                              {{ strtoupper(substr(auth()->user()->fullname ?? auth()->user()->name ?? 'U', 0, 1)) }}
                          </div>
                          <span class="text-[12px] font-medium text-gray-800 truncate">
                              {{ auth()->user()->fullname ?? auth()->user()->name ?? 'User' }}
                          </span>
                      </div>
                      {{-- NF: Phase 2C — sidebar quick-search. Pure client-side DOM filter over .kt-menu-title text. --}}
                      <div class="relative px-2 mb-2">
                          <i class="ki-filled ki-magnifier absolute top-1/2 -translate-y-1/2 text-gray-400 text-[12px] pointer-events-none" style="inset-inline-start: 16px;"></i>
                          <input id="nf-sidebar-search" type="text"
                                 placeholder="Search menu…"
                                 autocomplete="off" spellcheck="false"
                                 class="w-full ps-8 pe-2 py-1.5 text-[12px] bg-gray-50 border border-gray-200 rounded-md focus:outline-none focus:border-blue-500 focus:bg-white transition-colors" />
                      </div>
                      @endif
                   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                       <a href="/dashboard">
                           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">

                               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                   <i class="ki-filled ki-element-11 text-lg">
                                   </i>
                               </span>
                               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                   Dashboards
                               </span>
                           </div>
                      </a>
                  </div>
                  
                  <!-- Get User Role -->
                  @php
                      $userRole = null;
                      $isTaimurRole = false;
                      if (auth()->check()) {
                          $userRole = \DB::table('t_sys_user_role as ur')
                              ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                              ->where('ur.user_id', auth()->id())
                              ->value('r.type');
                          $isTaimurRole = \DB::table('t_sys_user_role as ur')
                              ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                              ->where('ur.user_id', auth()->id())
                              ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
                              ->exists();
                      }
                  @endphp
                  
                  <!-- Attendance Section -->
                  @if($userRole === 'rider')
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/attendance/mine">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-time text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  My Attendance
                              </span>
                          </div>
                      </a>
                  </div>
                  @else
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/attendance">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-time text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Attendance
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif
                  
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                          Orders
                      </span>
                  </div>
                   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                       <a href="/orders">
                           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                   <i class="ki-filled ki-security-user text-lg">
                                   </i>
                               </span>
                               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Invoices
                              </span>
                          </div>
                      </a>
                  </div>
                  @if($userRole !== 'rider')
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="{{ route('riders-map') }}">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-map text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Riders Map
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if(auth()->user()->hasMobilePermission('view_delivery_regions'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/regions">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-geolocation text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Delivery Regions
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/orders?source=shopify">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-shop text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Shopify
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/customers">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-people text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Customers
                              </span>
                          </div>
                      </a>
                  </div>
                  @if(auth()->user()->hasMobilePermission('view_whatsapp_messages') || auth()->user()->hasMobilePermission('view_whatsapp_messages_limited'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/messages">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-messages text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Messages
                              </span>
                              <span id="wa-unread-badge" class="hidden ml-auto bg-green-500 text-white text-xs font-bold rounded-full min-w-[20px] h-5 flex items-center justify-center px-1.5"></span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($isTaimurRole || auth()->user()->hasMobilePermission('view_campaigns'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/campaigns">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-notification-on text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Campaigns
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/orders/open-quantities">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-chart-line text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Open Order Quantities
                              </span>
                          </div>
                      </a>
                  </div>
                  
                  @php
                      $qurbaniEnabled = \App\Models\FIN\ConfigModel::get('qurbani_mode_enabled', '1') === '1';
                  @endphp
                  @if($qurbaniEnabled && $userRole !== 'rider')
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                          Qurbani
                      </span>
                  </div>
                  {{-- Qurbani Orders: region-wise item-level dispatch view (May-2026). --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/qurbani/orders">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-parcel text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Qurbani Orders
                              </span>
                          </div>
                      </a>
                  </div>
                  {{-- Qurbani Riders: read-only web mirror of the mobile manager
                       dispatch view (Phase 1, May-2026). Sits between Orders and
                       Invoices because the operational flow is Orders → Riders
                       (dispatch & ETA tracking) → Invoices. --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/qurbani/riders">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-delivery text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Qurbani Riders
                              </span>
                          </div>
                      </a>
                  </div>
                  {{-- Qurbani Invoices: invoice-level page (formerly Qurbani Orders). --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/qurbani/invoices">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-document text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Qurbani Invoices
                              </span>
                          </div>
                      </a>
                  </div>
                  {{-- Phase 5 (May-2026) — Qurbani Performance dashboard.
                       Sits between Invoices and Settings so the user can
                       jump to it after looking at invoice-level totals. --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/qurbani/performance">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-chart-line-up text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Qurbani Performance
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/qurbani-settings">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-setting-2 text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Qurbani Settings
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif

                  {{-- Analytics Sandbox — only visible when the .env flag is on. --}}
                  @if(filter_var(env('ANALYTICS_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN))
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                          Analytics (Sandbox)
                      </span>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/sandbox">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-chart-line-up text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Sandbox dashboards
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif

                  <!-- Products Section -->
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                          Products
                      </span>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/products">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-package text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Products
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/coupons">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-discount text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Coupons
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/shipping">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-delivery text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Shipping
                              </span>
                          </div>
                      </a>
                  </div>
                  
   <!-- 🌿 Khaas Section (permission-based: access_khaas_mode mobile permission) -->
   @php
       $hasKhaasAccess = false;
       if (auth()->check()) {
           $khaasUser = auth()->user();
           if (!$khaasUser->relationLoaded('roles')) {
               $khaasUser->load(['roles.mobilePermissions']);
           }
           $hasKhaasAccess = $khaasUser->hasMobilePermission('access_khaas_mode');
           
           if ($hasKhaasAccess) {
               $khaasBusinessUnit = \App\Models\FIN\BusinessUnitModel::where('code', 'KHAAS')->where('is_active', 1)->first();
               $khaasPendingTransfers = $khaasBusinessUnit 
                   ? \App\Models\CRM\WarehouseTransferModel::where('business_unit_id', $khaasBusinessUnit->id)->where('status', 'pending')->count() 
                   : 0;
           }
       }
   @endphp
   @if($hasKhaasAccess && isset($khaasBusinessUnit) && $khaasBusinessUnit)
   <div class="kt-menu-item pt-2.25 pb-px">
       <span class="kt-menu-heading uppercase text-xs font-medium text-amber-600 ps-[10px] pe-[10px]">
           🌿 {{ $khaasBusinessUnit->name }}
       </span>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('khaas.dashboard') }}">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]">
                   <i class="ki-filled ki-home-2 text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">
                   {{ $khaasBusinessUnit->name }} Dashboard
               </span>
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('khaas.products') }}">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]">
                   <i class="ki-filled ki-package text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">
                   Products & Inventory
               </span>
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('khaas.meat-order') }}">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]">
                   <i class="ki-filled ki-delivery text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">
                   Meat Order
               </span>
               @php
                   $khaasPendingReceive = $khaasBusinessUnit
                       ? \DB::table('t_crm_khaas_storage_order as so')
                           ->join('t_crm_prod_order as o', 'o.id', '=', 'so.order_id')
                           ->where('so.khaas_business_unit_id', $khaasBusinessUnit->id)
                           ->whereNotIn('so.status', ['received', 'cancelled'])
                           ->whereIn('o.order_status', ['delivered', 'completed'])
                           ->count()
                       : 0;
               @endphp
               @if($khaasPendingReceive > 0)
               <span class="kt-badge kt-badge-sm font-bold" style="background-color: #EF4444; color: white; border-radius: 999px; padding: 2px 6px; font-size: 10px;">
                   {{ $khaasPendingReceive }}
               </span>
               @endif
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('khaas.inventory') }}">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]">
                   <i class="ki-filled ki-box text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">
                   Planning
               </span>
               @php
                   $khaasPendingDemand = $khaasBusinessUnit
                       ? \DB::table('t_crm_khaas_production_demand')
                           ->where('business_unit_id', $khaasBusinessUnit->id)
                           ->where('status', 'submitted')
                           ->count()
                       : 0;
               @endphp
               @if($khaasPendingDemand > 0)
               <span class="kt-badge kt-badge-sm font-bold" style="background-color: #F59E0B; color: white; border-radius: 999px; padding: 2px 6px; font-size: 10px;">
                   {{ $khaasPendingDemand }}
               </span>
               @endif
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('khaas.operations') }}">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]">
                   <i class="ki-filled ki-element-11 text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">
                   Operations
               </span>
               @if($khaasPendingTransfers > 0)
               <span class="kt-badge kt-badge-sm font-bold" style="background-color: #f59e0b; color: white; border-radius: 999px; padding: 2px 6px; font-size: 10px;">
                   {{ $khaasPendingTransfers }}
               </span>
               @endif
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('khaas.sales-report') }}">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]">
                   <i class="ki-filled ki-chart-line-up-2 text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">
                   Sales Report
               </span>
           </div>
       </a>
   </div>
   @endif
   
   <!-- Finance Section -->
   <div class="kt-menu-item pt-2.25 pb-px">
       <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
           Finance
       </span>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="/finance/vendors">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                   <i class="ki-filled ki-shop text-lg">
                   </i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                   Vendors
               </span>
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="/finance/employee">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                   <i class="ki-filled ki-dollar text-lg">
                   </i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                   NF Ledger
               </span>
           </div>
       </a>
   </div>
   
   @php
       $pendingInvoiceSettlements = \App\Models\FIN\LedgerModel::where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
           ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_PENDING)
           ->where('description', 'LIKE', '%Settlement%')
           ->count();
   @endphp
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="{{ route('fin.employee.all-outstanding-invoices') }}" title="Daily Closing & Invoice Settlements">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                   <i class="ki-filled ki-calendar-tick text-lg">
                   </i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">
                   Daily Closing
               </span>
               @if($pendingInvoiceSettlements > 0)
               <span class="kt-badge kt-badge-sm kt-badge-warning font-bold">
                   {{ $pendingInvoiceSettlements }}
               </span>
               @endif
           </div>
       </a>
   </div>
   
   @php
       $pendingSettlements = \App\Models\Request\RequestModel::where('settlement_status', 'pending')->count();
   @endphp
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="/finance/expenses" title="Expense Management & Settlements">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                   <i class="ki-filled ki-chart-line-up-2 text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">
                   Expense Management
               </span>
               @if($pendingSettlements > 0)
               <span class="kt-badge kt-badge-sm kt-badge-warning font-bold">
                   {{ $pendingSettlements }}
               </span>
               @endif
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="/finance/ledger">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                   <i class="ki-filled ki-book text-lg">
                   </i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                   Overall Ledger
               </span>
           </div>
       </a>
   </div>
   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
       <a href="/reports">
           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                   <i class="ki-filled ki-chart-simple text-lg"></i>
               </span>
               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                   Reports
               </span>
           </div>
       </a>
   </div>
                  
                 <!-- Requests & Approvals Section -->
                 <div class="kt-menu-item pt-2.25 pb-px">
                     <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                         Requests & Approvals
                     </span>
                 </div>
                 <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                     <a href="/requests">
                         <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                             <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                 <i class="ki-filled ki-file-sheet text-lg">
                                 </i>
                             </span>
                             <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                 My Requests
                             </span>
                         </div>
                     </a>
                 </div>
                 @if($userRole !== 'rider')
                 @php
                     // Calculate pending approvals count for badge (simplified)
                     // Financial transactions
                     $pendingLedgerCount = \App\Models\FIN\LedgerModel::where('approval_status', 'pending')->count();
                     
                     // Expense requests - simplified count for badge (detailed filtering happens on dashboard)
                     $user = auth()->user();
                     $hasLevel1Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
                     $hasLevel2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
                     
                     $pendingRequestsCount = 0;
                     if ($hasLevel1Rights || $hasLevel2Rights) {
                         // Show count of all pending requests if user has any approval rights
                         $pendingRequestsCount = \App\Models\Request\RequestModel::where('status', 'pending')->count();
                     }
                     
                     $totalPendingApprovals = $pendingLedgerCount + $pendingRequestsCount;
                 @endphp
                <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                    <a href="/approvals" title="Approve requests, invoices, payments & transfers">
                        <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                            <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                <i class="ki-filled ki-check-circle text-lg"></i>
                            </span>
                            <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">
                                Approvals Dashboard
                            </span>
                            @if($totalPendingApprovals > 0)
                            <span class="kt-badge kt-badge-sm kt-badge-danger font-bold">
                                {{ $totalPendingApprovals }}
                            </span>
                            @endif
                        </div>
                    </a>
               </div>
               
               {{-- ⭐ Online Approvals - Dedicated page for online payments --}}
               @php
                   $pendingOnlineCount = \App\Models\FIN\LedgerModel::whereIn('approval_status', ['pending', 'pending_l1', 'pending_l2'])
                       ->whereNull('request_id')
                       ->where('mode', 'online')
                       ->count();
               @endphp
               <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                   <a href="/approvals/online" title="Approve online payment invoices">
                       <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                           <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                               <i class="ki-filled ki-credit-cart text-lg"></i>
                           </span>
                           <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">
                               Online Approvals
                           </span>
                           @if($pendingOnlineCount > 0)
                           <span class="kt-badge kt-badge-sm kt-badge-primary font-bold">
                               {{ $pendingOnlineCount }}
                           </span>
                           @endif
                       </div>
                   </a>
              </div>
                @endif
                
                <!-- HR & Salary Section -->
                @if($userRole !== 'rider')
                <div class="kt-menu-item pt-2.25 pb-px">
                    <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                        HR & Salary
                    </span>
                </div>
                @if(auth()->user()->hasPermission('view_employee_salaries'))
                <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                    <a href="/hr/employees">
                        <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                            <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                <i class="ki-filled ki-badge text-lg"></i>
                            </span>
                            <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                Employee Salaries
                            </span>
                        </div>
                    </a>
                </div>
                @endif
                @endif
                 
                <!-- Administration Section (Phase 2B: collapsible for non-riders, remembered in localStorage) -->
                @if($userRole !== 'rider')
                <div class="kt-menu-item pt-2.25 pb-px">
                    <button type="button" id="nf-admin-toggle"
                            class="flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group">
                        <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 group-hover:text-gray-600 transition-colors">
                            Administration
                        </span>
                        <i id="nf-admin-chevron" class="ki-filled ki-down text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                    </button>
                </div>
                @else
                <div class="kt-menu-item pt-2.25 pb-px">
                    <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">
                        Administration
                    </span>
                </div>
                @endif

                <div id="nf-admin-items">
                 @if($userRole !== 'rider')
                 <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                     <a href="/admin/operations">
                         <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                             <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                 <i class="ki-filled ki-setting-3 text-lg">
                                 </i>
                             </span>
                             <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                 Operations
                             </span>
                         </div>
                     </a>
                 </div>
                 
                 <!-- Finance Admin Subsection -->
                 <div class="kt-menu-item pt-2 pb-px ps-2">
                     <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">
                         Finance Admin
                     </span>
                 </div>
                <div class="kt-menu-item ps-2" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                    <a href="/finance/accounts">
                        <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                            <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                <i class="ki-filled ki-category text-lg">
                                </i>
                            </span>
                            <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                Accounts
                            </span>
                        </div>
                    </a>
                </div>
                <div class="kt-menu-item ps-2" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                    <a href="/finance/business-units">
                        <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                            <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                <i class="ki-filled ki-briefcase text-lg">
                                </i>
                            </span>
                            <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                Business Units
                            </span>
                        </div>
                    </a>
                </div>
                @php
                     $openActionItems = \App\Models\FIN\ActionItemModel::where('status', 'open')->count();
                 @endphp
                 <div class="kt-menu-item ps-2" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                     <a href="/finance/action-items" title="Track and resolve ledger posting issues">
                         <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                             <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                 <i class="ki-filled ki-information-2 text-lg"></i>
                             </span>
                             <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">
                                 Action Items
                             </span>
                             @if($openActionItems > 0)
                             <span class="kt-badge kt-badge-sm kt-badge-danger font-bold">
                                 {{ $openActionItems }}
                             </span>
                             @endif
                         </div>
                     </a>
                 </div>
                 <div class="kt-menu-item ps-2" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                     <a href="/requests/settings">
                         <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                             <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                 <i class="ki-filled ki-setting-2 text-lg">
                                 </i>
                             </span>
                             <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                 Request Settings
                             </span>
                         </div>
                     </a>
                 </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/users">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-users text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Users
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/riders">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-delivery text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Riders
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/roles">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-shield-tick text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Roles
                              </span>
                          </div>
                      </a>
                  </div>
                   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                       <a href="/logs">
                           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                               <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                   <i class="ki-filled ki-file-sheet text-lg">
                                   </i>
                               </span>
                               <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                   Error Logs
                                </span>
                            </div>
                        </a>
                    </div>
                    <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                        <a href="/order-status">
                            <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                    <i class="ki-filled ki-setting-2 text-lg">
                                    </i>
                                </span>
                                <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                    Order Status
                                </span>
                            </div>
                        </a>
                    </div>
                    <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                        <a href="/order-status/history">
                            <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                    <i class="ki-filled ki-time text-lg">
                                    </i>
                                </span>
                                <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                    Status History
                                </span>
                            </div>
                        </a>
                    </div>
                    @endif
                </div>{{-- /#nf-admin-items --}}

                    <!-- Logout Section -->
                    @if(auth()->check())
                    <div class="kt-menu-item pt-4 pb-2">
                        <div style="border-top: 1px solid #d1d5db; padding-top: 12px;">
                            <a href="#" onclick="event.preventDefault(); performLogout();">
                                <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-red-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                    <span class="kt-menu-icon items-start text-red-500 group-hover:text-red-600 w-[20px]">
                                        <i class="ki-filled ki-exit-up text-lg"></i>
                                    </span>
                                    <span class="kt-menu-title text-sm font-medium text-red-500 group-hover:text-red-600">
                                        Logout
                                    </span>
                                </div>
                            </a>
                        </div>
                    </div>
                    @endif
                 
               </div>
               <!-- End of Sidebar Menu -->
           </div>
       </div>
   </div>
   <!-- End of Sidebar -->
   
   @if(auth()->check())
   <!-- Logout Form (hidden) -->
   <form id="logout-form" action="/logout" method="GET" style="display: none;">
       @csrf
   </form>
   <script>
   function performLogout() {
       if (!confirm('Are you sure you want to logout?')) return;
       
       // Clear any cached data in localStorage
       try {
           // Remove order table column preferences and other cached settings
           const keysToKeep = []; // Add any keys that should survive logout
           const keysToRemove = [];
           for (let i = 0; i < localStorage.length; i++) {
               const key = localStorage.key(i);
               if (!keysToKeep.includes(key)) {
                   keysToRemove.push(key);
               }
           }
           keysToRemove.forEach(key => localStorage.removeItem(key));
       } catch(e) {
           console.warn('Could not clear localStorage:', e);
       }
       
       // Clear any session storage
       try {
           sessionStorage.clear();
       } catch(e) {
           console.warn('Could not clear sessionStorage:', e);
       }
       
       // Navigate to logout URL which will invalidate session and redirect to login
       window.location.href = '/logout';
   }
   </script>
   @endif

   {{-- NF: Phase 1 active-route tagger. Marks the best-matching sidebar link with .nf-active.
        Pure DOM tagging; no navigation, no fetch, no handlers touched. --}}
   <script>
   (function() {
       try {
           var currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
           var currentSource = new URLSearchParams(window.location.search).get('source');
           var links = document.querySelectorAll('#sidebar_menu a[href]');
           var best = null, bestLen = -1;
           links.forEach(function(a) {
               var href = a.getAttribute('href') || '';
               if (!href || href === '#' || href.charAt(0) !== '/') return;
               var url;
               try { url = new URL(href, window.location.origin); } catch (e) { return; }
               var linkPath = url.pathname.replace(/\/+$/, '') || '/';
               var linkSource = url.searchParams.get('source');
               var pathMatch = (currentPath === linkPath) ||
                               (linkPath !== '/' && currentPath.indexOf(linkPath + '/') === 0);
               if (!pathMatch) return;
               // Disambiguate /orders (Invoices, no source) vs /orders?source=shopify (Shopify).
               if ((linkSource || null) !== (currentSource || null)) return;
               if (linkPath.length > bestLen) { best = a; bestLen = linkPath.length; }
           });
           if (best) {
               var div = best.querySelector('.kt-menu-link');
               if (div) div.classList.add('nf-active');
           }
       } catch (e) { /* never break the sidebar */ }
   })();

   /* NF: Phase 2B — Administration collapsible. Default expanded; state remembered per-user in localStorage.
      Only attaches if the toggle button exists (non-rider users). Fails silent if localStorage is blocked. */
   (function() {
       try {
           var toggle = document.getElementById('nf-admin-toggle');
           var items = document.getElementById('nf-admin-items');
           var chevron = document.getElementById('nf-admin-chevron');
           if (!toggle || !items) return;
           var STORAGE_KEY = 'nfAdminCollapsed';
           function apply(collapsed) {
               items.style.display = collapsed ? 'none' : '';
               if (chevron) chevron.style.transform = collapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
               toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
           }
           var initial = false;
           try { initial = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}
           apply(initial);
           toggle.addEventListener('click', function() {
               var nowCollapsed = items.style.display !== 'none' ? true : false;
               apply(nowCollapsed);
               try { localStorage.setItem(STORAGE_KEY, nowCollapsed ? '1' : '0'); } catch (e) {}
           });
       } catch (e) { /* never break the sidebar */ }
   })();

   /* NF: Phase 2C — Sidebar quick-search. Pure DOM filter; no fetch, no state beyond the input. */
   (function() {
       try {
           var input = document.getElementById('nf-sidebar-search');
           var menu = document.getElementById('sidebar_menu');
           if (!input || !menu) return;

           // Collect top-level leaf items in document order; headings mark section boundaries.
           var children = Array.prototype.slice.call(menu.children);
           // Each entry is either {type:'heading', el}, {type:'leaf', el, title} or {type:'wrapper', el}.
           // For #nf-admin-items wrapper we descend into its direct children so they are filtered too.
           function classify(el) {
               if (!el || el.nodeType !== 1) return null;
               if (el.id === 'nf-admin-items') return { type: 'wrapper', el: el };
               if (el.classList && el.classList.contains('kt-menu-item')) {
                   if (el.querySelector(':scope > button#nf-admin-toggle')) {
                       return { type: 'heading', el: el };
                   }
                   if (el.querySelector(':scope > .kt-menu-heading') ||
                       (el.children.length === 1 && el.firstElementChild &&
                        el.firstElementChild.querySelector && el.firstElementChild.querySelector('.kt-menu-heading'))) {
                       return { type: 'heading', el: el };
                   }
                   var titleEl = el.querySelector('.kt-menu-title');
                   if (titleEl) return { type: 'leaf', el: el, title: (titleEl.textContent || '').toLowerCase() };
               }
               return null;
           }

           var sections = []; // flat list of classified nodes, preserving order
           children.forEach(function(c) {
               var info = classify(c);
               if (!info) return;
               if (info.type === 'wrapper') {
                   Array.prototype.slice.call(c.children).forEach(function(sub) {
                       var subInfo = classify(sub);
                       if (subInfo) sections.push(subInfo);
                   });
               } else {
                   sections.push(info);
               }
           });

           function filter(term) {
               term = (term || '').trim().toLowerCase();
               if (!term) {
                   sections.forEach(function(s) { s.el.style.display = ''; });
                   menu.classList.remove('nf-searching');
                   return;
               }
               menu.classList.add('nf-searching');
               // First pass: show/hide leaves by match
               var lastHeading = null;
               var headingHasMatch = false;
               function finalizeHeading() {
                   if (lastHeading) lastHeading.el.style.display = headingHasMatch ? '' : 'none';
               }
               sections.forEach(function(s) {
                   if (s.type === 'heading') {
                       finalizeHeading();
                       lastHeading = s;
                       headingHasMatch = false;
                       s.el.style.display = 'none'; // optimistic; revealed if a leaf matches below
                   } else if (s.type === 'leaf') {
                       var match = s.title.indexOf(term) !== -1;
                       s.el.style.display = match ? '' : 'none';
                       if (match) {
                           headingHasMatch = true;
                           if (lastHeading) lastHeading.el.style.display = '';
                       }
                   }
               });
               finalizeHeading();
           }

           input.addEventListener('input', function() { filter(input.value); });
           input.addEventListener('keydown', function(e) {
               if (e.key === 'Escape') { input.value = ''; filter(''); input.blur(); }
           });
       } catch (e) { /* never break the sidebar */ }
   })();
   </script>