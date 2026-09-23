<?php
// LOCAL ONLY — mint a sanctum token for Qasim so the device's exact endpoints can be
// driven from the shell. Refuses to run against anything but the local replica.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'local') { fwrite(STDERR, "refusing: not local\n"); exit(1); }
$u = \App\Models\SysAdmin\UserModel::find(91);
$u->tokens()->where('name', 'device-verify')->delete();
echo $u->createToken('device-verify')->plainTextToken, "\n";
