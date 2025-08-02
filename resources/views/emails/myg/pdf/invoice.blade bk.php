<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title> {{ $data['MYG']["full_name"] }}</title>
    <style>
        body {
            margin: 0 !important;
            padding: 0 !important;
        }

        #invoice {
            padding: 10px;
            font-size: 11px;
            font-family: Tahoma, "Trebuchet MS", sans-serif;
        }

        .company-details h2 {

            font-size: 14px !important;
            font-weight: bold !important;
        }

        .company-details {
            font-size: 12px !important;
            font-weight: bold !important;
        }

        .border-right {
            border-right: solid 1px #EBEDF3;
        }

        .inv-table td,
        th {
            border: solid 1px #EBEDF3;
            padding: 5px;
        }

        .invoice {
            position: relative;
            background-color: #FFF;
            padding: 5px
        }

        .mb-5 {
            margin-bottom: 5px;
        }

        .mb-10 {
            margin-bottom: 10px;
        }

        .mb-15 {
            margin-bottom: 15px;
        }

        .mb-20 {
            margin-bottom: 20px;
        }

        .font-weight-bold {
            font-weight: bold;
        }

        .invoice .header {
            padding: 10px 0;
            margin-bottom: 30px;
            border-bottom: 1px solid #EBEDF3;
        }

        .invoice table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            margin-bottom: 0;
        }

        .invoice .company-details {
            text-align: left;
            font-size: 16px;
        }

        .heading {
            font-size: 22px;
        }

        .invoice .company-details .name {
            margin-top: 0;
            margin-bottom: 5px;
        }

        .invoice .company-details .name a {
            margin-top: 0;
            margin-bottom: 5px;
            font-size: 22px;
            color: #000;
            text-decoration: none;
        }

        .invoice .contacts {
            margin-bottom: 20px
        }

        .invoice .invoice-to {
            text-align: left
        }

        .invoice .invoice-to .to {
            margin-top: 0;
            margin-bottom: 0
        }

        .invoice .invoice-details {
            text-align: right
        }

        .invoice .invoice-details .invoice-id {
            margin-top: 0;
            color: #3989c6
        }

        .invoice .main {
            padding-bottom: 0px
        }


        .invoice .main .notices {
            padding-left: 10px;
            border-left: 4px solid #3989c6
        }

        .invoice .main .notices .notice {
            font-size: 13px;
        }

        .invoice .footer {
            width: 100%;
            text-align: center;
            color: #777;
            border-top: 1px solid #EBEDF3;
            padding: 20px 0 0px 0
        }

        @media print {
            .invoice {
                font-size: 11px !important;
                overflow: hidden !important
            }

            .invoice .footer {
                position: absolute;
                bottom: 10px;
                page-break-after: always
            }

            .invoice>div:last-child {
                page-break-before: always
            }
        }
    </style>
</head>
<body>
    <div id="invoice">
        <div class="invoice overflow-auto">
            <div style="min-width: 600px">
                <div class="header">
                    <table>
                        <tr>
                            <td style="width: 140px;">
                                <a target="_blank" href="{{env('url')}}">
                                    <img src="{{ env('APP_STORAGE_URL').'/'.$data['inv_logo'] }}" style="width: 120px;">
                                </a>
                            </td>
                            <td width="600" valign="bottom">
                                <div class="company-details">
                                    <h2 class="name">
                                        <a target="_blank" href="{{env('url')}}">
                                            {{ $data['MYG']["full_name"]}}
                                        </a>
                                    </h2>
                                    <div class="mb-5">
                                        {{ $data['MYG']["contact_no"]}}/{{ $data['MYG']["email"]}}
                                    </div>
                                    <div> VAT NO: {{ $data['MYG']["vat_no"]}} COMPANY NO: {{ $data['MYG']["company_no"]}}</div>
                                </div>
                            </td>
                            <td align="right">
                                <h1 class="heading">INVOICE</h1>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="main mb-20">
                    <table>
                        <tr>
                            <td width="30%" class="border-right">
                                <div class="text-dark-50 font-size-lg font-weight-bold mb-5">INVOICE TO.</div>
                                <div class="font-size-lg font-weight-bold mb-20">{{ $data['INV']["company_name"] }}.
                                    <br>{{ $data['INV']["address_first_line"]  }}
                                    <br>{{ $data['INV']["address_second_line"]  }}
                                    <br>{{ $data['INV']["city"]  }}, {{ $data['INV']["postcode"]  }}
                                </div>
                                <div class="text-dark-50 font-size-lg font-weight-bold mb-5">INVOICE NO.</div>
                                <div class="font-size-lg font-weight-bold mb-20">{{ $data['INV']["inv_no"]   }}</div>
                                <div class="text-dark-50 font-size-lg font-weight-bold mb-5">DATE</div>
                                <div class="font-size-lg font-weight-bold mb-20">{{ date('d,M Y', strtotime($data['INV']["inv_date"]))  }}
                                </div>
                                <div class="text-dark-50 font-size-lg font-weight-bold mb-5">STATUS</div>
                                <div class="font-size-lg font-weight-bold mb-20">{{ $data['INV']["inv_status"]   }}</div>
                            </td>
                            <td width="70%" valign="top" style="padding-left:20px ;">
                                <table border="1" class="inv-table">
                                    <thead>
                                        <tr class="tr-head">
                                            <th>
                                                Description</th>
                                            <th>
                                                Branches</th>
                                            <th>
                                                Rate</th>
                                            <th>
                                                Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="font-weight-bolder font-size-lg">
                                            <td class="border-top-0 pl-0 pl-md-5 pt-7 d-flex align-items-center font-weight-normal font-size-lg">
                                                {{ $data['INV']["description"]  }}
                                            </td>
                                            <td align="center"> {{ $data['INV']["no_of_br"]  }}</td>
                                            <td align="right"> @money($data['INV']["per_br_price"]) </td>
                                            <td align="right">@money($data['INV']["tot_price"])</td>
                                        </tr>
                                    </tbody>
                                    <tfoot class="font-weight-bold">
                                        <tr class="font-weight-bolder font-size-lg text-right">
                                            <td align="right" colspan="3">
                                                VAT
                                            </td>
                                            <td align="right">@money($data['INV']["tot_vat"])</td>
                                        </tr>
                                        <tr class="font-weight-bolder font-size-lg text-right">
                                            <td align="right" colspan="3">
                                                TOTAL AMOUNT
                                            </td>
                                            <td align="right">@money($data['INV']["tot_price_vat"])</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <div class="notices">
                                    <div>NOTICE:</div>
                                    <div class="notice">A finance charge of 1.5% will be made on unpaid balances after 30 days.</div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="footer">
                    Invoice was created on a computer and is valid without the signature and seal.
                </div>
            </div>
        </div>
    </div>
</body>

</html>