<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * NF Assistant on the WEB (ASSISTANT-VIEW-PLAN-JUL2026.md §4). One page that
 * mirrors the phone assistant so the owner can watch the money boxes + activity
 * live and act (confirm cards, match/ignore SMS, chat) from the desk.
 *
 * DESIGN: this controller only renders the shell + gates access. ALL data and
 * actions reuse the EXISTING assistant controllers (Workspace / Sms /
 * Assistant) — routed here under the web `auth` session middleware in web.php
 * (routes/web.php "assistant-view" group). Those controllers resolve the actor
 * via Auth::user() and gate on hasMobilePermission('use_ai_assistant'), both of
 * which work identically under web session auth — so the web and phone can
 * never disagree, and no business logic is duplicated.
 *
 * v1 permission: use_ai_assistant (the same gate the phone uses). Assign it to
 * the owner's role to watch/act for testing. A read-only tier
 * (view_assistant_activity) is a later refinement (see the plan).
 */
class AssistantViewController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user && $user->hasMobilePermission('use_ai_assistant'), 403,
            'You do not have access to the NF Assistant.');

        return view('pages.assistant-view.index');
    }
}
