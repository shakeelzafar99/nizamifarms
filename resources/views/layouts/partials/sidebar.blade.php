   {{-- NF: Phase 1 sidebar visual polish. Pure CSS; no JS/ID/class names removed.
        Phase 2 (Jul-2026): sections regrouped + made collapsible (nfNavCollapsed),
        badge rollups on collapsed headers, auto-expand of the active section, and
        the quick-search now spans every (even collapsed) section. Every menu item
        keeps its exact href + gate — see UX-REVAMP plan Appendix C. --}}
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

       /* ---- Phase 2: collapsible sections ---- */
       #sidebar .nf-sec-toggle { appearance: none; }
       #sidebar .nf-sec-chev { transition: transform .18s ease; }
       #sidebar .nf-section.nf-collapsed .nf-sec-chev { transform: rotate(-90deg); }
       #sidebar .nf-section.nf-collapsed > .nf-section-body { display: none; }
       /* rollup pill on a collapsed header (populated by JS) */
       #sidebar .nf-sec-rollup {
           display: none; min-width: 18px; text-align: center;
           font-size: 10px; font-weight: 700; line-height: 1;
           padding: 2px 6px; border-radius: 999px;
           background: #EEF2F7; color: #475569; font-variant-numeric: tabular-nums;
       }
       #sidebar .nf-section.nf-collapsed .nf-sec-rollup.nf-has { display: inline-block; }
       #sidebar .nf-sec-rollup.nf-danger { background: #FEF2F2; color: #DC2626; }
       #sidebar .nf-sec-rollup.nf-warn   { background: #FFFBEB; color: #D97706; }
       /* While the quick-search is active, force every section body open so matches
          inside a collapsed section are still findable. */
       #sidebar_menu.nf-searching .nf-section > .nf-section-body { display: block !important; }
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
                  @php
                      // Computed here too (before the main role block below) so the
                      // "supervisor" / "supervisor 2" invoices-only logins also hide Dashboards.
                      $isInvoicesOnly = auth()->check() && \DB::table('t_sys_user_role as ur')
                          ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                          ->where('ur.user_id', auth()->id())
                          ->whereRaw('LOWER(r.urole_name) IN (?, ?)', ['supervisor', 'supervisor 2'])
                          ->exists();

                      // Jul-2026 — RESTRICTED WEB MENU mode (e.g. the "adnan"
                      // HQ-only role). If any of the user's roles carries a
                      // web_menu_* permission key, the sidebar shows ONLY the
                      // granted sections (plus Logout). Grant more sections by
                      // inserting rows into t_sys_role_permissions — see
                      // database/migrations/add_web_menu_restricted_role_jul2026.sql.
                      // Users with NO web_menu_* keys see the normal full menu.
                      $nfWebMenuKeys = [];
                      if (auth()->check()) {
                          try {
                              $nfWebMenuKeys = \DB::table('t_sys_role_permissions as rp')
                                  ->join('t_sys_user_role as ur2', 'ur2.role_id', '=', 'rp.role_id')
                                  ->where('ur2.user_id', auth()->id())
                                  ->where('rp.is_allowed', 1)
                                  ->where('rp.permission_key', 'like', 'web\_menu\_%')
                                  ->pluck('rp.permission_key')->unique()->values()->all();
                          } catch (\Throwable $e) { $nfWebMenuKeys = []; }
                      }
                      $nfRestrictedWeb = !empty($nfWebMenuKeys);
                      $nfWebCan = function ($key) use ($nfWebMenuKeys) {
                          return in_array('web_menu_' . $key, $nfWebMenuKeys, true);
                      };
                  @endphp
                  @if($nfRestrictedWeb)
                  {{-- ================= RESTRICTED WEB MENU ================= --}}
                  @if($nfWebCan('hq'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/hq">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-line-up text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">HQ · Executive</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($nfWebCan('invoices_analysis'))
                  {{-- Invoices — Analysis: read-only invoice explorer (NOT the operational
                       Orders page). Distinct key from web_menu_invoices (→ /orders). --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/invoices">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-document text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Invoices</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($nfWebCan('dashboards'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/dashboard">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-element-11 text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Dashboards</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($nfWebCan('invoices'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/orders">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-security-user text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Invoices</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($nfWebCan('customers'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/customers">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-profile-circle text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Customers</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  {{-- Campaigns in the restricted menu (Jul-2026). The full-menu
                       Campaigns entry lower down never renders for a restricted
                       role, so it needs its own row here. Grant with
                       web_menu_campaigns; the page itself is separately gated on
                       the view_campaigns / manage_campaigns permissions, so a
                       view-only owner can be given campaign-RUNNING rights
                       without unlocking writes anywhere else. --}}
                  @if($nfWebCan('campaigns'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/campaigns">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-notification-on text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Campaigns</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($nfWebCan('finance_hub'))
                  {{-- Ledger Hub (read-only for view-only owners; write buttons hidden via $canWrite). --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/finance/hub">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-bank text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Ledger Hub</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @if($nfWebCan('finance'))
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/finance/employee">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-dollar text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">NF Ledger</span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/finance/expenses">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-wallet text-lg"></i></span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Expense Management</span>
                          </div>
                      </a>
                  </div>
                  @endif
                  @else
                  {{-- ================= NORMAL FULL MENU ================= --}}
                  @if(!$isInvoicesOnly)
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
                  @endif{{-- /Dashboards (hidden for the invoices-only role) --}}

                  @if(!$isInvoicesOnly)
                  {{-- HQ Executive dashboard (Jul-2026). Same audience as Dashboards. --}}
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/hq">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-chart-line-up text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  HQ · Executive
                              </span>
                          </div>
                      </a>
                  </div>
                  @endif{{-- /HQ Executive --}}

                  <!-- Get User Role -->
                  @php
                      $userRole = null;
                      $isTaimurRole = false;
                      $isInvoicesOnly = false;
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
                          // Jun-2026 — the "supervisor" and "supervisor 2" roles are invoices-only
                          // WEB logins: show ONLY the Invoices menu item below and hide the rest of
                          // the sidebar. Keyed by exact role names so no other role is affected.
                          // (Web sidebar only — the mobile app does not use this file.)
                          $isInvoicesOnly = \DB::table('t_sys_user_role as ur')
                              ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                              ->where('ur.user_id', auth()->id())
                              ->whereRaw('LOWER(r.urole_name) IN (?, ?)', ['supervisor', 'supervisor 2'])
                              ->exists();
                      }
                  @endphp

                  @if($isInvoicesOnly)
                  {{-- supervisor / supervisor 2 = invoices-only web logins: show just Orders ▸ Invoices,
                       then fall through to the Logout section (outside this wrapper). --}}
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 ps-[10px] pe-[10px]">
                          Orders
                      </span>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/orders">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                              <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]">
                                  <i class="ki-filled ki-security-user text-lg"></i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">
                                  Invoices
                              </span>
                          </div>
                      </a>
                  </div>
                  @else
                  {{-- ============================================================
                       NF Phase 2 — regrouped full menu (11 collapsible sections).
                       Every item keeps its exact href + gate (plan Appendix C).
                       Section wrapper pattern:
                         <div class="nf-section" data-nf-sec="ID" data-nf-default="open|collapsed">
                            <div class="kt-menu-item ..."><button class="nf-sec-toggle">…heading…</button></div>
                            <div class="nf-section-body"> …items… </div>
                         </div>
                       ============================================================ --}}

                  {{-- ===== B · Orders & Dispatch ===== --}}
                  <div class="nf-section" data-nf-sec="orders" data-nf-default="open">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Orders &amp; Dispatch</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/orders">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-security-user text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Invoices</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/orders?source=shopify">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-shop text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Shopify</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/orders/open-quantities">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-line text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Open Order Quantities</span>
                                  </div>
                              </a>
                          </div>
                          @if($userRole !== 'rider')
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('riders-map') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-map text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Riders Map</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                          @if(auth()->user()->hasMobilePermission('view_delivery_regions'))
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/regions">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-geolocation text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Delivery Regions</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                      </div>
                  </div>

                  {{-- ===== C · Approvals & Requests ===== --}}
                  <div class="nf-section" data-nf-sec="approvals" data-nf-default="open">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Approvals &amp; Requests</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          @if($userRole !== 'rider')
                          @php
                              // Calculate pending approvals count for badge (simplified)
                              $pendingLedgerCount = \App\Models\FIN\LedgerModel::where('approval_status', 'pending')->count();
                              $user = auth()->user();
                              $hasLevel1Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
                              $hasLevel2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
                              $pendingRequestsCount = 0;
                              if ($hasLevel1Rights || $hasLevel2Rights) {
                                  $pendingRequestsCount = \App\Models\Request\RequestModel::where('status', 'pending')->count();
                              }
                              $totalPendingApprovals = $pendingLedgerCount + $pendingRequestsCount;
                          @endphp
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/approvals" title="Approve requests, invoices, payments & transfers">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-check-circle text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Approvals Dashboard</span>
                                      @if($totalPendingApprovals > 0)
                                      <span class="kt-badge kt-badge-sm kt-badge-danger font-bold nf-badge nf-badge-danger">{{ $totalPendingApprovals }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          @php
                              $pendingOnlineCount = \App\Models\FIN\LedgerModel::whereIn('approval_status', ['pending', 'pending_l1', 'pending_l2'])
                                  ->whereNull('request_id')
                                  ->where('mode', 'online')
                                  ->count();
                          @endphp
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/approvals/online" title="Approve online payment invoices">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-credit-cart text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Online Approvals</span>
                                      @if($pendingOnlineCount > 0)
                                      <span class="kt-badge kt-badge-sm kt-badge-primary font-bold nf-badge">{{ $pendingOnlineCount }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          @endif
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/requests">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-file-sheet text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">My Requests</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>

                  {{-- ===== D · Customers & Messaging ===== --}}
                  <div class="nf-section" data-nf-sec="crm" data-nf-default="open">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Customers &amp; Messaging</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/customers">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-people text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Customers</span>
                                  </div>
                              </a>
                          </div>
                          @if(auth()->user()->hasMobilePermission('view_whatsapp_messages') || auth()->user()->hasMobilePermission('view_whatsapp_messages_limited'))
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/messages">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-messages text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Messages</span>
                                      <span id="wa-unread-badge" class="hidden bg-green-500 text-white text-xs font-bold rounded-full min-w-[20px] h-5 flex items-center justify-center px-1.5 nf-badge"></span>
                                  </div>
                              </a>
                          </div>
                          @endif
                          @if(auth()->user()->hasMobilePermission('use_ai_assistant'))
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/assistant-view">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-abstract-26 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">NF Assistant</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                          @if($isTaimurRole || auth()->user()->hasMobilePermission('view_campaigns'))
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/campaigns">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-notification-on text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Campaigns</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                      </div>
                  </div>

                  {{-- ===== E · Finance ===== --}}
                  <div class="nf-section" data-nf-sec="finance" data-nf-default="open">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Finance</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          {{-- Ledger Hub (beta): unified finance workspace. Parallel-run — the
                               individual pages below stay live until it is phased in. --}}
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/hub" title="New unified finance workspace (beta)">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-element-11 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Ledger Hub</span>
                                      <span class="kt-badge kt-badge-sm font-bold nf-badge" style="background:#E1F0E9;color:#0E7A52;">beta</span>
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
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-calendar-tick text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Daily Closing</span>
                                      @if($pendingInvoiceSettlements > 0)
                                      <span class="kt-badge kt-badge-sm kt-badge-warning font-bold nf-badge nf-badge-warn">{{ $pendingInvoiceSettlements }}</span>
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
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-line-up-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Expense Management</span>
                                      @if($pendingSettlements > 0)
                                      <span class="kt-badge kt-badge-sm kt-badge-warning font-bold nf-badge nf-badge-warn">{{ $pendingSettlements }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          {{-- Jul-2026 — NF Ledger, Overall Ledger and Banks were RETIRED FROM THIS MENU
                               (not from the app): the Ledger Hub now covers all three, and each old page
                               is still one click away from the tab that mirrors it —
                                 NF Ledger      → Hub ▸ Accounts ▸ "Old NF ledger ↗"  (/finance/employee)
                                 Overall Ledger → Hub ▸ Overview ▸ "Old ledger ↗"     (/finance/ledger)
                                 Banks          → Hub ▸ Banks ▸ "Manage banks ↗"      (/finance/bank-balances)
                               Routes, permissions and every deep link into them are untouched, so nothing
                               that already points at these pages breaks. To bring one back, un-comment it.

                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/employee">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-dollar text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">NF Ledger</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/ledger">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-book text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Overall Ledger</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/bank-balances" title="Per-bank balances over the single Online ledger account">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-bank text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Banks</span>
                                  </div>
                              </a>
                          </div>
                          --}}
                          {{-- Vendors now opens the Hub's Vendors tab. The OLD page keeps its route and is
                               reached from there via "Old vendors ↗" — so this must NOT become a redirect
                               on /finance/vendors itself, or that escape hatch would loop back here. --}}
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/hub/vendors">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-shop text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Vendors</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/reports">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-simple text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Reports</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>

                  {{-- ===== F · Team ===== --}}
                  <div class="nf-section" data-nf-sec="team" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Team</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          @if($userRole === 'rider')
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/attendance/mine">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-time text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">My Attendance</span>
                                  </div>
                              </a>
                          </div>
                          @else
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/attendance">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-time text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Attendance</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/shift-planner">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-calendar text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Shift Planner</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                          @if($userRole !== 'rider' && auth()->user()->hasPermission('manage_payroll'))
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/hr/payroll">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-dollar text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Payroll</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                      </div>
                  </div>

                  {{-- ===== G · Catalog ===== --}}
                  <div class="nf-section" data-nf-sec="catalog" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Catalog</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/products">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-package text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Products</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/coupons">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-discount text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Coupons</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/shipping">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-delivery text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Shipping</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>

                  {{-- ===== G2 · 🌙 Overnight Storage (mobile-permission gated, same pattern as Khaas) ===== --}}
                  @php
                      $hasOvernightAccess = false;
                      $overnightStoredCount = 0;
                      if (auth()->check()) {
                          $overnightUser = auth()->user();
                          if (!$overnightUser->relationLoaded('roles')) {
                              $overnightUser->load(['roles.mobilePermissions']);
                          }
                          $hasOvernightAccess = $overnightUser->hasMobilePermission('access_overnight_storage');
                          if ($hasOvernightAccess) {
                              $overnightStoredCount = \App\Models\CRM\OvernightItemModel::where('status', 'stored')->count();
                          }
                      }
                  @endphp
                  @if($hasOvernightAccess)
                  <div class="nf-section" data-nf-sec="overnight" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-sky-500 group-hover:text-sky-700 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-sky-600 group-hover:text-sky-700 transition-colors flex-1">🌙 Overnight</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('overnight.index') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-sky-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-sky-600 group-hover:text-sky-700 w-[20px]"><i class="ki-filled ki-moon text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-sky-700 flex-1">Chiller / Freezer</span>
                                      @if($overnightStoredCount > 0)
                                      <span class="kt-badge kt-badge-sm font-bold" style="background-color:#e0f2fe; color:#0369a1; border-radius:9999px; padding:1px 8px; font-size:11px;">{{ $overnightStoredCount }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>
                  @endif

                  {{-- ===== G3 · 🐔 La Carne (mobile-permission gated, same pattern as Overnight) ===== --}}
                  @php
                      $hasLaCarneAccess = false;
                      if (auth()->check()) {
                          $laCarneUser = auth()->user();
                          if (!$laCarneUser->relationLoaded('roles')) {
                              $laCarneUser->load(['roles.mobilePermissions']);
                          }
                          $hasLaCarneAccess = $laCarneUser->hasMobilePermission('access_lacarne');
                      }
                  @endphp
                  @if($hasLaCarneAccess)
                  <div class="nf-section" data-nf-sec="lacarne" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-amber-500 group-hover:text-amber-700 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-amber-600 group-hover:text-amber-700 transition-colors flex-1">🐔 La Carne</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('lacarne.index') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-basket text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">Chicken Board</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>
                  @endif

                  {{-- ===== H · 🌿 Khaas (permission + business-unit gated) ===== --}}
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
                  <div class="nf-section" data-nf-sec="khaas" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-amber-500 group-hover:text-amber-700 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-amber-600 group-hover:text-amber-700 transition-colors flex-1">🌿 {{ $khaasBusinessUnit->name }}</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('khaas.dashboard') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-home-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">{{ $khaasBusinessUnit->name }} Dashboard</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('khaas.products') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-package text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">Products & Inventory</span>
                                  </div>
                              </a>
                          </div>
                          {{-- 🏍️ Bikes — running cost per rider. Deep-links straight to the
                               Bikes tab, so someone whose only access is this (a Khaas-mode
                               user) never lands on the live board. Gated by its own key so it
                               can be granted without any rider-ops or finance access. --}}
                          @if(auth()->user() && auth()->user()->hasPermission('view_bike_costs'))
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('riders-map') }}#bikes">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-scooter text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">Bikes · fuel & running cost</span>
                                  </div>
                              </a>
                          </div>
                          @endif
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('khaas.meat-order') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-delivery text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">Meat Order</span>
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
                                      <span class="kt-badge kt-badge-sm font-bold nf-badge nf-badge-danger" style="background-color: #EF4444; color: white; border-radius: 999px; padding: 2px 6px; font-size: 10px;">{{ $khaasPendingReceive }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('khaas.inventory') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-box text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">Planning</span>
                                      @php
                                          $khaasPendingDemand = $khaasBusinessUnit
                                              ? \DB::table('t_crm_khaas_production_demand')
                                                  ->where('business_unit_id', $khaasBusinessUnit->id)
                                                  ->where('status', 'submitted')
                                                  ->count()
                                              : 0;
                                      @endphp
                                      @if($khaasPendingDemand > 0)
                                      <span class="kt-badge kt-badge-sm font-bold nf-badge nf-badge-warn" style="background-color: #F59E0B; color: white; border-radius: 999px; padding: 2px 6px; font-size: 10px;">{{ $khaasPendingDemand }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('khaas.operations') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-element-11 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700 flex-1">Operations</span>
                                      @if($khaasPendingTransfers > 0)
                                      <span class="kt-badge kt-badge-sm font-bold nf-badge nf-badge-warn" style="background-color: #f59e0b; color: white; border-radius: 999px; padding: 2px 6px; font-size: 10px;">{{ $khaasPendingTransfers }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="{{ route('khaas.sales-report') }}">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-amber-50 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-amber-600 group-hover:text-amber-700 w-[20px]"><i class="ki-filled ki-chart-line-up-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-amber-700">Sales Report</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>
                  @endif

                  {{-- ===== I · Qurbani (env + non-rider gated) ===== --}}
                  @php
                      $qurbaniEnabled = \App\Models\FIN\ConfigModel::get('qurbani_mode_enabled_web', \App\Models\FIN\ConfigModel::get('qurbani_mode_enabled', '1')) === '1';
                  @endphp
                  @if($qurbaniEnabled && $userRole !== 'rider')
                  <div class="nf-section" data-nf-sec="qurbani" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Qurbani</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/qurbani/orders">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-parcel text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Qurbani Orders</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/qurbani/riders">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-delivery text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Qurbani Riders</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/qurbani/invoices">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-document text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Qurbani Invoices</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/qurbani/performance">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-line-up text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Qurbani Performance</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/qurbani-settings">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-setting-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Qurbani Settings</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>
                  @endif

                  {{-- ===== J · Analytics Sandbox (env gated) ===== --}}
                  @if(filter_var(env('ANALYTICS_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN))
                  <div class="nf-section" data-nf-sec="sandbox" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-500 group-hover:text-gray-700 transition-colors flex-1">Analytics (Sandbox)</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/sandbox">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-line-up text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Sandbox dashboards</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>
                  @endif

                  {{-- ===== K · Administration (non-rider) ===== --}}
                  @if($userRole !== 'rider')
                  <div class="nf-section" data-nf-sec="admin" data-nf-default="collapsed">
                      <div class="kt-menu-item pt-2.25 pb-px">
                          <button type="button" class="nf-sec-toggle flex items-center gap-1.5 w-full text-left ps-[10px] pe-[10px] py-0 bg-transparent border-0 cursor-pointer group" aria-expanded="true">
                              <i class="ki-filled ki-down nf-sec-chev text-[10px] text-gray-400 group-hover:text-gray-600 transition-transform"></i>
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 group-hover:text-gray-600 transition-colors flex-1">Administration</span>
                              <span class="nf-sec-rollup"></span>
                          </button>
                      </div>
                      <div class="nf-section-body">
                          {{-- People & Access --}}
                          <div class="kt-menu-item pt-2 pb-px">
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">People &amp; Access</span>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/users">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-users text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Users</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/riders">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-delivery text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Riders</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/roles">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-shield-tick text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Roles</span>
                                  </div>
                              </a>
                          </div>
                          {{-- Workflow Setup --}}
                          <div class="kt-menu-item pt-2 pb-px">
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">Workflow Setup</span>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/requests/settings">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-setting-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Requests Setup</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/order-status">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-setting-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Order Status</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/order-status/history">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-time text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Status History</span>
                                  </div>
                              </a>
                          </div>
                          {{-- Finance Setup --}}
                          <div class="kt-menu-item pt-2 pb-px">
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">Finance Setup</span>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/accounts">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-category text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Accounts</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/business-units">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-briefcase text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Business Units</span>
                                  </div>
                              </a>
                          </div>
                          @php
                              $openActionItems = \App\Models\FIN\ActionItemModel::where('status', 'open')->count();
                          @endphp
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/finance/action-items" title="Track and resolve ledger posting issues">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-information-2 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900 flex-1">Action Items</span>
                                      @if($openActionItems > 0)
                                      <span class="kt-badge kt-badge-sm kt-badge-danger font-bold nf-badge nf-badge-danger">{{ $openActionItems }}</span>
                                      @endif
                                  </div>
                              </a>
                          </div>
                          {{-- System --}}
                          <div class="kt-menu-item pt-2 pb-px">
                              <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">System</span>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/admin/operations">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-setting-3 text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Operations</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/logs">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-file-sheet text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Error Logs</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/customer-app-stats" title="Customer-app API traffic — requests/day, busiest minute, per-endpoint, errors">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-chart-simple text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Customer App Stats</span>
                                  </div>
                              </a>
                          </div>
                          <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                              <a href="/audit-log" title="Audit trail — who changed what on orders, ledger and payments">
                                  <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                      <span class="kt-menu-icon items-start text-gray-600 group-hover:text-gray-900 w-[20px]"><i class="ki-filled ki-document text-lg"></i></span>
                                      <span class="kt-menu-title text-sm font-medium text-gray-900 group-hover:text-gray-900">Audit Trail</span>
                                  </div>
                              </a>
                          </div>
                      </div>
                  </div>
                  @endif{{-- /Administration (non-rider) --}}

                  @endif{{-- /$isInvoicesOnly: end of the full menu --}}
                  @endif{{-- /$nfRestrictedWeb: restricted vs normal menu --}}

                    {{-- NF Phase 5 — "Customize menu": a per-user, subtractive display
                         preference (hide items you never use). Only for the normal menu
                         (restricted web_menu roles already have a curated menu) and only
                         once t_sys_user_setting exists, so the feature is invisible until
                         the SQL script has been run. --}}
                    @php
                        $nfCustomizeReady = false;
                        $nfHiddenSidebar = [];
                        if (auth()->check() && empty($nfRestrictedWeb)) {
                            try {
                                if (\Illuminate\Support\Facades\Schema::hasTable('t_sys_user_setting')) {
                                    $nfCustomizeReady = true;
                                    $nfRaw = \DB::table('t_sys_user_setting')
                                        ->where('user_id', auth()->id())
                                        ->where('setting_key', 'sidebar_hidden')
                                        ->value('setting_value');
                                    if ($nfRaw) {
                                        $nfDec = json_decode($nfRaw, true);
                                        if (is_array($nfDec)) $nfHiddenSidebar = array_values(array_filter($nfDec, 'is_string'));
                                    }
                                }
                            } catch (\Throwable $e) { $nfCustomizeReady = false; }
                        }
                    @endphp
                    @if($nfCustomizeReady)
                    <div class="kt-menu-item pt-2">
                        <a href="#" onclick="event.preventDefault(); nfOpenCustomize();" title="Choose which menu items you see — saved to your account">
                            <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] hover:bg-gray-200 rounded-md transition-colors duration-200 group" tabindex="0">
                                <span class="kt-menu-icon items-start text-gray-500 group-hover:text-gray-800 w-[20px]"><i class="ki-filled ki-setting-2 text-lg"></i></span>
                                <span class="kt-menu-title text-sm font-medium text-gray-600 group-hover:text-gray-900 flex-1">Customize menu</span>
                                <span id="nf-hidden-count" style="display:none;font-size:10px;font-weight:700;color:#6b7280;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:2px 7px;"></span>
                            </div>
                        </a>
                    </div>
                    @endif

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
        Pure DOM tagging; no navigation, no fetch, no handlers touched. Runs BEFORE the
        section-collapse script below so auto-expand can find the active section. --}}
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

   /* NF: Phase 2 — generalized section collapse + badge rollup + auto-expand-active.
      State persisted per-user in localStorage 'nfNavCollapsed' as {sectionId: true|false}.
      First visit uses each section's data-nf-default. Fails silent if storage is blocked. */
   (function() {
       try {
           var menu = document.getElementById('sidebar_menu');
           if (!menu) return;
           var sections = Array.prototype.slice.call(menu.querySelectorAll('.nf-section'));
           if (!sections.length) return;
           var KEY = 'nfNavCollapsed';
           var state = {};
           try { state = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { state = {}; }
           function persist() { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }

           function rollup(sec) {
               var span = sec.querySelector(':scope > .kt-menu-item .nf-sec-rollup');
               if (!span) return;
               var total = 0, sev = '';
               sec.querySelectorAll('.nf-section-body .nf-badge').forEach(function(b) {
                   if (b.classList.contains('hidden')) return;
                   var n = parseInt((b.textContent || '').trim(), 10);
                   if (isNaN(n)) return;
                   total += n;
                   if (b.classList.contains('nf-badge-danger')) sev = 'nf-danger';
                   else if (b.classList.contains('nf-badge-warn') && sev !== 'nf-danger') sev = 'nf-warn';
               });
               span.className = 'nf-sec-rollup' + (sev ? ' ' + sev : '');
               if (total > 0) { span.textContent = String(total); span.classList.add('nf-has'); }
               else { span.textContent = ''; }
           }

           function apply(sec) {
               var id = sec.getAttribute('data-nf-sec');
               var collapsed = state.hasOwnProperty(id) ? !!state[id]
                             : (sec.getAttribute('data-nf-default') === 'collapsed');
               sec.classList.toggle('nf-collapsed', collapsed);
               var btn = sec.querySelector('.nf-sec-toggle');
               if (btn) btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
               rollup(sec);
           }

           sections.forEach(function(sec) {
               apply(sec);
               var btn = sec.querySelector('.nf-sec-toggle');
               if (btn) btn.addEventListener('click', function() {
                   var id = sec.getAttribute('data-nf-sec');
                   state[id] = !sec.classList.contains('nf-collapsed');
                   persist();
                   apply(sec);
               });
           });

           // Auto-expand the section containing the active item (this render only; not persisted).
           var active = menu.querySelector('.kt-menu-link.nf-active');
           if (active) {
               var sec = active.closest('.nf-section');
               if (sec) {
                   sec.classList.remove('nf-collapsed');
                   var b = sec.querySelector('.nf-sec-toggle');
                   if (b) b.setAttribute('aria-expanded', 'true');
                   rollup(sec);
               }
           }
       } catch (e) { /* never break the sidebar */ }
   })();

   /* NF: Phase 2C — sidebar quick-search. Filters .kt-menu-title leaves across every
      section (collapsed ones are force-opened while searching via the .nf-searching CSS). */
   (function() {
       try {
           var input = document.getElementById('nf-sidebar-search');
           var menu = document.getElementById('sidebar_menu');
           if (!input || !menu) return;
           var sections = Array.prototype.slice.call(menu.querySelectorAll('.nf-section'));

           function leavesOf(root, sectionScoped) {
               return Array.prototype.slice.call(root.querySelectorAll('.kt-menu-item')).filter(function(el) {
                   if (!el.querySelector('.kt-menu-title')) return false;      // must be a link row
                   if (el.querySelector('.nf-sec-toggle')) return false;       // not a section header
                   if (el.querySelector(':scope > .kt-menu-heading')) return false; // not a sub-heading
                   if (sectionScoped) return true;
                   return !el.closest('.nf-section');                          // top-level pins only
               });
           }
           var pins = leavesOf(menu, false);

           function filter(term) {
               term = (term || '').trim().toLowerCase();
               if (!term) {
                   menu.classList.remove('nf-searching');
                   sections.forEach(function(sec) {
                       sec.style.display = '';
                       leavesOf(sec, true).forEach(function(l) { l.style.display = ''; });
                   });
                   pins.forEach(function(l) { l.style.display = ''; });
                   return;
               }
               menu.classList.add('nf-searching');
               pins.forEach(function(l) {
                   var t = (l.querySelector('.kt-menu-title').textContent || '').toLowerCase();
                   l.style.display = t.indexOf(term) !== -1 ? '' : 'none';
               });
               sections.forEach(function(sec) {
                   var any = false;
                   leavesOf(sec, true).forEach(function(l) {
                       var t = (l.querySelector('.kt-menu-title').textContent || '').toLowerCase();
                       var m = t.indexOf(term) !== -1;
                       l.style.display = m ? '' : 'none';
                       if (m) any = true;
                   });
                   sec.style.display = any ? '' : 'none';
               });
           }
           input.addEventListener('input', function() { filter(input.value); });
           input.addEventListener('keydown', function(e) {
               if (e.key === 'Escape') { input.value = ''; filter(''); input.blur(); }
           });
       } catch (e) { /* never break the sidebar */ }
   })();
   </script>

@if(!empty($nfCustomizeReady))
   {{-- NF Phase 5 — "Customize menu" modal + per-user hide logic. Additive: hiding is
        a client-side display preference applied on top of role gates (never grants),
        persisted per-user via /me/settings/sidebar. Item identity = slug from href. --}}
   <style>
       #sidebar .nf-item-hidden { display: none !important; }
       #sidebar .nf-section.nf-section-empty { display: none !important; }
       #nf-cust-row { }
       .nf-cust-row { display: flex; align-items: center; gap: 12px; padding: 8px 8px; border-radius: 9px; }
       .nf-cust-row:hover { background: #f9fafb; }
       .nf-cust-row .lbl { flex: 1; min-width: 0; font-size: 13.5px; font-weight: 550; color: #111827; }
       .nf-cust-row.locked .lbl { color: #9ca3af; }
       .nf-cust-lock { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #9ca3af; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 20px; padding: 2px 7px; }
       .nf-cust-grp { font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #9ca3af; padding: 12px 8px 3px; }
       .nf-sw { position: relative; width: 38px; height: 22px; flex: none; cursor: pointer; }
       .nf-sw input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
       .nf-sw .tk { position: absolute; inset: 0; background: #cbd5e1; border-radius: 20px; transition: .16s; }
       .nf-sw .th { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: .16s; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
       .nf-sw input:checked + .tk { background: #2563eb; }
       .nf-sw input:checked ~ .th { transform: translateX(16px); }
   </style>

   <div id="nf-cust-overlay" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.5);" onclick="if(event.target===this)nfCloseCustomize()">
       <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:520px;max-width:94vw;max-height:88vh;overflow:hidden;background:#fff;border-radius:14px;box-shadow:0 25px 50px -12px rgba(0,0,0,.35);display:flex;flex-direction:column;">
           <div style="padding:16px 20px;border-bottom:1px solid #eef1f5;display:flex;align-items:center;gap:12px;">
               <div style="width:38px;height:38px;border-radius:10px;background:#f5f3ff;color:#7c3aed;display:grid;place-items:center;font-size:16px;">☰</div>
               <div style="flex:1;">
                   <div style="font-size:16px;font-weight:700;color:#111827;">Customize my menu</div>
                   <div style="font-size:12px;color:#6b7280;">Hide items you never use. Only changes YOUR menu — saved to your account.</div>
               </div>
               <button type="button" onclick="nfCloseCustomize()" style="background:none;border:0;font-size:22px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
           </div>
           <div style="padding:10px 20px;border-bottom:1px solid #f1f3f6;">
               <input id="nf-cust-search" type="text" placeholder="Filter items…" autocomplete="off" style="width:100%;border:1px solid #e5e7eb;border-radius:9px;padding:8px 11px;font-size:13px;outline:none;background:#f9fafb;color:#111827;">
           </div>
           <div id="nf-cust-body" style="padding:6px 16px 14px;overflow-y:auto;flex:1;"></div>
           <div style="padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;">
               <span id="nf-cust-count" style="font-size:12.5px;color:#4b5563;font-weight:650;"></span>
               <button type="button" onclick="nfResetCustomize()" style="margin-left:auto;background:#fff;border:1px solid #d1d5db;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:600;color:#374151;cursor:pointer;">Reset to default</button>
               <button type="button" id="nf-cust-save" onclick="nfSaveCustomize()" style="background:#2563eb;border:0;color:#fff;border-radius:9px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;">Save</button>
           </div>
       </div>
   </div>

   <script>
   (function () {
       "use strict";
       // Nothing is locked from hiding: Dashboard + HQ are hideable by owner ruling
       // (Jul-2026). The Customize gear and Logout both use href="#", so collect()
       // /nfApplyHidden() skip them — they can never be hidden and remain the escape
       // hatch even if every navigable item is hidden. Kept as an array so the
       // lock mechanism stays available if an item ever needs pinning again.
       var NONHIDE = [];
       var nfHidden = new Set(@json($nfHiddenSidebar ?? []));   // currently-applied (saved) set
       var nfWork = null;                                       // working copy while the modal is open

       function slugOf(href) {
           try {
               var u = new URL(href, location.origin);
               var s = (u.pathname + u.search).replace(/^\/+/, '').replace(/[\/?=&]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase();
               return s || 'root';
           } catch (e) { return (href || '').replace(/[^a-z0-9]+/gi, '-').toLowerCase(); }
       }

       function collect() {
           var menu = document.getElementById('sidebar_menu');
           var seen = {}, items = [];
           if (!menu) return items;
           Array.prototype.forEach.call(menu.querySelectorAll('a[href]'), function (a) {
               var href = a.getAttribute('href');
               if (!href || href === '#') return;
               var title = a.querySelector('.kt-menu-title');
               if (!title) return;
               var slug = slugOf(href);
               if (seen[slug]) return; seen[slug] = 1;
               var sec = a.closest('.nf-section');
               var secName = 'Pinned';
               if (sec) { var h = sec.querySelector('.kt-menu-heading'); if (h) secName = h.textContent.trim(); }
               items.push({ slug: slug, label: title.textContent.trim(), section: secName, locked: NONHIDE.indexOf(slug) > -1 });
           });
           return items;
       }

       // Apply the SAVED set to the live sidebar (hide items + empty sections + badge).
       window.nfApplyHidden = function () {
           var menu = document.getElementById('sidebar_menu');
           if (!menu) return;
           var count = 0;
           Array.prototype.forEach.call(menu.querySelectorAll('a[href]'), function (a) {
               var href = a.getAttribute('href');
               if (!href || href === '#') return;
               var slug = slugOf(href);
               var item = a.closest('.kt-menu-item');
               if (!item) return;
               var hide = NONHIDE.indexOf(slug) === -1 && nfHidden.has(slug);
               item.classList.toggle('nf-item-hidden', hide);
               if (hide) count++;
           });
           Array.prototype.forEach.call(menu.querySelectorAll('.nf-section'), function (sec) {
               var leaves = Array.prototype.filter.call(sec.querySelectorAll('.nf-section-body a[href]'), function (a) {
                   var h = a.getAttribute('href'); return h && h !== '#' && a.querySelector('.kt-menu-title');
               });
               var visible = leaves.filter(function (a) { return !a.closest('.kt-menu-item').classList.contains('nf-item-hidden'); });
               sec.classList.toggle('nf-section-empty', leaves.length > 0 && visible.length === 0);
           });
           var badge = document.getElementById('nf-hidden-count');
           if (badge) { if (count > 0) { badge.textContent = count + ' hidden'; badge.style.display = ''; } else { badge.style.display = 'none'; } }
       };

       function render(filter) {
           var body = document.getElementById('nf-cust-body');
           var items = collect();
           var groups = {}, order = [];
           items.forEach(function (it) { if (!groups[it.section]) { groups[it.section] = []; order.push(it.section); } groups[it.section].push(it); });
           var term = (filter || '').trim().toLowerCase();
           var html = '';
           order.forEach(function (sec) {
               var rows = groups[sec].filter(function (it) { return !term || it.label.toLowerCase().indexOf(term) !== -1; });
               if (!rows.length) return;
               html += '<div class="nf-cust-grp">' + sec + '</div>';
               rows.forEach(function (it) {
                   if (it.locked) {
                       html += '<div class="nf-cust-row locked"><span class="lbl">' + it.label + '</span><span class="nf-cust-lock">🔒 always shown</span></div>';
                   } else {
                       var on = !nfWork.has(it.slug);
                       html += '<div class="nf-cust-row"><span class="lbl">' + it.label + '</span>' +
                           '<label class="nf-sw"><input type="checkbox" data-slug="' + it.slug + '" ' + (on ? 'checked' : '') + '><span class="tk"></span><span class="th"></span></label></div>';
                   }
               });
           });
           body.innerHTML = html || '<div style="padding:16px;color:#9ca3af;font-size:13px;">No items match.</div>';
           body.querySelectorAll('input[data-slug]').forEach(function (cb) {
               cb.addEventListener('change', function () {
                   if (cb.checked) nfWork.delete(cb.getAttribute('data-slug')); else nfWork.add(cb.getAttribute('data-slug'));
                   updateCount();
               });
           });
           updateCount();
       }

       function updateCount() {
           var el = document.getElementById('nf-cust-count');
           if (el) el.textContent = nfWork.size + (nfWork.size === 1 ? ' item hidden' : ' items hidden');
       }

       window.nfOpenCustomize = function () {
           nfWork = new Set(nfHidden);
           render('');
           var s = document.getElementById('nf-cust-search'); if (s) s.value = '';
           document.getElementById('nf-cust-overlay').style.display = 'block';
       };
       window.nfCloseCustomize = function () { document.getElementById('nf-cust-overlay').style.display = 'none'; };
       window.nfResetCustomize = function () { nfWork.clear(); render(document.getElementById('nf-cust-search').value); };

       window.nfSaveCustomize = function () {
           var btn = document.getElementById('nf-cust-save');
           btn.disabled = true; btn.textContent = 'Saving…';
           var tokenEl = document.querySelector('meta[name="csrf-token"]');
           var token = tokenEl ? tokenEl.getAttribute('content') : '{{ csrf_token() }}';
           fetch('{{ route('me.settings.sidebar.save') }}', {
               method: 'POST',
               headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
               body: JSON.stringify({ hidden: Array.from(nfWork) })
           })
           .then(function (r) { return r.json().catch(function () { return {}; }); })
           .then(function (d) {
               if (d && d.success) {
                   nfHidden = new Set(d.hidden || Array.from(nfWork));
                   window.nfApplyHidden();
                   window.nfCloseCustomize();
               } else {
                   alert((d && d.message) || 'Could not save menu preferences.');
               }
           })
           .catch(function () { alert('Network error saving menu preferences.'); })
           .finally(function () { btn.disabled = false; btn.textContent = 'Save'; });
       };

       var search = document.getElementById('nf-cust-search');
       if (search) search.addEventListener('input', function () { render(this.value); });

       // Apply the saved hides now (synchronous → before first paint, no flash).
       window.nfApplyHidden();
   })();
   </script>
@endif
