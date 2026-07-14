{{-- Minimal, chrome-less layout for rendering a page inside an iframe/drawer
     (currently: the Messages chat opened in the right-side drawer from the
     Online Approvals WhatsApp button — /messages?embed=1). Same head, theme
     setup, global scripts and push-stacks as layouts.app, but WITHOUT the
     sidebar, header and footer, so only @yield('content') renders. --}}
<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="en">

<head>
    @include('layouts.partials.head')
    @stack('demo1_css')
    <style>
        /* Embed mode: no app header/padding chrome, so let the embedded UI use
           the full iframe height instead of app.blade.php's reserved offsets. */
        html, body { height: 100%; }
        #content { height: 100%; }
        .wa-page { height: 100% !important; }
    </style>
</head>

<body class="antialiased flex flex-col h-full text-base text-foreground bg-background demo1">
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
    <main class="grow" id="content" role="content">
        @yield('content')
    </main>
    @include('layouts.partials.scripts')
    <script src="{{ asset('assets/js/layouts/demo1.js') }}"></script>
    @stack('demo1_js')
    @stack('modals')
</body>

</html>
