<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
	<title>{{ $data["company_name"] }}</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
</head>

<body>
	<style>
		html,
		body {
			padding: 0;
			margin: 0;
		}
	</style>
	<div style="font-family:Arial,Helvetica,sans-serif; line-height: 1.5; font-weight: normal; font-size: 15px; color: #2F3044; min-height: 100%; margin:0; padding:0; width:100%; background-color:#edf2f7">
		<table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 auto; padding:0; max-width:600px">
			<tbody>
				<tr>
					<td align="center" valign="center" style="text-align:center; padding: 40px">
						<a href="{{env('url')}}" rel="noopener" target="_blank"> 
							<img alt="{{ $data['company_name'] }}" src="{{ env('APP_STORAGE_URL').'/'.$data['logo'] }}" style="max-height: 80px;" />
						</a>
					</td>
				</tr>
				<tr>
					<td align="left" valign="center">
						<div style="text-align:left; margin: 0 20px; padding: 40px; background-color:#ffffff; border-radius: 6px">
							@yield("content")
							<div style="padding-bottom: 10px">Kind regards,
								<br>{{ $data["company_name"] }}.
							</div>
						</div>

					</td>
				</tr>
				<tr>
					<td align="center" valign="center" style="font-size: 13px; text-align:center;padding: 20px; color: #6d6e7c;">
						<p>Copyright &copy;
							<a href="{{env('url')}}" rel="noopener" target="_blank">{{env('APP_NAME')}}</a>.
						</p>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</body>

</html>