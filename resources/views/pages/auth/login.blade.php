{{-- resources/views/auth/login.blade.php --}}

@extends('layouts.guest')

@section('title', 'Login')

@section('content')
<div class="kt-card max-w-[370px] w-full">
    <form action="{{ route('authenticate') }}" class="kt-card-content flex flex-col gap-5 p-10" id="sign_in_form" method="post">
        @csrf
        <div class="text-center mb-2.5">
            <h3 class="text-lg font-medium text-mono leading-none mb-2.5">
                Nizami Farms
            </h3>
        </div>
        @if(session('message'))
        <div style="padding: 8px 12px; background-color: #ecfdf5; border: 1px solid #10b981; border-radius: 6px; color: #065f46; font-size: 13px; text-align: center;">
            {{ session('message') }}
        </div>
        @endif
        @if($errors->any())
        <div style="padding: 8px 12px; background-color: #fef2f2; border: 1px solid #ef4444; border-radius: 6px; color: #991b1b; font-size: 13px; text-align: center;">
            {{ $errors->first() }}
        </div>
        @endif
        <div class="flex flex-col gap-1">
            <label class="kt-form-label font-normal text-mono">
                Email
            </label>
            <input name="email" id="email" class="kt-input" placeholder="email@email.com" type="text" value="" />
        </div>
        <div class="flex flex-col gap-1">
            <div class="flex items-center justify-between gap-1">
                <label class="kt-form-label font-normal text-mono">
                    Password
                </label>
                <a class="text-sm kt-link shrink-0" href="html/demo1/authentication/classic/reset-password/enter-email.html">
                    Forgot Password?
                </a>
            </div>
            <div class="kt-input" data-kt-toggle-password="true">
                <input name="password" id="password" placeholder="Enter Password" type="password" value="" />
                <button class="kt-btn kt-btn-sm kt-btn-ghost kt-btn-icon bg-transparent! -me-1.5" data-kt-toggle-password-trigger="true" type="button">
                    <span class="kt-toggle-password-active:hidden">
                        <i class="ki-filled ki-eye text-muted-foreground">
                        </i>
                    </span>
                    <span class="hidden kt-toggle-password-active:block">
                        <i class="ki-filled ki-eye-slash text-muted-foreground">
                        </i>
                    </span>
                </button>
            </div>
        </div>
        <label class="kt-label">
            <input class="kt-checkbox kt-checkbox-sm" name="check" type="checkbox" value="1" />
            <span class="kt-checkbox-label">
                Remember me
            </span>
        </label>
        <button class="kt-btn kt-btn-primary flex justify-center grow">
            Sign In
        </button>
    </form>
    
    <!-- Mobile App Download Section -->
    <div class="kt-card-content p-6 border-t border-gray-200">
        <div class="text-center">
            <p class="text-sm text-muted-foreground mb-3">
                Download Rider Mobile App
            </p>
            <a href="{{ asset('downloads/NizamiFarms-Rider.apk') }}" 
               class="kt-btn kt-btn-sm kt-btn-light flex items-center justify-center gap-2 w-full"
               download>
                <i class="ki-filled ki-android text-lg"></i>
                <span>Download Android App (v8.7.0)</span>
            </a>
            <p class="text-xs text-muted-foreground mt-2">
                For riders only
            </p>
        </div>
    </div>
</div>
@endsection
