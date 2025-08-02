<?php

namespace App\Traits;
 
use Illuminate\Support\Facades\Auth;

trait CurrentUser{ 

    protected function currentUserId()
	{ 
        return Auth::id();  
	}
    protected function currentUser()
	{ 
      return  Auth::user();
    } 
    
}