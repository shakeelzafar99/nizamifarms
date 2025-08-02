<?php
// app/Http/Controllers/Demo1Controller.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class Demo1Controller extends Controller
{
    public function index()
    {
        return view('pages.demo1.index', [
            'pageTitle' =>
'Demo 1 - Sidebar Layout',
            'pageDescription' => 'Central Hub for Personal Customization',
            'currentDemo' => 'demo1',
            'currentRoute' => \Route::currentRouteName()
        ]);
    }
}