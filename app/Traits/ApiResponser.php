<?php

namespace App\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Shared;  

trait ApiResponser{ 

    protected function success($response)
	{
		return response()->json([
			'status'=> $response->status, 
			'message' => $response->message, 
			'response' => $response->data,
			'errors' => $response->errors,
		], $response->code);
	}

	protected function error($message = null, $code = null)
	{
		return response()->json([
			'status'=>'Error',
			'message' => $message,
			'response' => null
		], $code);
	}

}