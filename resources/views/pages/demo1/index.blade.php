{{-- 1. Template inheritance and sections --}}
@extends('layouts.demo1.base')

@section('content')
<!-- Your page content here -->
@endsection

{{-- 2. Convert static asset paths --}}
{{-- FROM: src="assets/media/logos/logo.svg" --}}
{{-- TO: --}}
<img alt="Logo" src="{{ asset('assets/media/logos/logo.svg') }}" />
{{-- 3. Use Blade directives for dynamic content --}}
{{-- FROM:
<h1>
 Dashboard
</h1>
--}}
{{-- TO: --}}
<h1>
    {{ $pageTitle ?? 'Dashboard' }}
</h1>
{{-- 4. Include Blade components --}}
<x-demo1.navigation-menu :active="$currentRoute">
</x-demo1.navigation-menu>
<x-shared.theme-mode>
</x-shared.theme-mode>
{{-- 5. Add CSS/JS stacks for page-specific assets --}}
@push('custom_css')
<style>
    /* Page-specific styles */
</style>
@endpush

@push('page_js')
<script>
    // Page-specific JavaScript
</script>
@endpush