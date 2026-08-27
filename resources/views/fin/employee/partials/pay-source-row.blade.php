{{--
    One "Pay from:" + "🏦 bank" pair for a pending petrol / maintenance claim.

    ⭐ ONE copy on purpose. The petrol panel and the maintenance panel carried a
    byte-identical select each, so a bank picker added to only one of them would
    have left the other booking untagged bank outflows exactly as before.

    ⭐ Why the bank half exists at all: "Online Bank" is a SINGLE chart account —
    WHICH bank the money left lives in receiving_account_id, and BankBalanceService
    only counts TAGGED rows. Approving an online claim without one means the
    per-bank balances never see the money.

    Expects: $req (array incl. id, filed_source_id, filed_bank_id)
             $accounts (PaymentSourceService::sourcesFor rows — arrays, not models)
             $banks    (PaymentSourceService::banks rows)
             $ringClass(the FULL focus-ring class, e.g. 'focus:ring-orange-400' —
                       passed whole, never built as 'ring-'.$colour.'-400', so
                       Tailwind's scanner still sees a literal it can emit)
--}}
@php
    $reqId    = $req['id'];
    $filedId  = $req['filed_source_id'] ?? null;
    $filedBank= $req['filed_bank_id'] ?? null;

    // What the approve button will actually send, decided HERE so the select can
    // never show one account while the POST carries another. Order matches the
    // mobile screen and RequestApprovalController's own precedence:
    // what it was filed against → this approver's starred default → first allowed.
    $preselect = null;
    foreach ($accounts as $a) {
        if ($filedId && (int) $a['id'] === (int) $filedId) { $preselect = (int) $a['id']; break; }
    }
    if (!$preselect) {
        foreach ($accounts as $a) {
            if (!empty($a['is_default'])) { $preselect = (int) $a['id']; break; }
        }
    }
    if (!$preselect && count($accounts)) { $preselect = (int) $accounts[0]['id']; }

    $preselectOnline = false;
    foreach ($accounts as $a) {
        if ((int) $a['id'] === (int) $preselect) { $preselectOnline = !empty($a['is_online']); }
    }

    // The filed bank is only meaningful while the account is unchanged — a bank id
    // from a different account's filing means nothing here.
    $bankPreselect = ($preselect && $filedId && (int) $preselect === (int) $filedId) ? $filedBank : null;
@endphp
<div class="flex items-center gap-1.5">
    <span class="text-xs text-gray-500">Pay from:</span>
    <select id="petrol-pay-src-{{ $reqId }}"
        onchange="petrolSourceChanged({{ $reqId }})"
        style="color: #1f2937; background-color: white; border: 1px solid #d1d5db;"
        class="text-xs px-2 py-1 rounded-md focus:outline-none focus:ring-1 {{ $ringClass ?? 'focus:ring-orange-400' }}">
        @foreach($accounts as $acc)
        <option value="{{ $acc['id'] }}"
            data-online="{{ !empty($acc['is_online']) ? '1' : '0' }}"
            {{ (int) $acc['id'] === (int) $preselect ? 'selected' : '' }}>
            {{ $acc['display_name'] ?? $acc['name'] }}{{ $filedId && (int) $acc['id'] === (int) $filedId ? ' (as filed)' : '' }}
        </option>
        @endforeach
    </select>
</div>
@if(count($banks))
<div class="flex items-center gap-1.5" id="petrol-bank-wrap-{{ $reqId }}"
     style="display: {{ $preselectOnline ? 'flex' : 'none' }};">
    <span class="text-xs text-gray-500" title="Which bank did this leave from?">🏦</span>
    <select id="petrol-pay-bank-{{ $reqId }}"
        style="color: #1f2937; background-color: white; border: 1px solid #d1d5db;"
        class="text-xs px-2 py-1 rounded-md focus:outline-none focus:ring-1 {{ $ringClass ?? 'focus:ring-orange-400' }}">
        <option value="">Which bank?</option>
        @foreach($banks as $b)
        <option value="{{ $b['id'] }}" {{ (int) ($bankPreselect ?? 0) === (int) $b['id'] ? 'selected' : '' }}>
            {{ $b['name'] }}
        </option>
        @endforeach
    </select>
</div>
@endif
