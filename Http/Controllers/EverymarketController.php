<?php

namespace Modules\Everymarket\Http\Controllers;

use App\Mailbox;
use App\Conversation;
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
