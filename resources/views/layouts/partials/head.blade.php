<!-- <base href="{{ url('/') }}/">
<title>@yield('title', 'Dashboard')</title>
<meta charset="utf-8" />
<meta name="robots" content="follow, index" />
<link rel="canonical" href="{{ url()->current() }}" />
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<meta name="description" content="Sign in page using Tailwind CSS" />
<meta name="twitter:site" content="@keenthemes" />
<meta name="twitter:creator" content="@keenthemes" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="Metronic - Tailwind CSS Sign In" />
<meta name="twitter:description" content="Sign in page using Tailwind CSS" />
<meta name="twitter:image" content="{{ asset('assets/media/app/og-image.png') }}" />
<meta property="og:url" content="{{ url()->current() }}" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:site_name" content="@keenthemes" />
<meta property="og:title" content="Metronic - Tailwind CSS Sign In" />
<meta property="og:description" content="Sign in page using Tailwind CSS" />
<meta property="og:image" content="{{ asset('assets/media/app/og-image.png') }}" />

<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/media/app/apple-touch-icon.png') }}" />
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/media/app/favicon-32x32.png') }}" />
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('assets/media/app/favicon-16x16.png') }}" />
<link rel="shortcut icon" href="{{ asset('assets/media/app/favicon.ico') }}" />

{{-- Fonts --}}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />

{{-- Vendor Styles --}}
<link rel="stylesheet" href="{{ asset('assets/vendors/apexcharts/apexcharts.css') }}" />
<link rel="stylesheet" href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}" />

{{-- Main Styles --}}
@vite('resources/css/app.css') -->


<title>
    @yield('title', 'Dashboard')
</title>
<meta charset="utf-8" />
<meta content="follow, index" name="robots" />
<link href="{{ url(request()->path()) }}" rel="canonical" />
<meta content="width=device-width, initial-scale=1, shrink-to-fit=no" name="viewport" />
<meta content="{{ $pageDescription ?? 'Metronic admin dashboard' }}" name="description" />
<!--begin::Fonts-->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<!--end::Fonts-->
<!--begin::Vendor Stylesheets-->
@stack('vendor_css')
<!--end::Vendor Stylesheets-->
<!--begin::Global Stylesheets Bundle-->
<link href="{{ asset('assets/vendors/apexcharts/apexcharts.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/css/styles.css') }}" rel="stylesheet" />
<!--end::Global Stylesheets Bundle-->
<!--begin::Custom Stylesheets-->
@stack('custom_css')
<!-- @vite('resources/css/app.css')   -->
<!--end::Custom Stylesheets-->