<?php

namespace Modules\Everymarket\Http\Controllers;

use App\Mailbox;
use App\Conversation;
use App\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class EverymarketController extends Controller
{
    /**
     * Mailbox Everymarket settings page.
     * @return Response
     */
    public function mailboxSettings($id)
    {
        $mailbox = Mailbox::findOrFail($id);

        $settings = \Everymarket::getMailboxEverymarketSettings($mailbox);

        return view('everymarket::mailbox_settings', [
            'settings' => [
                'everymarket.shop_domain' => $settings['shop_domain'] ?? '',
                'everymarket.api_domain' => $settings['api_domain'] ?? '',
                'everymarket.access_token' => $settings['access_token'] ?? '',
                'everymarket.api_version' => $settings['api_version'] ?? '',
            ],
            'mailbox' => $mailbox
        ]);
    }

    public function mailboxSettingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);

        $settings = $request->settings ?: [];

        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                $settings[str_replace('everymarket.', '', $key)] = $value;
                unset($settings[$key]);
            }
        }

        if (!empty($settings['shop_domain'])) {
            $settings['shop_domain'] = preg_replace("/https?:\/\//i", '', $settings['shop_domain']);
            if (!\Helper::sanitizeRemoteUrl('https://'.$settings['shop_domain'])) {
                $settings['shop_domain'] = '';
            }
        }

        if (!empty($settings['api_domain'])) {
            $settings['api_domain'] = preg_replace("/https?:\/\//i", '', $settings['api_domain']);
            if (!\Helper::sanitizeRemoteUrl('https://'.$settings['api_domain'])) {
                $settings['api_domain'] = '';
            }
        }

        $mailbox->everymarket = json_encode($settings);
        $mailbox->save();

        if (!empty($settings['shop_domain']) &&!empty($settings['api_domain']) && !empty($settings['access_token']) && !empty($settings['api_version'])) {
            // Check API credentials - create dummy customer object for testing
            $result = \Everymarket::apiGetOrders('test@123.com', $mailbox);

            if (!empty($result['error'])) {
                \Session::flash('flash_error', __('Error occurred connecting to the API').': '.$result['error']);
            } else {
                \Session::flash('flash_success', __('Successfully connected to the API.'));
            }
        } else {
            \Session::flash('flash_success_floating', __('Settings updated'));
        }

        return redirect()->route('mailboxes.everymarket', ['id' => $id]);
    }

    /**
     * Ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        switch ($request->action) {

            case 'orders':
                $response['html'] = '';

                $mailbox = null;
                if ($request->mailbox_id) {
                    $mailbox = Mailbox::find($request->mailbox_id);
                }

                $mailbox_api_enabled = \Everymarket::isMailboxApiEnabled($mailbox);
                $orders = [];

                if (\Everymarket::isApiEnabled() || $mailbox_api_enabled) {

                    foreach ($request->customer_emails as $customer_email) {

                        $result = \Everymarket::apiGetOrders($customer_email, $mailbox);

                        if (!empty($result['error'])) {
                            \Log::error('[Everymarket] API Error: '.$result['error']);
                            $response['msg'] = $result['error'];
                        } elseif (is_array($result['data']) && count($result['data'])) {
                            $orders = $result['data'];

                            // Cache orders for an hour.
                            $cache_key = 'em_orders_'.$customer_email;
                            if ($mailbox_api_enabled) {
                                $cache_key = 'em_orders_'.$request->mailbox_id.'_'.$customer_email;
                            }

                            \Cache::put($cache_key, $orders, now()->addMinutes(60));
                            break;
                        }
                    }
                }

                $shop_url = '';
                $api_url = '';
                if ($mailbox && \Everymarket::isMailboxApiEnabled($mailbox)) {
                    $settings  = \Everymarket::getMailboxEverymarketSettings($mailbox);
                    $shop_url = \Everymarket::getSanitizedShopDomain($settings['shop_domain'] ?? '');
                    $api_url = \Everymarket::getSanitizedApiDomain($settings['api_domain'] ?? '');
                } else {
                    $shop_url = \Everymarket::getSanitizedShopDomain();
                    $api_url = \Everymarket::getSanitizedApiDomain();
                }

                $response['html'] = \View::make('everymarket::partials/orders_list', [
                    'orders'         => $orders,
                    'load'           => false,
                    'shop_url'       => $shop_url,
                    'api_url'        => $api_url,
                ])->render();

                $response['status'] = 'success';
                break;

            case 'customers':

                $response['html'] = '';
                $mailbox = null;
                if ($request->mailbox_id) {
                    $mailbox = Mailbox::find($request->mailbox_id);
                }

                $mailbox_api_enabled = \Everymarket::isMailboxApiEnabled($mailbox);
                $customers = [];

                if (\Everymarket::isApiEnabled() || $mailbox_api_enabled) {
                    $result = \Everymarket::apiGetCustomers($request->search_input, $mailbox);

                    if (!empty($result['error'])) {
                        $response['msg'] = $result['error'];
                    } elseif (is_array($result['data']) && count($result['data'])) {
                        $customers = $result['data'];
                        $response['html'] = \View::make('everymarket::partials/customers_list', [
                            'customers'      => $customers,
                            'load'           => false
                        ])->render();
                        $response['status'] = 'success';
                    } else {
                        $response['msg'] = "Could not find related customers.";
                    }
                }
                
                break;

            case 'add_email':

                $response['status'] = 'error';

                if($request->conversation_id) {
                    $conversation = Conversation::find($request->conversation_id);
                    $customer = $conversation->customer;

                    if($customer) {
                        $email_added = $customer->addEmail($request->email, true);
                        if( $email_added === false ) {
                            $response['msg'] = "This email was already added to another customer.";
                        } else {
                            $response['status'] = 'success';
                        }
                    }
                }

                break;

            case 'create_cs_request':
                $response['status'] = 'error';
                
                $mailbox = null;
                if ($request->mailbox_id) {
                    $mailbox = Mailbox::find($request->mailbox_id);
                }
                
                if (empty($request->order_number) || empty($request->line_item_id) || empty($request->reason) || empty($request->note)) {
                    $response['msg'] = 'Missing required fields';
                    break;
                }
                
                $mailbox_api_enabled = \Everymarket::isMailboxApiEnabled($mailbox);
                
                // Call Everymarket API to create CS request
                if (\Everymarket::isApiEnabled() || $mailbox_api_enabled) {

                    $api_result = \Everymarket::apiPostCsRequests($request, $mailbox);
                    
                    if (!empty($api_result['error'])) {
                        $response['status'] = 'error';
                        $response['msg'] = $api_result['error'];
                        \Log::error('[Everymarket] Failed to create CS request: ' . $api_result['error']);
                    } else {
                        $response['status'] = 'success';
                        $response['msg'] = __('CS request created successfully');
                        
                        $user = auth()->user();
                        $conversation = Conversation::find($request->conversation_id);
                        $conversation->requestedBy($user);

                        \Eventy::filter('conversation.set_custom_field', false, $conversation, 'Request Status', 'waiting_reply');
                        // Clear cache for this order so it refreshes on next load
                        // Cache key would need customer email, which we don't have here
                        // The cache will expire naturally or can be cleared manually
                    }
                } else {
                    $response['status'] = 'error';
                    $response['msg'] = __('API is not enabled');
                }
                
                break;

            case 'add_cs_request_event':
                $response['status'] = 'error';

                $mailbox = null;
                if ($request->mailbox_id) {
                    $mailbox = Mailbox::find($request->mailbox_id);
                }
                
                if (empty($request->order_request_id) || empty($request->note)) {
                    $response['msg'] = 'Missing required fields';
                    break;
                }
                
                $mailbox_api_enabled = \Everymarket::isMailboxApiEnabled($mailbox);
                
                // Call Everymarket API to add event/note to CS request
                if (\Everymarket::isApiEnabled() || $mailbox_api_enabled) {
                    $api_result = \Everymarket::apiPostCsRequestEvent(
                        $request->order_number ?? '',
                        $request->order_request_id,
                        $request->note,
                        $request->user_email ?? null,
                        $mailbox
                    );
                    
                    if (!empty($api_result['error'])) {
                        $response['status'] = 'error';
                        $response['msg'] = $api_result['error'];
                        \Log::error('[Everymarket] Failed to add CS request event: ' . $api_result['error']);
                    } else {
                        $response['status'] = 'success';
                        $response['msg'] = __('Note added successfully');

                        $user = auth()->user();
                        $conversation = Conversation::find($request->conversation_id);
                        if(!$conversation->isRequestedByUser($user->id)) {
                            $conversation->requestedBy($user);
                        }
                        \Eventy::filter('conversation.set_custom_field', false, $conversation, 'Request Status', 'waiting_reply');
                        // Cache update will be handled by frontend after appending the event
                    }
                } else {
                    $response['status'] = 'error';
                    $response['msg'] = __('API is not enabled');
                }
                
                break;

            case 'close_cs_request':
                $response['status'] = 'error';

                $mailbox = null;
                if ($request->mailbox_id) {
                    $mailbox = Mailbox::find($request->mailbox_id);
                }
                
                if (empty($request->order_request_id) || empty($request->order_number)) {
                    $response['msg'] = 'Missing required fields';
                    break;
                }
                
                $mailbox_api_enabled = \Everymarket::isMailboxApiEnabled($mailbox);
                
                // Call Everymarket API to close/finalize CS request
                if (\Everymarket::isApiEnabled() || $mailbox_api_enabled) {
                    $api_result = \Everymarket::apiFinalizeCsRequest(
                        $request->order_number,
                        $request->order_request_id,
                        $request->note ?? null,
                        $request->user_email ?? null,
                        $mailbox
                    );
                    
                    if (!empty($api_result['error'])) {
                        $response['status'] = 'error';
                        $response['msg'] = $api_result['error'];
                        \Log::error('[Everymarket] Failed to close CS request: ' . $api_result['error']);
                    } else {
                        $response['status'] = 'success';
                        $response['msg'] = __('CS request closed successfully');

                        $user = auth()->user();
                        $conversation = Conversation::find($request->conversation_id);
                        // $conversation->unrequestedBy($user);
                        \Eventy::filter('conversation.set_custom_field', false, $conversation, 'Request Status', 'request_closed');
                        // Cache update will be handled by frontend after closing the request
                    }
                } else {
                    $response['status'] = 'error';
                    $response['msg'] = __('API is not enabled');
                }
                
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occured';
        }

        return \Response::json($response);
    }
}
