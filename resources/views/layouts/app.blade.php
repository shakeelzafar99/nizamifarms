{{-- resources/views/layouts/demo1/base.blade.php --}}
<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="en">

<head>
    @include('layouts.partials.head')
    @stack('demo1_css')
</head>

<body class="antialiased flex h-full text-base text-foreground bg-background demo1 kt-sidebar-fixed kt-header-fixed">
    <!--begin::Theme mode setup-->
    <script>
        var defaultThemeMode = "light";
        var themeMode;
        if (document.documentElement) {
            if (document.documentElement.hasAttribute("data-kt-theme-mode")) {
                themeMode = document.documentElement.getAttribute("data-kt-theme-mode");
            } else {
                if (localStorage.getItem("data-kt-theme") !== null) {
                    themeMode = localStorage.getItem("data-kt-theme");
                } else {
                    themeMode = defaultThemeMode;
                }
            }
            if (themeMode === "system") {
                themeMode = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
            }
            document.documentElement.setAttribute("data-kt-theme", themeMode);
        }
    </script>
    <!--end::Theme mode setup-->
    <!--begin::Page layout-->
    <div class="flex grow">
        @include('layouts.partials.sidebar')
        <div class="kt-wrapper flex grow flex-col">
            @include('layouts.partials.header')
            <main class="grow pt-5" id="content" role="content">
                @yield('content')
            </main>
            @include('layouts.partials.footer')
        </div>
    </div>
    <!--end::Page layout-->
    @include('layouts.partials.extra')
    @include('layouts.partials.scripts')
    <script src="{{ asset('assets/js/layouts/demo1.js') }}">
    </script>
    @stack('demo1_js')
</body>

</html>