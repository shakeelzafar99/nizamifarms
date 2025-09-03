   <!-- Sidebar -->
   <div class="kt-sidebar bg-background border-e border-e-border fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]" data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0" id="sidebar">
       <div class="kt-sidebar-header hidden lg:flex items-center relative justify-between px-3 lg:px-6 shrink-0" id="sidebar_header">
           <a class="dark:hidden font-medium uppercase" href="/dashboard">
               Nizami Farms
           </a>
           <button class="kt-btn kt-btn-outline kt-btn-icon size-[30px] absolute start-full top-2/4 -translate-x-2/4 -translate-y-2/4 rtl:translate-x-2/4" data-kt-toggle="body" data-kt-toggle-class="kt-sidebar-collapse" id="sidebar_toggle">
               <i class="ki-filled ki-black-left-line kt-toggle-active:rotate-180 transition-all duration-300 rtl:translate rtl:rotate-180 rtl:kt-toggle-active:rotate-0">
               </i>
           </button>
       </div>
       <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2" id="sidebar_content">
           <div class="kt-scrollable-y-hover grow shrink-0 flex ps-2 lg:ps-5 pe-1 lg:pe-3" data-kt-scrollable="true" data-kt-scrollable-dependencies="#sidebar_header" data-kt-scrollable-height="auto" data-kt-scrollable-offset="0px" data-kt-scrollable-wrappers="#sidebar_content" id="sidebar_scrollable">
               <!-- Sidebar Menu -->
               <div class="kt-menu flex flex-col grow gap-1" data-kt-menu="true" data-kt-menu-accordion-expand-all="false" id="sidebar_menu">
                   <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                       <a href="/dashboard">
                           <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px]" tabindex="0">

                               <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                   <i class="ki-filled ki-element-11 text-lg">
                                   </i>
                               </span>
                               <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                   Dashboards
                               </span>
                           </div>
                       </a>
                   </div>
                   <div class="kt-menu-item pt-2.25 pb-px">
                       <span class="kt-menu-heading uppercase text-xs font-medium text-muted-foreground ps-[10px] pe-[10px]">
                           Orders
                       </span>
                   </div>
                                     <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/orders">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px]" tabindex="0">
                              <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                  <i class="ki-filled ki-security-user text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                  Invoices
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item pt-2.25 pb-px">
                      <span class="kt-menu-heading uppercase text-xs font-medium text-muted-foreground ps-[10px] pe-[10px]">
                          Administration
                      </span>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/users">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px]" tabindex="0">
                              <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                  <i class="ki-filled ki-users text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                  Users
                              </span>
                          </div>
                      </a>
                  </div>
                  <div class="kt-menu-item" data-kt-menu-item-toggle="accordion" data-kt-menu-item-trigger="click">
                      <a href="/roles">
                          <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px]" tabindex="0">
                              <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                  <i class="ki-filled ki-shield-tick text-lg">
                                  </i>
                              </span>
                              <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                  Roles
                              </span>
                          </div>
                      </a>
                  </div>
               </div>
               <!-- End of Sidebar Menu -->
           </div>
       </div>
   </div>
   <!-- End of Sidebar -->