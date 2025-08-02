@extends('emails.layouts.myg')
@section('content')
<div>
    <!--begin:Email content-->
    <div style="padding-bottom: 20px; font-size: 17px;">
        <strong>Hello {{ $data["name"] }}!</strong>
    </div> 
    {!! $data["msg"] !!}
</div>
@endsection()
 