{{-- resources/views/auth/login.blade.php --}}

@extends('layouts.app')

@section('title', 'Orders')

@section('content')

<!-- Container -->
<div class="kt-container-fixed" id="contentContainer">
</div>
<!-- End of Container -->
<div class="flex items-center flex-wrap md:flex-nowrap lg:items-end justify-between border-b border-b-border gap-3 lg:gap-6 mb-5 lg:mb-10">
    <!-- Container -->
    <div class="kt-container-fixed" id="hero_container">
        <div class="grid">
            <div class="kt-scrollable-x-auto">
                <div class="kt-menu gap-3" data-kt-menu="true">
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary kt-menu-item-dropdown" data-kt-menu-item-overflow="true" data-kt-menu-item-placement="bottom-start" data-kt-menu-item-placement-rtl="bottom-end" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
                        <div class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2">
                            <span class="kt-menu-title text-nowrap text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-medium kt-menu-item-here:text-primary kt-menu-item-here:font-medium kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                Account Home
                            </span>
                            <span class="kt-menu-arrow">
                                <i class="ki-filled ki-down text-xs text-muted-foreground menu-item-active:text-primary kt-menu-item-here:text-primary menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                </i>
                            </span>
                        </div>
                        <div class="kt-menu-dropdown kt-menu-default py-2 min-w-[200px]">
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/get-started" tabindex="0">
                                    <span class="kt-menu-title">
                                        Get Started
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/user-profile" tabindex="0">
                                    <span class="kt-menu-title">
                                        User Profile
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/company-profile" tabindex="0">
                                    <span class="kt-menu-title">
                                        Company Profile
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/settings-sidebar" tabindex="0">
                                    <span class="kt-menu-title">
                                        Settings - With Sidebar
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/settings-enterprise" tabindex="0">
                                    <span class="kt-menu-title">
                                        Settings - Enterprise
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/settings-plain" tabindex="0">
                                    <span class="kt-menu-title">
                                        Settings - Plain
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/home/settings-modal" tabindex="0">
                                    <span class="kt-menu-title">
                                        Settings - Modal
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary here kt-menu-item-dropdown" data-kt-menu-item-overflow="true" data-kt-menu-item-placement="bottom-start" data-kt-menu-item-placement-rtl="bottom-end" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
                        <div class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2">
                            <span class="kt-menu-title text-nowrap text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-medium kt-menu-item-here:text-primary kt-menu-item-here:font-medium kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                Billing
                            </span>
                            <span class="kt-menu-arrow">
                                <i class="ki-filled ki-down text-xs text-muted-foreground menu-item-active:text-primary kt-menu-item-here:text-primary menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                </i>
                            </span>
                        </div>
                        <div class="kt-menu-dropdown kt-menu-default py-2 min-w-[200px]">
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/billing/basic" tabindex="0">
                                    <span class="kt-menu-title">
                                        Billing - Basic
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/billing/enterprise" tabindex="0">
                                    <span class="kt-menu-title">
                                        Billing - Enterprise
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/billing/plans" tabindex="0">
                                    <span class="kt-menu-title">
                                        Plans
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item active">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/billing/history" tabindex="0">
                                    <span class="kt-menu-title">
                                        Billing History
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary kt-menu-item-dropdown" data-kt-menu-item-overflow="true" data-kt-menu-item-placement="bottom-start" data-kt-menu-item-placement-rtl="bottom-end" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
                        <div class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2">
                            <span class="kt-menu-title text-nowrap text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-medium kt-menu-item-here:text-primary kt-menu-item-here:font-medium kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                Security
                            </span>
                            <span class="kt-menu-arrow">
                                <i class="ki-filled ki-down text-xs text-muted-foreground menu-item-active:text-primary kt-menu-item-here:text-primary menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                </i>
                            </span>
                        </div>
                        <div class="kt-menu-dropdown kt-menu-default py-2 min-w-[200px]">
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/get-started" tabindex="0">
                                    <span class="kt-menu-title">
                                        Get Started
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/overview" tabindex="0">
                                    <span class="kt-menu-title">
                                        Security Overview
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/allowed-ip-addresses" tabindex="0">
                                    <span class="kt-menu-title">
                                        Allowed IP Addresses
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/privacy-settings" tabindex="0">
                                    <span class="kt-menu-title">
                                        Privacy Settings
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/device-management" tabindex="0">
                                    <span class="kt-menu-title">
                                        Device Management
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/backup-and-recovery" tabindex="0">
                                    <span class="kt-menu-title">
                                        Backup &amp; Recovery
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/current-sessions" tabindex="0">
                                    <span class="kt-menu-title">
                                        Current Sessions
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/security/security-log" tabindex="0">
                                    <span class="kt-menu-title">
                                        Security Log
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary kt-menu-item-dropdown" data-kt-menu-item-overflow="true" data-kt-menu-item-placement="bottom-start" data-kt-menu-item-placement-rtl="bottom-end" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
                        <div class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2">
                            <span class="kt-menu-title text-nowrap text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-medium kt-menu-item-here:text-primary kt-menu-item-here:font-medium kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                Members &amp; Roles
                            </span>
                            <span class="kt-menu-arrow">
                                <i class="ki-filled ki-down text-xs text-muted-foreground menu-item-active:text-primary kt-menu-item-here:text-primary menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                </i>
                            </span>
                        </div>
                        <div class="kt-menu-dropdown kt-menu-default py-2 min-w-[200px]">
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/team-starter" tabindex="0">
                                    <span class="kt-menu-title">
                                        Teams Starter
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/teams" tabindex="0">
                                    <span class="kt-menu-title">
                                        Teams
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/team-info" tabindex="0">
                                    <span class="kt-menu-title">
                                        Team Info
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/members-starter" tabindex="0">
                                    <span class="kt-menu-title">
                                        Members Starter
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/team-members" tabindex="0">
                                    <span class="kt-menu-title">
                                        Team Members
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/import-members" tabindex="0">
                                    <span class="kt-menu-title">
                                        Import Members
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/roles" tabindex="0">
                                    <span class="kt-menu-title">
                                        Roles
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/permissions-toggle" tabindex="0">
                                    <span class="kt-menu-title">
                                        Permissions - Toggler
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/members/permissions-check" tabindex="0">
                                    <span class="kt-menu-title">
                                        Permissions - Check
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary">
                        <a class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2" href="/metronic/tailwind/demo1/account/integrations">
                            <span class="kt-menu-title text-nowrap font-medium text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-semibold kt-menu-item-here:text-primary kt-menu-item-here:font-semibold kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                Integrations
                            </span>
                        </a>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary">
                        <a class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2" href="/metronic/tailwind/demo1/account/notifications">
                            <span class="kt-menu-title text-nowrap font-medium text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-semibold kt-menu-item-here:text-primary kt-menu-item-here:font-semibold kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                Notifications
                            </span>
                        </a>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary">
                        <a class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2" href="/metronic/tailwind/demo1/account/api-keys">
                            <span class="kt-menu-title text-nowrap font-medium text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-semibold kt-menu-item-here:text-primary kt-menu-item-here:font-semibold kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                API Keys
                            </span>
                        </a>
                    </div>
                    <div class="kt-menu-item border-b-2 border-b-transparent kt-menu-item-active:border-b-primary kt-menu-item-here:border-b-primary kt-menu-item-dropdown" data-kt-menu-item-overflow="true" data-kt-menu-item-placement="bottom-start" data-kt-menu-item-placement-rtl="bottom-end" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover">
                        <div class="kt-menu-link gap-1.5 pb-2 lg:pb-4 px-2">
                            <span class="kt-menu-title text-nowrap text-sm text-secondary-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-medium kt-menu-item-here:text-primary kt-menu-item-here:font-medium kt-menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                More
                            </span>
                            <span class="kt-menu-arrow">
                                <i class="ki-filled ki-down text-xs text-muted-foreground menu-item-active:text-primary kt-menu-item-here:text-primary menu-item-show:text-primary kt-menu-link-hover:text-primary">
                                </i>
                            </span>
                        </div>
                        <div class="kt-menu-dropdown kt-menu-default py-2 min-w-[200px]">
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/appearance" tabindex="0">
                                    <span class="kt-menu-title">
                                        Appearance
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/invite-a-friend" tabindex="0">
                                    <span class="kt-menu-title">
                                        Invite a Friend
                                    </span>
                                </a>
                            </div>
                            <div class="kt-menu-item">
                                <a class="kt-menu-link" href="/metronic/tailwind/demo1/account/activity" tabindex="0">
                                    <span class="kt-menu-title">
                                        Activity
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- End of Container -->
</div>
<!-- Container -->
<div class="kt-container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-medium leading-none text-mono">
                Billing History
            </h1>
            <div class="flex items-center gap-2 text-sm font-normal text-secondary-foreground">
                Central Hub for Personal Customization
            </div>
        </div>
        <div class="flex items-center gap-2.5">
            <a class="kt-btn kt-btn-outline" href="#">
                Billing
            </a>
        </div>
    </div>
