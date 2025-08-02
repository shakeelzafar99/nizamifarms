<?php
namespace App\Models\Shared;  
use Illuminate\Database\Eloquent\Model;  

class ResponseModel 
 {
   
    public string $status = 'Success';
    public int $code = 200;
    public string $message = '';
    public array $data = [];
    public array $errors = []; 

}
