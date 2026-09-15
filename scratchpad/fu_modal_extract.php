<?php
$f = "C:/NF App/nizamifarms/resources/views/fin/employee/outstanding-invoices.blade.php";
$src = file_get_contents($f);
preg_match_all("#<script[^>]*>(.*?)</script>#s", $src, $m);
$js = implode("\n;\n", $m[1]);
$js = preg_replace("#\{\{--.*?--\}\}#s", "", $js);
$js = preg_replace("#@json\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)#", "\"__JSON__\"", $js);
$routes = [
  "fin.employee.mark-online-message-sent" => "/finance/employee/mark-online-message-sent/__ID__",
  "fin.employee.followup-precheck"        => "/finance/employee/followup-precheck/__ID__",
  "fin.employee.followup-heartbeat"       => "/finance/employee/followup-heartbeat",
  "fin.employee.panels-refresh"           => "/finance/employee/panels-refresh",
];
$js = preg_replace_callback("#\{\{\s*route\(\s*[\"']([a-zA-Z0-9._-]+)[\"'].*?\)\s*\}\}#s", function ($mm) use ($routes) {
    return $routes[$mm[1]] ?? ("/__route__/" . $mm[1]);
}, $js);
$js = preg_replace("#\{\{.*?\}\}#s", "__BLADE__", $js);
$js = preg_replace("#\{!!.*?!!\}#s", "__BLADE__", $js);
$js = preg_replace("#^\s*@(if|else|elseif|endif|foreach|endforeach|php|endphp)\b.*$#m", "", $js);
file_put_contents($argv[1], $js);
echo "ok\n";
