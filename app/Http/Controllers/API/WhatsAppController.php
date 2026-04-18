<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\MessageModel;
use App\Models\CRM\OrderModel;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppController extends Controller
{
    protected WhatsAppService $whatsapp;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
    }

    // =========================================================================
    // CONVERSATIONS
    // =========================================================================

    /**
     * List all conversations with last message preview
     */
    public function getConversations(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $filter = $request->get('filter', 'all'); // all, unread, mine
            $search = $request->get('search', '');

            $query = ConversationModel::with(['customer:id,first_name,last_name,phone_normalized,city'])
                ->where('status', '!=', 'closed');

            if ($filter === 'unread') {
                $query->where('unread_count', '>', 0);
            } elseif ($filter === 'mine') {
                $query->where('assigned_to', $user->id);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('wa_phone', 'LIKE', "%{$search}%")
                      ->orWhere('wa_contact_name', 'LIKE', "%{$search}%")
                      ->orWhereHas('customer', function ($cq) use ($search) {
                          $cq->where('first_name', 'LIKE', "%{$search}%")
                             ->orWhere('last_name', 'LIKE', "%{$search}%")
                             ->orWhere('phone_normalized', 'LIKE', "%{$search}%")
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                      });
                });
            }

            $conversations = $query->orderByDesc('last_message_at')
                ->limit(100)
                ->get();

            // Get last message for each conversation
            $conversationIds = $conversations->pluck('id')->toArray();
            $lastMessages = MessageModel::whereIn('conversation_id', $conversationIds)
                ->whereIn('id', function ($q) use ($conversationIds) {
                    $q->selectRaw('MAX(id)')
                      ->from('t_wa_messages')
                      ->whereIn('conversation_id', $conversationIds)
                      ->groupBy('conversation_id');
                })
                ->get()
                ->keyBy('conversation_id');

            $result = $conversations->map(function ($conv) use ($lastMessages) {
                $lastMsg = $lastMessages->get($conv->id);
                return [
                    'id' => $conv->id,
                    'customer_id' => $conv->customer_id,
                    'customer_name' => $conv->display_name,
                    'customer_city' => $conv->customer?->city,
                    'wa_phone' => $conv->wa_phone,
                    'wa_contact_name' => $conv->wa_contact_name,
                    'unread_count' => $conv->unread_count,
                    'session_active' => $conv->isSessionActive(),
                    'last_message_at' => $conv->last_message_at?->toIso8601String(),
                    'last_message_preview' => $lastMsg ? mb_substr($lastMsg->content, 0, 80) : null,
                    'last_message_direction' => $lastMsg?->direction,
                    'last_message_type' => $lastMsg?->type,
                    'assigned_to' => $conv->assigned_to,
                ];
            });

            return response()->json(['success' => true, 'conversations' => $result]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to get conversations', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $conversation = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city,total_orders,total_spent')
                ->find($conversationId);

            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            $before = $request->get('before'); // for pagination - load older messages
            $limit = $request->get('limit', 50);

            $query = MessageModel::where('conversation_id', $conversationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if ($before) {
                $query->where('id', '<', $before);
            }

            $messages = $query->limit($limit)->get();

            $result = $messages->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'wa_message_id' => $msg->wa_message_id,
                    'direction' => $msg->direction,
                    'type' => $msg->type,
                    'content' => $msg->content,
                    'template_name' => $msg->template_name,
                    'media_url' => $msg->media_public_url,
                    'media_mime_type' => $msg->media_mime_type,
                    'status' => $msg->status,
                    'sent_by' => $msg->sent_by,
                    'sender_name' => $msg->sender ? $msg->sender->name : null,
                    'error_message' => $msg->error_message,
                    'metadata' => $msg->metadata ? json_decode($msg->metadata, true) : null,
                    'created_at' => $msg->created_at?->toIso8601String(),
                ];
            })->reverse()->values(); // Reverse so oldest is first in the array

            return response()->json([
                'success' => true,
                'conversation' => [
                    'id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'customer_name' => $conversation->display_name,
                    'customer_city' => $conversation->customer?->city,
                    'customer_orders' => $conversation->customer?->total_orders,
                    'customer_spent' => $conversation->customer?->total_spent,
                    'wa_phone' => $conversation->wa_phone,
                    'session_active' => $conversation->isSessionActive(),
                    'session_expires_at' => $conversation->last_customer_message_at
                        ? $conversation->last_customer_message_at->addHours(24)->toIso8601String()
                        : null,
                    'assigned_to' => $conversation->assigned_to,
                ],
                'messages' => $result,
                'has_more' => $messages->count() === $limit,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to get messages', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a free-form text message in a conversation
     */
    public function sendMessage(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate(['message' => 'required|string|max:4096']);

            $conversation = ConversationModel::find($conversationId);
            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            if (!$conversation->isSessionActive()) {
                return response()->json([
                    'success' => false,
                    'message' => '24-hour window expired. Use a template message to re-initiate.',
                    'session_expired' => true,
                ], 422);
            }

            $response = $this->whatsapp->sendTextMessage($conversation->wa_phone, $request->input('message'));

            if (!($response['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to send message',
                ], 500);
            }

            $message = $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $response,
                'text',
                $request->input('message'),
                $user->id
            );

            return response()->json([
                'success' => true,
                'message_id' => $message?->id,
                'wa_message_id' => $message?->wa_message_id,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send message', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a template message (can initiate conversations outside 24h window)
     */
    public function sendTemplate(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'phone' => 'required|string',
                'template_name' => 'required|string',
                'body_params' => 'nullable|array',
                'customer_id' => 'nullable|integer',
                'conversation_id' => 'nullable|integer',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));
            $templateName = $request->input('template_name');
            $bodyParams = $request->input('body_params', []);

            $response = $this->whatsapp->sendTemplateMessage($phone, $templateName, 'en', $bodyParams);

            if (!($response['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to send template',
                ], 500);
            }

            // Find or create conversation
            $conversation = $this->whatsapp->findOrCreateConversation($phone);

            // Link to customer if provided and not already linked
            if ($request->input('customer_id') && !$conversation->customer_id) {
                $conversation->update(['customer_id' => $request->input('customer_id')]);
            }

            // Build human-readable content from template
            $templateDisplay = $this->buildTemplateDisplayText($templateName, $bodyParams);

            $message = $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $response,
                'template',
                $templateDisplay,
                $user->id,
                $templateName,
                $bodyParams
            );

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send template', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a conversation as read
     */
    public function markRead(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            ConversationModel::where('id', $conversationId)->update(['unread_count' => 0]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get total unread count (for badge display)
     */
    public function getUnreadCount(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => true, 'unread_count' => 0]);
            }

            $count = ConversationModel::where('unread_count', '>', 0)
                ->where('status', '!=', 'closed')
                ->sum('unread_count');

            return response()->json(['success' => true, 'unread_count' => $count]);

        } catch (\Exception $e) {
            return response()->json(['success' => true, 'unread_count' => 0]);
        }
    }

    /**
     * Get WhatsApp message history for a specific customer
     */
    public function getCustomerMessages(Request $request, $customerId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $conversation = ConversationModel::where('customer_id', $customerId)->first();

            if (!$conversation) {
                return response()->json([
                    'success' => true,
                    'conversation' => null,
                    'messages' => [],
                ]);
            }

            // Delegate to getMessages with the conversation ID
            $request->merge(['limit' => $request->get('limit', 50)]);
            return $this->getMessages($request, $conversation->id);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a message to a customer (creates conversation if needed)
     */
    public function sendToCustomer(Request $request, $customerId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $customer = \App\Models\CRM\CustomerModel::find($customerId);
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
            }

            $phone = $this->whatsapp->formatPhone($customer->phone_normalized ?? $customer->phone);

            $conversation = $this->whatsapp->findOrCreateConversation($phone);
            if (!$conversation->customer_id) {
                $conversation->update(['customer_id' => $customerId]);
            }

            // If sending a template
            if ($request->has('template_name')) {
                $request->merge([
                    'phone' => $phone,
                    'customer_id' => $customerId,
                    'conversation_id' => $conversation->id,
                ]);
                return $this->sendTemplate($request);
            }

            // If sending a text (session must be active)
            $request->validate(['message' => 'required|string|max:4096']);
            return $this->sendMessage($request, $conversation->id);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a free-form text message to a phone number (creates conversation if needed)
     */
    public function sendToPhone(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'phone' => 'required|string',
                'message' => 'required|string|max:4096',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));
            $conversation = $this->whatsapp->findOrCreateConversation($phone);

            if (!$conversation->isSessionActive()) {
                return response()->json([
                    'success' => false,
                    'message' => '24-hour window expired. Use a template message.',
                    'session_expired' => true,
                ], 422);
            }

            $response = $this->whatsapp->sendTextMessage($phone, $request->input('message'));

            if (!($response['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to send message',
                ], 500);
            }

            $message = $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $response,
                'text',
                $request->input('message'),
                $user->id
            );

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send to phone', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get available templates for sending
     */
    public function getTemplates(Request $request)
    {
        try {
            $query = DB::table('t_wa_templates')
                ->where('status', 'approved');

            // Hide soft-disabled templates from every mobile picker.
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'is_active')) {
                $query->where('is_active', 1);
            }

            $context = $request->query('context');
            if ($context) {
                $contexts = array_map('trim', explode(',', $context));
                $query->where(function ($q) use ($contexts) {
                    foreach ($contexts as $ctx) {
                        if ($ctx === '') continue;
                        $q->orWhereRaw("FIND_IN_SET(?, show_in) > 0", [$ctx]);
                    }
                });

                // Scope filtering:
                //   - Non-qurbani picker → hide qurbani-only templates
                //   - Qurbani picker     → hide regular-only templates
                // Templates with both flags = 0 are "Common" and show in both.
                $isQurbaniCtx = false;
                foreach ($contexts as $c) {
                    if ($c !== '' && (str_starts_with($c, 'qurbani_') || $c === 'qurbani')) {
                        $isQurbaniCtx = true;
                        break;
                    }
                }
                if (!$isQurbaniCtx && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) {
                    $query->where('is_qurbani_only', 0);
                }
                if ($isQurbaniCtx && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'is_regular_only')) {
                    $query->where('is_regular_only', 0);
                }
            }

            $templates = $query->orderBy('display_name')->get();

            return response()->json(['success' => true, 'templates' => $templates]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost tracking summary
     */
    public function getCostSummary(Request $request)
    {
        try {
            $user = Auth::user();

            $todayCount = DB::table('t_wa_cost_log')->whereDate('created_at', today())->count();
            $monthCount = DB::table('t_wa_cost_log')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();

            $byCategory = DB::table('t_wa_cost_log')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category');

            return response()->json([
                'success' => true,
                'today' => $todayCount,
                'this_month' => $monthCount,
                'by_category' => $byCategory,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Build human-readable text from a template name + params for display
     */
    /**
     * Register or update a device's FCM token for push notifications
     */
    public function registerDevice(Request $request)
    {
        try {
            $user = Auth::user();
            $request->validate([
                'fcm_token' => 'required|string|max:500',
                'device_type' => 'nullable|string|in:android,ios',
            ]);

            $token = $request->input('fcm_token');
            $deviceType = $request->input('device_type', 'android');

            // Deactivate any old entries with this token (could be from another user)
            DB::table('t_wa_device_tokens')
                ->where('fcm_token', $token)
                ->where('user_id', '!=', $user->id)
                ->update(['is_active' => 0]);

            // Upsert: insert or update for this user + token combination
            DB::table('t_wa_device_tokens')->updateOrInsert(
                ['user_id' => $user->id, 'fcm_token' => $token],
                [
                    'device_type' => $deviceType,
                    'is_active' => 1,
                    'updated_at' => now(),
                ]
            );

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('FCM: Failed to register device', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a test push notification to the current user's device (dev only)
     */
    public function testNotification(Request $request)
    {
        try {
            $user = Auth::user();
            $senderName = $request->input('sender_name', 'Ahmed Khan');
            $message = $request->input('message', 'Hi, I want to place an order for tomorrow delivery');

            $firebase = app(\App\Services\FirebaseService::class);
            $projectId = config('whatsapp.firebase_project_id', '');
            $credFile = config('whatsapp.firebase_credentials_path', 'firebase-service-account.json');
            $credPath = base_path($credFile);

            if (!$projectId) {
                return response()->json(['success' => false, 'message' => 'FIREBASE_PROJECT_ID not set in .env']);
            }
            if (!file_exists($credPath)) {
                return response()->json(['success' => false, 'message' => "Firebase credentials file not found at: {$credFile}. Place the JSON file at: {$credPath}"]);
            }

            $tokenCount = DB::table('t_wa_device_tokens')
                ->where('is_active', 1)
                ->where('user_id', $user->id)
                ->count();

            if ($tokenCount === 0) {
                return response()->json(['success' => false, 'message' => 'No active FCM device token found for your user. Make sure you logged in on the mobile app after Firebase was set up.']);
            }

            $firebase->notifyNewWhatsAppMessage($senderName, $message, null);

            return response()->json(['success' => true, 'message' => "Test notification sent to {$tokenCount} device(s). Check your notification bar."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get orders for a customer (for invoice picker)
     */
    public function getCustomerOrders(Request $request, $customerId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $orders = OrderModel::where('customer_id', $customerId)
                ->with(['lineItems:id,order_id,name,quantity,unit_price,line_total', 'assignedRider:id,fullname'])
                ->orderBy('order_date', 'desc')
                ->limit(20)
                ->get(['id', 'order_number', 'order_date', 'order_status', 'total_price', 'customer_id', 'assigned_rider_user_id', 'estimated_delivery_at']);

            return response()->json([
                'success' => true,
                'orders' => $orders->map(function($o) {
                    $eta = null;
                    if ($o->estimated_delivery_at && strtolower($o->order_status) === 'out_for_delivery') {
                        $eta = \Carbon\Carbon::parse($o->estimated_delivery_at)->format('h:i A');
                    }
                    return [
                        'id' => $o->id,
                        'order_number' => $o->order_number,
                        'order_date' => $o->order_date,
                        'status' => $o->order_status,
                        'total' => $o->total_price,
                        'items_count' => $o->lineItems->count(),
                        'items_summary' => $o->lineItems->take(3)->pluck('name')->join(', '),
                        'rider_name' => $o->assignedRider?->fullname,
                        'eta' => $eta,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate invoice image URL for an order
     */
    public function getInvoiceImageUrl(Request $request, $orderId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $webController = app(\App\Http\Controllers\Web\WhatsAppWebController::class);
            return $webController->getInvoiceImageUrl($request, $orderId);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Upload invoice image captured by client
     */
    public function uploadInvoiceImage(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $webController = app(\App\Http\Controllers\Web\WhatsAppWebController::class);
            return $webController->uploadInvoiceImage($request);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send invoice via WhatsApp template with image header
     */
    public function sendInvoice(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'order_id' => 'required|integer',
                'phone' => 'required|string',
                'template_name' => 'required|string',
                'body_params' => 'nullable|array',
                'customer_id' => 'nullable|integer',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));

            $imgResponse = $this->getInvoiceImageUrl($request, $request->input('order_id'));
            $imgData = json_decode($imgResponse->getContent(), true);

            if (!($imgData['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $imgData['message'] ?? 'Failed to generate invoice image'], 500);
            }

            $imageUrl = $imgData['image_url'];
            $orderNumber = $imgData['order_number'];

            $headerParams = [
                ['type' => 'image', 'image' => ['link' => $imageUrl]],
            ];
            $bodyParams = $request->input('body_params', []);

            $result = $this->whatsapp->sendTemplateMessage($phone, $request->input('template_name'), 'en', $bodyParams, $headerParams);

            if (!($result['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send'], 500);
            }

            $conversation = $this->whatsapp->findOrCreateConversation($phone);

            if ($request->input('customer_id') && !$conversation->customer_id) {
                $conversation->update(['customer_id' => $request->input('customer_id')]);
            }

            $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $result,
                'template',
                "Invoice #{$orderNumber}",
                $user->id,
                $request->input('template_name'),
                $bodyParams
            );

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'order_number' => $orderNumber,
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send invoice', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    protected function buildTemplateDisplayText(string $templateName, array $params): string
    {
        $templateBodies = [
            'capacity_full' => "Dear {{1}},\n\nWe received your order (Order No: {{2}}) on our website today.\n\nAs we are fully occupied today, your order can be delivered fresh tomorrow. Would you like to confirm delivery for tomorrow?\n\nThank you for choosing Nizami Farms.",
            'next_day_delivery' => "Dear {{1}},\n\nAn order was received on our website today - Order No: {{2}}\n\nThis order can be delivered to you fresh tomorrow. Would you like to have this order delivered tomorrow? Please confirm.\n\nThank you for ordering from Nizami Farms",
            'meatless_days' => "Dear {{1}},\n\nThank you for placing your order {{2}} with Nizami Farms! We confirm receipt of your order.\n\nPlease note that Tuesday and Wednesday are non-meat days, and our operations are closed. Your order will be delivered on Thursday.\n\nTo confirm, kindly reply to this message. We will process your order for Thursday delivery.\n\nBest regards,\nNizami Farms Team",
            'location_request' => "Dear {{1}},\n\nCould you please share your Google Maps location pin for delivery? Simply tap the attach icon > Location > Send your current location.\n\nThis helps our rider reach you without any delays.\n\nThank you,\nNizami Farms Team",
            'customer_greeting' => "Assalam-o-Alaikum {{1}},\n\nThis is Nizami Farms. How can we help you today?\n\nBest regards,\nNizami Farms Team",
            'delivery_confirmation_online' => "Dear {{1}},\n\nWe are happy to confirm that your order #{{2}} has been successfully delivered on {{3}} by our rider {{4}}.\n\nYour payment method is Online Bank Transfer. Please share a screenshot of the transfer here once the transaction has been made.\n\nAccount Title: \"Nizami Farms\"\n- Bank: Habib Bank Limited (HBL)\n   Account no: 23297901934403\n   IBAN: PK35HABB0023297901934403\n\n- Bank: Meezan Bank Limited\n   Account no: 03050106554237\n   IBAN: PK75MEZN0003050106554237\n\nThank you for choosing Nizami Farms!",
            'delivery_confirmation' => "Dear {{1}},\n\nYour order {{2}} is now out for delivery! Our rider will contact you upon arrival at your location.\n\nPayment options:\n- Cash on delivery\n- Online transfer\n\nAccount Title: \"Nizami Farms\"\n• Bank: Habib Bank Limited (HBL)\n   Account no: 23297901934403\n   IBAN: PK35HABB0023297901934403\n\n• Bank: Meezan Bank Limited\n   Account no: 03050106554237\n   IBAN: PK75MEZN0003050106554237\n\n(Please share the transfer slip with us to ensure a smooth delivery process)\n\nThank you for choosing Nizami Farms!",
        ];

        $body = $templateBodies[$templateName] ?? "[Template: {$templateName}]";

        // Replace placeholders with actual params
        foreach ($params as $index => $value) {
            $placeholder = '{{' . ($index + 1) . '}}';
            $body = str_replace($placeholder, $value, $body);
        }

        return $body;
    }
}
