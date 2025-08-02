<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Traits\CurrentUser;
use App\Traits\Email;
use App\Traits\ListRequest;
use App\Traits\Utilities;

class BaseModel extends Model
{
  use CurrentUser, ListRequest, Utilities, Email;

  protected array $err_msgs = [];
  protected array $rules = [];
  public array $data = [];
  protected array $errors = [];
  protected string $status = "Success";
  protected string $message = "";
  protected int $code = 200;
  protected int $transId = 0;
  protected int $companyId = 0;
  protected int $branchId = 0;

  protected function setResponse()
  {
    $response = new ResponseModel();
    $response->status = $this->status;
    $response->data = $this->data;
    $response->message = $this->message;
    $response->errors = $this->errors;
    $response->code = $this->code;
    return $response;
  }

  protected function resetResponse()
  {
    $this->err_msgs = [];
    $this->rules = [];
    //$this->data = [];
    $this->errors = [];
    $this->status = "Success";
    $this->message = "";
    $this->code = 200;
  }

  protected function dataNotFound()
  {
    $this->resetResponse();
    $this->code = 200;
    $this->status = "Error";
    $this->message = "Data not found against provided ID";
  }
  protected function intlSrvError()
  {
    $this->resetResponse();
    $this->code = 200;
    $this->status = "Error";
    $this->errors = [];
    $this->message = "Internal Server Error! Please contact administrator for assistance";
  }
  protected function trxnCompleted()
  {
    $this->resetResponse();
    $this->status = "Success";
    $this->message = "Your transaction has been successfully completed";
  }
  protected function delTrxnCompleted()
  {
    $this->resetResponse();
    $this->status = "Warning";
    $this->message = "Selected record has been deleted successfully";
  }


  protected function trxnNotCompleted()
  {
    $this->resetResponse();
    $this->status = "Error";
    $this->message = $this->message;
  }


  protected function dataValidation()
  { 
    $validator = Validator::make($this->data, $this->rules, $this->err_msgs); 
    if ($validator->fails()) {
      $this->resetResponse();
      $this->data = [];
      $this->message = "Input data validation error";
      $this->errors = $validator->errors()->all();
      $this->status = "Error";
      return false;
    }
    return true;
  }
}
