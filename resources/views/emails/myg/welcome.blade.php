@extends('emails.layouts.myg')
@section('content')
<div>
	<!--begin:Email content-->
	<div style="padding-bottom: 20px; font-size: 17px;">
		<strong>Hello {{ $data["name"] }}!</strong>
	</div>
	<div style="padding-bottom: 30px">{!! $data["msg"] !!}</div>
	<div style="padding-bottom: 30px">Please click the below the link, active your account and set password.</div>
	<div style="padding-bottom: 40px; text-align:center;">
		<a href="{{ $data['url'] }}" rel="noopener" style="text-decoration:none;display:inline-block;text-align:center;padding:0.75575rem 1.3rem;font-size:0.925rem;line-height:1.5;border-radius:0.35rem;color:#ffffff;background-color:#000000;border:0px;margin-right:0.75rem!important;font-weight:600!important;outline:none!important;vertical-align:middle" target="_blank">Set Your Password</a>
	</div>
	<div style="border-bottom: 1px solid #eeeeee; margin: 15px 0"></div>
	<div style="padding-bottom: 50px; word-wrap: break-all;">
		<p style="margin-bottom: 10px;">Button not working? Try pasting this URL into your browser:</p>
		<a href="{{ $data['url'] }}" rel="noopener" target="_blank" style="text-decoration:none;">{{ $data['url'] }}</a>
	</div> 
</div>
@endsection()