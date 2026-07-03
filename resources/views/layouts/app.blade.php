{{-- resources/views/layouts/demo1/base.blade.php --}}
<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="en">

<head>
    @include('layouts.partials.head')
    @stack('demo1_css')
    @verbatim
    <style>
        /* NF (Jul-2026): reclaim the empty desktop header band. The global
           kt-header holds only a mobile logo/menu-toggle (lg:hidden) plus a
           never-rendered demo "topbar", so on DESKTOP it is a blank 50px bar
           while .kt-wrapper reserves 50px of padding-top for it. Hide it and
           drop the reserved space at lg+. MOBILE is untouched (that logo/toggle
           IS the mobile nav). @verbatim keeps Blade from touching the @media.
           Reversible: delete this block. */
        @media (min-width: 1024px) {
            body.kt-header-fixed header.kt-header { display: none !important; }
            body.kt-header-fixed .kt-wrapper { padding-top: 0 !important; }
        }
        /* NF (Jul-2026): some legacy pages carry a long-standing div imbalance
           (orders blade: 10 extra closes, pre-existing — verified vs git HEAD)
           which makes the browser re-parent everything after the content —
           incl. the footer — to <body>. Body is flex-row, so that footer used
           to render as a full-height strip on the RIGHT ("2025©" column).
           `body > footer` matches ONLY such spilled footers; on healthy pages
           the footer lives inside .kt-wrapper and is untouched. Proper
           re-balancing is scheduled structural work (UI plan, Phase D). */
        body > footer.kt-footer { display: none; }
    </style>
    @endverbatim
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
        // The browser's Notification API is undefined on insecure origins
        // in some browsers (e.g. Chrome on plain http://). Reading
        // Notification.permission unguarded throws there, which used to
        // abort this whole IIFE before pollUnread() ever ran and left the
        // sidebar badge stuck on "hidden" in dev. Cache a single
        // capability flag so the rest of the script never has to care.
        var hasNotifications = (typeof Notification !== 'undefined');
        function pollUnread() {
            fetch('/messages/unread-count', {
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.unread_count > 0) {
                    badge.textContent = d.unread_count;
                    badge.classList.remove('hidden');
                    if (hasNotifications && Notification.permission === 'granted' && d.unread_count > (window._lastWaUnread || 0)) {
                        try {
                            new Notification('Nizami Farms - New WhatsApp Message', { body: d.unread_count + ' unread message(s)', icon: '/favicon.ico' });
                        } catch (_) { /* insecure-origin throw — ignore */ }
                    }
                    window._lastWaUnread = d.unread_count;
                } else {
                    badge.classList.add('hidden');
                    window._lastWaUnread = 0;
                }
            })
            .catch(function(){});
        }
        if (hasNotifications && Notification.permission === 'default') {
            try { Notification.requestPermission(); } catch (_) { /* same as above */ }
        }
        pollUnread();
        setInterval(pollUnread, 15000);
        // Apr-2026: expose a manual-refresh hook so other scripts (e.g.
        // the Unread tab's Mark-All-Read action) can force the sidebar
        // badge to recompute immediately instead of waiting up to 15s
        // for the next poll.
        window.refreshUnreadBadge = pollUnread;
    })();
    </script>
    @endauth
</body>

</html>