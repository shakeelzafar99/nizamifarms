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
            <main class="grow pt-0" id="content" role="content">
                @yield('content')
            </main>
            @include('layouts.partials.footer')
        </div>
    </div>
    <!--end::Page layout-->
    {{-- @include('layouts.partials.extra') --}} {{-- Commented out: demo template with broken image links --}}
    @include('layouts.partials.scripts')
    <script src="{{ asset('assets/js/layouts/demo1.js') }}">
    </script>
    @stack('demo1_js')
    @stack('modals')
    @auth
    <script>
    (function() {
        var badge = document.getElementById('wa-unread-badge');
        if (!badge) return;
        function pollUnread() {
            fetch('/messages/unread-count', {
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.unread_count > 0) {
                    badge.textContent = d.unread_count;
                    badge.classList.remove('hidden');
                    if (Notification.permission === 'granted' && d.unread_count > (window._lastWaUnread || 0)) {
                        new Notification('Nizami Farms - New WhatsApp Message', { body: d.unread_count + ' unread message(s)', icon: '/favicon.ico' });
                    }
                    window._lastWaUnread = d.unread_count;
                } else {
                    badge.classList.add('hidden');
                    window._lastWaUnread = 0;
                }
            })
            .catch(function(){});
        }
        if (Notification.permission === 'default') Notification.requestPermission();
        pollUnread();
        setInterval(pollUnread, 15000);
    })();
    </script>
    @endauth
</body>

</html>