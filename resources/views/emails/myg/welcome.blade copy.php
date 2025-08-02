<!DOCTYPE html>
<html>
<? @include('emails.myg.partials.head')  ?>
<body>
	<? @include('emails.myg.partials.style')  ?>
	<div style="font-family:Arial,Helvetica,sans-serif; line-height: 1.5; font-weight: normal; font-size: 15px; color: #2F3044; min-height: 100%; margin:0; padding:0; width:100%; background-color:#edf2f7">
		<table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 auto; padding:0; max-width:600px">
			<tbody>
				<? @include('partials.logo')  ?>
				<tr>
					<td align="left" valign="center">
						<div style="text-align:left; margin: 0 20px; padding: 40px; background-color:#ffffff; border-radius: 6px">
							<!--begin:Email content-->
							<div style="padding-bottom: 30px; font-size: 17px;">
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
							<!--end:Email content-->
							<div style="padding-bottom: 10px">Kind regards,
								<br>{{ $data["company_name"] }}.

							</div>
						</div>
					</td>
				</tr>
				<? @include('emails.myg.partials.footer')  ?>
			</tbody>
		</table>
	</div>
</body>

</html>