</div>
<!-- End of Container -->
<!-- Container -->
<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="kt-card kt-card-grid min-w-full">
            <div class="kt-card-header">
                <h3 class="kt-card-title">
                    Billing and Invoicing
                </h3>
                <button class="kt-btn kt-btn-outline">
                    <i class="ki-filled ki-exit-down">
                    </i>
                    Download PDF
                </button>
            </div>
            <div class="kt-card-table">
                <div class="grid datatable-initialized" data-kt-datatable="true" data-kt-datatable-page-size="10" data-kt-datatable-initialized="true">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table kt-table-border" data-kt-datatable-table="true">
                            <thead>
                                <tr>
                                    <th class="w-14">
                                        <input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-check="true" type="checkbox">
                                    </th>
                                    <th class="min-w-[200px]" data-kt-datatable-column="invoice">
                                        <span class="kt-table-col">
                                            <span class="kt-table-col-label">
                                                Invoice
                                            </span>
                                            <span class="kt-table-col-sort">
                                            </span>
                                        </span>
                                    </th>
                                    <th class="w-[170px]">
                                        <span class="kt-table-col">
                                            <span class="kt-table-col-label">
                                                Status
                                            </span>
                                            <span class="kt-table-col-sort">
                                            </span>
                                        </span>
                                    </th>
                                    <th class="min-w-[170px]" data-kt-datatable-column="date">
                                        <span class="kt-table-col">
                                            <span class="kt-table-col-label">
                                                Date
                                            </span>
                                            <span class="kt-table-col-sort">
                                            </span>
                                        </span>
                                    </th>
                                    <th class="min-w-[170px]" data-kt-datatable-column="dueDate">
                                        <span class="kt-table-col">
                                            <span class="kt-table-col-label">
                                                Due Date
                                            </span>
                                            <span class="kt-table-col-sort">
                                            </span>
                                        </span>
                                    </th>
                                    <th class="w-[170px]" data-kt-datatable-column="amount">
                                        <span class="kt-table-col">
                                            <span class="kt-table-col-label">
                                                Amount
                                            </span>
                                            <span class="kt-table-col-sort">
                                            </span>
                                        </span>
                                    </th>
                                    <th class="w-[100px]">
                                    </th>
                                </tr>
                            </thead>

                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2" data-kt-datatable-spinner="true" style="display: none;">
                                <div class="kt-datatable-loading">
                                    <svg class="animate-spin -ml-1 h-5 w-5 text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Loading...
                                </div>
                            </div>
                            <tbody>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="1"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-xd912c</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-warning">
                                            Upcoming
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">6 Aug, 2024</td>
                                    <td class="text-foreground font-normal">HR Dept</td>
                                    <td class="text-foreground font-normal">$24.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="2"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-rq857m</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-success">
                                            Paid
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">17 Jun, 2024</td>
                                    <td class="text-foreground font-normal">6 Aug, 2024</td>
                                    <td class="text-foreground font-normal">$29.99</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="3"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-jk563z</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-success">
                                            Paid
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">30 Apr, 2024</td>
                                    <td class="text-foreground font-normal">6 Aug, 2024</td>
                                    <td class="text-foreground font-normal">$24.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="4"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-hg234x</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-destructive">
                                            Declined
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">21 Apr, 2024</td>
                                    <td class="text-foreground font-normal">6 Aug, 2024</td>
                                    <td class="text-foreground font-normal">$6.59</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="5"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-lp098y</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-success">
                                            Paid
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">14 Mar, 2024</td>
                                    <td class="text-foreground font-normal">6 Aug, 2024</td>
                                    <td class="text-foreground font-normal">$79.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="6"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-q196l</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-success">
                                            Paid
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">08 Jan, 2024</td>
                                    <td class="text-foreground font-normal">6 Aug, 2024</td>
                                    <td class="text-foreground font-normal">$257.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="7"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-m113s</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-warning">
                                            Upcoming
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">07 Nov, 2024</td>
                                    <td class="text-foreground font-normal">Design Dept</td>
                                    <td class="text-foreground font-normal">$67.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="8"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-u859c</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-destructive">
                                            Declined
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">16 May, 2024</td>
                                    <td class="text-foreground font-normal">07 Nov, 2024</td>
                                    <td class="text-foreground font-normal">$494.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="9"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-m803g</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-success">
                                            Paid
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">16 Mar, 2024</td>
                                    <td class="text-foreground font-normal">16 Mar, 2024</td>
                                    <td class="text-foreground font-normal">$142.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                                <tr>
                                    <td><input class="kt-checkbox kt-checkbox-sm" data-kt-datatable-row-check="true" type="checkbox" value="10"></td>
                                    <td class="text-foreground font-normal">Invoice-2024-r204u</td>
                                    <td>
                                        <div class="kt-badge kt-badge-sm kt-badge-outline kt-badge-success">
                                            Paid
                                        </div>
                                    </td>
                                    <td class="text-foreground font-normal">25 Mar, 2024</td>
                                    <td class="text-foreground font-normal">25 Mar, 2024</td>
                                    <td class="text-foreground font-normal">$35.00</td>
                                    <td class="text-center"><a class="kt-link kt-link-underlined kt-link-dashed" href="">
                                            Download
                                        </a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="kt-card-footer justify-center md:justify-between flex-col md:flex-row gap-5 text-secondary-foreground text-sm font-medium">
                        <div class="flex items-center gap-2 order-2 md:order-1">
                            Show
                            <select class="hidden" data-kt-datatable-size="true" data-kt-select="" name="perpage" data-kt-select-initialized="true">
                                <option value="5" data-kt-select-option-initialized="true">5</option>
                                <option value="10" data-kt-select-option-initialized="true">10</option>
                                <option value="20" data-kt-select-option-initialized="true">20</option>
                                <option value="30" data-kt-select-option-initialized="true">30</option>
                                <option value="50" data-kt-select-option-initialized="true">50</option>
                            </select>
                            <div data-kt-select-wrapper="" class="kt-select-wrapper w-16">
                                <div data-kt-select-display="" class="kt-select-display kt-select" tabindex="0" role="button" data-selected="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select an option">10</div>
                                <div data-kt-select-dropdown="" class="kt-select-dropdown hidden " style="z-index: 105;">
                                    <ul role="listbox" aria-label="Select an option" class="kt-select-options " data-kt-select-options="true">
                                        <li data-kt-select-option="" data-value="5" data-text="5" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">5</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="10" data-text="10" class="kt-select-option selected" role="option" aria-selected="true">
                                            <div class="kt-select-option-text" data-kt-text-container="true">10</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="20" data-text="20" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">20</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="30" data-text="30" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">30</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="50" data-text="50" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">50</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            per page
                        </div>
                        <div class="flex items-center gap-4 order-1 md:order-2">
                            <span data-kt-datatable-info="true">1-10 of 30</span>
                            <div class="kt-datatable-pagination" data-kt-datatable-pagination="true"><button class="kt-datatable-pagination-button kt-datatable-pagination-prev disabled" disabled="">
                                    <svg class="rtl:transform rtl:rotate-180 size-3.5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8.86501 16.7882V12.8481H21.1459C21.3724 12.8481 21.5897 12.7581 21.7498 12.5979C21.91 12.4378 22 12.2205 22 11.994C22 11.7675 21.91 11.5503 21.7498 11.3901C21.5897 11.2299 21.3724 11.1399 21.1459 11.1399H8.86501V7.2112C8.86628 7.10375 8.83517 6.9984 8.77573 6.90887C8.7163 6.81934 8.63129 6.74978 8.53177 6.70923C8.43225 6.66869 8.32283 6.65904 8.21775 6.68155C8.11267 6.70405 8.0168 6.75766 7.94262 6.83541L2.15981 11.6182C2.1092 11.668 2.06901 11.7274 2.04157 11.7929C2.01413 11.8584 2 11.9287 2 11.9997C2 12.0707 2.01413 12.141 2.04157 12.2065C2.06901 12.272 2.1092 12.3314 2.15981 12.3812L7.94262 17.164C8.0168 17.2417 8.11267 17.2953 8.21775 17.3178C8.32283 17.3403 8.43225 17.3307 8.53177 17.2902C8.63129 17.2496 8.7163 17.18 8.77573 17.0905C8.83517 17.001 8.86628 16.8956 8.86501 16.7882Z" fill="currentColor"></path>
                                    </svg>
                                </button><button class="kt-datatable-pagination-button active disabled" disabled="">1</button><button class="kt-datatable-pagination-button">2</button><button class="kt-datatable-pagination-button">3</button><button class="kt-datatable-pagination-button kt-datatable-pagination-next">
                                    <svg class="rtl:transform rtl:rotate-180 size-3.5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M15.135 7.21144V11.1516H2.85407C2.62756 11.1516 2.41032 11.2415 2.25015 11.4017C2.08998 11.5619 2 11.7791 2 12.0056C2 12.2321 2.08998 12.4494 2.25015 12.6096C2.41032 12.7697 2.62756 12.8597 2.85407 12.8597H15.135V16.7884C15.1337 16.8959 15.1648 17.0012 15.2243 17.0908C15.2837 17.1803 15.3687 17.2499 15.4682 17.2904C15.5677 17.3309 15.6772 17.3406 15.7822 17.3181C15.8873 17.2956 15.9832 17.242 16.0574 17.1642L21.8402 12.3814C21.8908 12.3316 21.931 12.2722 21.9584 12.2067C21.9859 12.1412 22 12.0709 22 11.9999C22 11.9289 21.9859 11.8586 21.9584 11.7931C21.931 11.7276 21.8908 11.6683 21.8402 11.6185L16.0574 6.83565C15.9832 6.75791 15.8873 6.70429 15.7822 6.68179C15.6772 6.65929 15.5677 6.66893 15.4682 6.70948C15.3687 6.75002 15.2837 6.81959 15.2243 6.90911C15.1648 6.99864 15.1337 7.10399 15.135 7.21144Z" fill="currentColor"></path>
                                    </svg>
                                </button></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


@endsection