   <!-- Sidebar -->
   <div class="kt-sidebar bg-gray-200 border-e border-gray-300 fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]" data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0" id="sidebar">
       <div class="kt-sidebar-header hidden lg:flex flex-col items-stretch relative px-3 lg:px-6 shrink-0 border-b border-gray-300 pb-3" id="sidebar_header">
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
      <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2 bg-gray-100" id="sidebar_content">
              <div class="kt-scrollable-y-hover grow shrink-0 flex ps-2 lg:ps-5 pe-1 lg:pe-3" data-kt-scrollable="true" data-kt-scrollable-dependencies="#sidebar_header" data-kt-scrollable-height="auto" data-kt-scrollable-offset="0px" data-kt-scrollable-wrappers="#sidebar_content" id="sidebar_scrollable">
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
                      if (auth()->check()) {
                          $userRole = \DB::table('t_sys_user_role as ur')
                              ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                              ->where('ur.user_id', auth()->id())
                              ->value('r.type');
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
                  
                  <!-- Requests Section -->
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
                                  Requests
                              </span>
                          </div>
                      </a>
                  </div>
                  @if($userRole !== 'rider')
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
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
                  @endif
                  
                  <!-- Administration Section -->
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-gray-400 ps-[10px] pe-[10px]">
                          Administration
                      </span>
                  </div>
                  
                  @if($userRole !== 'rider')
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
                 
               </div>
               <!-- End of Sidebar Menu -->
           </div>
       </div>
   </div>
   <!-- End of Sidebar -->