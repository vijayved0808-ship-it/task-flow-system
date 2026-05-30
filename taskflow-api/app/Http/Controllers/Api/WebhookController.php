<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\WhatsApp\Services\WhatsAppService;
use App\Jobs\ProcessInboundWhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    // Meta webhook verification
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            Log::info('WhatsApp webhook verified');
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    // Incoming WhatsApp messages
    public function handle(Request $request)
    {
        $payload = $request->all();
        Log::info('WhatsApp webhook received', ['payload' => $payload]);

        // Queue for async processing — never block webhook
        ProcessInboundWhatsApp::dispatch($payload);

        return response()->json(['status' => 'received']);
    }
}
