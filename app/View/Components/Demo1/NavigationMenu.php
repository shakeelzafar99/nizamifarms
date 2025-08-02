<?php
// app/View/Components/Demo1/NavigationMenu.php

namespace App\View\Components\Demo1;

use Illuminate\View\Component;

class NavigationMenu extends Component
{
    public $activeRoute;

    public function __construct($activeRoute = null)
    {
        $this->
activeRoute = $activeRoute ?? request()->route()->getName();
    }

    public function render()
    {
        return view('components.demo1.navigation-menu');
    }
}