<?php

namespace App\Traits;

use Illuminate\Pagination\Paginator;
use \Carbon\Carbon;

trait ListRequest
{
    use Utilities;
    protected int $pageSize = 10;
    protected string $column = "id";
    protected string $direction = "asc";
    protected string $searchTerm = "";
    protected array $filter = [];
    protected array $cFilter = []; 
    protected string $flag = "Pagination";
    protected bool $isDateRange = false;
    protected string $start = "";
    protected string $end = "";

    protected function listRequest($data)
    {

        //Sort
        if (array_key_exists("sorting", $data)) {
            $this->column = $data["sorting"]["column"];
            $this->direction = $data["sorting"]["direction"];
        }
        //End Sort 
        // Paginiation
        if (array_key_exists("paginator", $data)) {
            $this->pageSize = $data["paginator"]["pageSize"];
            $currentPage = $data["paginator"]["page"];
            Paginator::currentPageResolver(function () use ($currentPage) {
                return $currentPage;
            });
        }
        //End Paginiation 

        //Search Term
        if (array_key_exists("filter", $data)) {
            if (count($data["filter"]) > 0) {

                if (array_key_exists("searchTerm", $data["filter"])) {
                    $this->searchTerm = $data["filter"]["searchTerm"];
                }

                if (array_key_exists("flag", $data["filter"])) {
                    $this->flag = $data["filter"]["flag"];
                }

                if (array_key_exists("dateRange", $data["filter"]) && $data["filter"]["dateRange"]["start"] != null && $data["filter"]["dateRange"]["end"] != null) { 
                    $this->isDateRange = true;
                    $this->start = $this->datetoDB($data["filter"]["dateRange"]["start"]); 
                    $this->end = $this->datetoDB($data["filter"]["dateRange"]["end"]);  
                } 

                foreach ($data["filter"] as $col => $col_value) {
                    if ($col != "searchTerm" &&  $col != "flag" &&  $col != "dateRange" &&  $col != "operator") {
                        $this->filter[$col] = $col_value;
                    } 
                } 

                if (array_key_exists("operator", $data["filter"])) {
                    //$operator = array();
                    foreach ($data["filter"]["operator"] as $data) {
                        $operator[] = array($data["col"],$data["condition"],$data["val"]);    
                    } 
                    $this->cFilter = $operator;  
                }
                
                
            }
        }
        //End Search Term

    }
}
