<?php

namespace Modules\Everymarket\Providers;

use Carbon\Carbon;
use App\Mailbox;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

// Module alias.
define('EM_MODULE', 'everymarket');

class EverymarketServiceProvider extends ServiceProvider
{
    const MAX_ORDERS = 5;

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        \Log::info('[Everymarket] EverymarketProvider boot() method called');
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        \Log::info('[Everymarket] Service Provider hooks() method called');

        // Add module's CSS file to the application layout.
        \Eventy::addFilter('stylesheets', function($styles) {
            $styles[] = \Module::getPublicPath(EM_MODULE).'/css/module.css';
            return $styles;
        });

        // Add module's JS file to the application layout.
        \Eventy::addFilter('javascripts', function($javascripts) {
            $javascripts[] = \Module::getPublicPath(EM_MODULE).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(EM_MODULE).'/js/module.js';
            return $javascripts;
        });

        // Add item to the mailbox menu.
        \Eventy::addAction('mailboxes.settings.menu', function($mailbox) {
            if (auth()->user()->isAdmin()) {
                echo \View::make('everymarket::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 34);

        // Section settings.
        \Eventy::addFilter('settings.sections', function($sections) {
            $sections[EM_MODULE] = ['title' => 'Everymarket', 'icon' => 'shopping-cart', 'order' => 550];

            return $sections;
        }, 35);

        // Section parameters.
        \Eventy::addFilter('settings.section_params', function($params, $section) {

            if ($section != EM_MODULE) {
                return $params;
            }

            $params['settings'] = [
                'everymarket.shop_domain' => [
                    'env' => 'EM_SHOP_DOMAIN',
                ],
                'everymarket.api_domain' => [
                    'env' => 'EM_API_DOMAIN',
                ],
                'everymarket.access_token' => [
                    'env' => 'EM_ACCESS_TOKEN',
                ],
                'everymarket.api_version' => [
                    'env' => 'EM_API_VERSION',
                ],
            ];

            // Validation.
            // $params['validator_rules'] = [
            //     'settings.everymarket\.shop_domain' => 'required',
            // ];

            return $params;
        }, 20, 2);

        // Settings view.
        \Eventy::addFilter('settings.view', function($view, $section) {
            if ($section != EM_MODULE) {
                return $view;
            } else {
                return 'everymarket::settings';
            }
        }, 20, 2);

        // Section settings.
        \Eventy::addFilter('settings.section_settings', function($settings, $section) {

            if ($section != EM_MODULE) {
                return $settings;
            }

            $settings['everymarket.shop_domain'] = config('everymarket.shop_domain');
            $settings['everymarket.api_domain'] = config('everymarket.api_domain');
            $settings['everymarket.access_token'] = config('everymarket.access_token');
            $settings['everymarket.api_version'] = config('everymarket.api_version');

            $mailboxes_enabled = \Auth::user()->mailboxesCanView(true);
            foreach ($mailboxes_enabled as $i => $mailbox) {
                if (!self::isMailboxApiEnabled($mailbox)) {
                    $mailboxes_enabled->forget($i);
                }
            }

            $settings['mailboxes_enabled'] = $mailboxes_enabled;

            return $settings;
        }, 20, 2);


        // Before saving settings.
        \Eventy::addFilter('settings.before_save', function($request, $section, $settings) {

            if ($section != EM_MODULE) {
                return $request;
            }

            if (!empty($request->settings['everymarket.api_domain'])) {
                $settings = $request->settings;

                $settings['everymarket.api_domain'] = preg_replace("/https?:\/\//i", '', $settings['everymarket.api_domain']);

                if (!\Helper::sanitizeRemoteUrl('https://'.$settings['everymarket.api_domain'])) {
                    $settings['everymarket.api_domain'] = '';
                }

                $request->merge([
                    'settings' => $settings,
                ]);
            }

            return $request;
        }, 20, 3);

        // After saving settings.
        \Eventy::addFilter('settings.after_save', function($response, $request, $section, $settings) {

            if ($section != EM_MODULE) {
                return $response;
            }

            if (self::isApiEnabled()) {
                // Check API credentials - create dummy customer object for testing
                $result = self::apiGetOrders('test@123.com');

                if (!empty($result['error'])) {
                    $request->session()->flash('flash_error', __('Error occurred connecting to the API').': '.$result['error']);
                } else {
                    $request->session()->flash('flash_success', __('Successfully connected to the API.'));
                }
            }

            return $response;
        }, 20, 4);

        // Show recent orders.
        \Eventy::addAction('conversation.after_prev_convs', function($customer, $conversation, $mailbox) {

            \Log::info('[Everymarket] Hook triggered - Customer: ' . ($customer ? $customer->id : 'null'));

            $load = false;
            $orders = [];

            if (!$customer) {
                \Log::info('[Everymarket] Hook: No customer object');
                return;
            }

            // Check all customer emails.
            $customer_emails = $customer->emails_cached->pluck('email');

            if (!count($customer_emails)) {
                \Log::info('[Everymarket] Hook: No customer emails found');
                return;
            }

            $global_enabled = \Everymarket::isApiEnabled();
            $mailbox_enabled = \Everymarket::isMailboxApiEnabled($mailbox);
            \Log::info('[Everymarket] Hook: Global enabled: ' . ($global_enabled ? 'yes' : 'no') . ', Mailbox enabled: ' . ($mailbox_enabled ? 'yes' : 'no'));

            if (!$global_enabled && !$mailbox_enabled) {
                \Log::info('[Everymarket] Hook: API not enabled, skipping widget');
                return;
            }

            // Initialize variables
            $orders = [];
            $load = true;
            $shop_url = '';
            $api_url = '';

            // Check cache for existing orders
            foreach ($customer_emails as $customer_email) {
                if (self::isMailboxApiEnabled($mailbox)) {
                    $settings = self::getMailboxEverymarketSettings($mailbox);
                    $shop_url = $settings['shop_domain'] ?? '';
                    $api_url = $settings['api_domain'] ?? '';
                    $cached_orders_json = \Cache::get('em_orders_'.$mailbox->id.'_'.$customer_email);
                } else {
                    $cached_orders_json = \Cache::get('em_orders_'.$customer_email);
                }

                if ($cached_orders_json && is_array($cached_orders_json)) {
                    $orders = $cached_orders_json;
                    $load = false;
                    break;
                }
            }

            // if (self::isApiEnabled()) {
            //     $result = self::apiGetOrders($customer_email);

            //     if (!empty($result['error'])) {
            //         \Log::error('[WooCommerce] '.$result['error']);
            //     } elseif (!empty($result['data'])) {
            //         $orders = $result['data'];

            //         // Cache orders for an hour.
            //         \Cache::put('wc_orders_'.$customer_email, $orders, now()->addMinutes(60));
            //     }
            // }

            echo \View::make('everymarket::partials/orders', [
                'orders'          => $orders,
                'customer_emails' => $customer_emails,
                'load'            => $load,
                'shop_url'        => \Everymarket::getSanitizedShopDomain($shop_url),
                'api_url'         => \Everymarket::getSanitizedApiDomain($api_url),
            ])->render();

        }, 12, 3);

    }

    public static function isApiEnabled()
    {
        return (config('everymarket.shop_domain') && config('everymarket.api_domain') && config('everymarket.access_token') && config('everymarket.api_version'));
    }

    public static function isMailboxApiEnabled($mailbox)
    {
        if (empty($mailbox) || empty($mailbox->everymarket)) {
            return false;
        }
        $settings = self::getMailboxEverymarketSettings($mailbox);

        return (!empty($settings['shop_domain']) && !empty($settings['api_domain']) && !empty($settings['access_token']) && !empty($settings['api_version']));
    }

    public static function getMailboxEverymarketSettings($mailbox)
    {
        return json_decode($mailbox->everymarket ?: '', true);
    }

    public static function formatDate($date)
    {
        $date_carbon = Carbon::parse($date);

        if (!$date_carbon) {
            return '';
        }

        return $date_carbon->format('M j, Y');
    }

    public static function getSanitizedShopDomain($shop_domain = '')
    {
        if (empty($shop_domain)) {
            $shop_domain = config('everymarket.shop_domain');
        }

        // Remove protocol if present
        $shop_domain = preg_replace("/https?:\/\//i", '', $shop_domain);

        // Remove trailing slash if present
        $shop_domain = rtrim($shop_domain, '/');

        return 'https://' . $shop_domain;
    }

    public static function getSanitizedApiDomain($api_domain = '')
    {
        if (empty($api_domain)) {
            $api_domain = config('everymarket.api_domain');
        }

        // Remove protocol if present
        $api_domain = preg_replace("/https?:\/\//i", '', $api_domain);

        // Remove trailing slash if present
        $api_domain = rtrim($api_domain, '/');

        return 'https://' . $api_domain;
    }

    public static function getApiInfo($mailbox)
    {
        // Get credentials (global or per-mailbox)
        if ($mailbox && \Everymarket::isMailboxApiEnabled($mailbox)) {
            $settings = self::getMailboxEverymarketSettings($mailbox);
            $api_domain = $settings['api_domain'];
            $access_token = $settings['access_token'];
            $api_version = $settings['api_version'];
            \Log::info('[Everymarket] Using mailbox settings - Domain: ' . $api_domain . ', Version: ' . $api_version . ', Token: ' . substr($access_token, 0, 10) . '...');
        } else {
            $api_domain = config('everymarket.api_domain');
            $access_token = config('everymarket.access_token');
            $api_version = config('everymarket.api_version');
            \Log::info('[Everymarket] Using global settings - Domain: ' . $api_domain . ', Version: ' . $api_version . ', Token: ' . substr($access_token, 0, 10) . '...');
        }

        $api_url = self::getSanitizedApiDomain($api_domain);
        \Log::info('[Everymarket] API URL: ' . $api_url);

        return ['api_url' => $api_url, 'access_token' => $access_token, 'api_version' => $api_version];
    }

    /**
     * Retrieve Everymarket orders for customer.
     * Uses customer ID caching to optimize API calls.
     */
    public static function apiGetOrders($customer_email, $mailbox = null)
    {
        $response = [
            'error' => '',
            'data' => [],
        ];

        // Get credentials (global or per-mailbox)
        $api_info = self::getApiInfo($mailbox);

        // Fetch orders by customer email (using customer-specific orders endpoint)
        $orders_url = $api_info["api_url"] . '/api/' . $api_info["api_version"] . '/orders?q[email_eq]=' . urlencode($customer_email) . '&q[state_eq]=complete&token=' . $api_info["access_token"];

        $orders_result = self::makeEverymarketApiCall($orders_url);

        if (!empty($orders_result['error'])) {
            return ['error' => $orders_result['error'], 'data' => []];
        }

        return ['error' => '', 'data' => $orders_result['data']['orders'] ?? []];
    }

    public static function apiGetCustomers($search_input, $mailbox = null)
    {
        $response = [
            'error' => '',
            'data' => [],
        ];

        $api_info = self::getApiInfo($mailbox);

        $customers_url = $api_info["api_url"] . '/api/' . $api_info["api_version"] . '/orders?q[state_eq]=complete&token=' . $api_info["access_token"];

        if(substr($search_input, 0, 2) == "EM" || substr($search_input, 0, 3) == "#EM") {
            if(substr($search_input, 0, 1) == "#") {
                $search_input = substr($search_input, 1);
            }
            $customers_url .= '&q[number_start]=' . $search_input;
        } elseif( strpos($search_input, "@") !== false) {
            $customers_url .= '&q[email_i_cont]=' . urlencode($search_input);
        } else {
            return ['error' => 'Please search by customer order or email', 'data' => []];
        }
        
        $customers_result = self::makeEverymarketApiCall($customers_url);

        if (!empty($customers_result['error'])) {
            return ['error' => $customers_result['error'], 'data' => []];
        }

        return ['error' => '', 'data' => $customers_result['data']['orders'] ?? []];
    }

    /**
     * Make Everymarket API call with authentication.
     */
    private static function makeEverymarketApiCall($url)
    {
        $response = ['error' => '', 'data' => []];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            \Helper::setCurlDefaultOptions($ch);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_USERAGENT, config('app.curl_user_agent') ?: 'FreeScout-Everymarket-Integration');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $json = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $json_decoded = json_decode($json, true);

            \Log::info('[Everymarket] API Response - Status: ' . $status_code . ', URL: ' . $url);
            \Log::info('[Everymarket] API Response Body: ' . substr($json, 0, 500));

            if ($status_code == 200) {
                $response['data'] = $json_decoded;
            } else {
                $response['error'] = 'HTTP Status Code: ' . $status_code . ' (' . self::errorCodeDescr($status_code) . ')';

                if (!empty($json_decoded['errors'])) {
                    $response['error'] .= ' | API Error: ' . json_encode($json_decoded['errors']);
                }
            }

        } catch (\Exception $e) {
            $response['error'] = $e->getMessage();
        }

        if ($response['error']) {
            $response['error'] .= ' | Requested resource: ' . $url;
        }

        return $response;
    }

    public static function errorCodeDescr($code)
    {
        switch ($code) {
            case 400:
                $descr = __('Bad request');
                break;
            case 401:
            case 403:
                $descr = __('Authentication error. Check your Admin API access token and ensure it has the correct permissions.');
                break;
            case 0:
            case 404:
                $descr = __('Shop not found. Verify your shop domain is correct (e.g., mystore.everymarket.com)');
                break;
            case 429:
                $descr = __('Everymarket API rate limit exceeded. Please try again in a moment.');
                break;
            case 500:
                $descr = __('Internal shop error');
                break;
            default:
                $descr = __('Unknown error');
                break;
        }

        return $descr;
    }


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('everymarket.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'everymarket'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/everymarket');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ],'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/everymarket';
        }, \Config::get('view.paths')), [$sourcePath]), 'everymarket');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ .'/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories()
    {
        if (! app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
