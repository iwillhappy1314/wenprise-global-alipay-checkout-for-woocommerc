<?php
/**
 *
 * trade_no | $order->get_transaction_id() :支付宝交易号，和商户订单号不能同时为空
 * out_trade_no | $order_id | $order->get_order_number() | $order->get_id(): 网站交易号
 *
 */

if ( ! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use Omnipay\Omnipay;

/**
 * Gateway class
 */
class Wenprise_Global_Alipay_Gateway extends \WC_Payment_Gateway
{

    /**
     * @var WC_Logger Logger 实例
     */
    public $log = false;

    /**
     * @var string
     */
    private $app_id = '';

    /**
     * @var string
     */
    private $sign_type = '';

    /**
     * @var string
     */
    private $md5_key = '';

    /**
     * @var string
     */
    private $rsa_key = '';

    /**
     * @var string
     */
    public $current_currency = '';

    /**
     * @var bool
     */
    public $multi_currency_enabled = false;

    /**
     * @var string
     */
    public $exchange_rate = '';

    /**
     * @var bool 日志是否启用
     */
    public $is_debug_mod = 'no';


    /**
     * 网关支持的功能
     *
     * @var array
     */
    public $supports = ['products', 'refunds'];

    /** @var string WC_API for the gateway - 作为回调 url 使用 */
    public $notify_url;

    function __construct()
    {

        // 支付方法的全局 ID
        $this->id = WENPRISE_GLOBAL_ALIPAY_WOOCOMMERCE_ID;

        // 支付网关页面显示的支付网关标题
        $this->method_title = __("Alipay", 'wprs-wc-global-alipay');

        // 支付网关设置页面显示的支付网关标题
        $this->method_description = __("Alipay Payment Gateway for WooCommerce", 'wprs-wc-global-alipay');

        // 前端显示的支付网关名称
        $this->title = __("Alipay", 'wprs-wc-global-alipay');

        // 支付网关标题
        $this->icon = apply_filters('omnipay_global_alipay_icon', WENPRISE_GLOBAL_ALIPAY_ASSETS_URL . "alipay.png");

        $this->supports = ['products', 'refunds'];

        $this->has_fields = false;

        $this->description = $this->get_option('description');

        $this->current_currency = get_option('woocommerce_currency');

        $this->multi_currency_enabled = in_array('woocommerce-multilingual/wpml-woocommerce.php',
                apply_filters('active_plugins', get_option('active_plugins'))) && get_option('icl_enable_multi_currency') == 'yes';

        $this->exchange_rate = $this->get_option('exchange_rate');

        // 转换设置为变量以方便使用
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        // 被 init_settings() 加载的基础设置
        $this->init_form_fields();

        $this->init_settings();

        // 设置是否应该重命名按钮。
        $this->order_button_text = apply_filters('woocommerce_alipay_button_text', __('Proceed to Alipay', 'wprs-wc-global-alipay'));

        // 保存设置
        if (is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        // below is the hook you need for that purpose
        add_action('woocommerce_receipt_' . $this->id, [$this, 'pay_for_order']);

        // 仪表盘通知
        add_action('admin_notices', [$this, 'requirement_checks']);

        // Hooks
        add_action('woocommerce_api_wprs-wc-global-alipay-return', [$this, 'listen_return_notify']);
        add_action('woocommerce_api_wprs-wc-global-alipay-notify', [$this, 'listen_return_notify']);
        add_action('woocommerce_api_wprs-wc-global-alipay-query-order', [$this, 'query_alipay_order']);

    }


    /**
     * 网关设置
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled'      => [
                'title'   => __('Enable / Disable', 'wprs-wc-global-alipay'),
                'label'   => __('Enable this payment gateway', 'wprs-wc-global-alipay'),
                'type'    => 'checkbox',
                'default' => 'no',
            ],
            'title'        => [
                'title'   => __('Title', 'wprs-wc-global-alipay'),
                'type'    => 'text',
                'default' => __('Alipay Global', 'wprs-wc-global-alipay'),
            ],
            'description'  => [
                'title'   => __('Description', 'wprs-wc-global-alipay'),
                'type'    => 'textarea',
                'default' => __('Pay securely using Alipay', 'wprs-wc-global-alipay'),
                'css'     => 'max-width:400px;',
            ],
            'app_id'       => [
                'title'       => __('App ID', 'wprs-wc-global-alipay'),
                'type'        => 'text',
                'description' => __('Enter your Alipay APPID. 开放平台密钥中的"APPID"，授权回调地址：', 'wprs-wc-global-alipay') . home_url('wc-api/wprs-wc-global-alipay-notify/'),
            ],
            'email'        => [
                'title'       => __('Email', 'wprs-wc-global-alipay'),
                'type'        => 'text',
                'description' => __('Enter your Alipay APPID. 开放平台密钥中的"APPID"，授权回调地址：', 'wprs-wc-global-alipay') . home_url('wc-api/wprs-wc-global-alipay-notify/'),
            ],
            'sign_type'    => [
                'title'       => __('Sign Type', 'wprs-wc-global-alipay'),
                'type'        => 'select',
                'options'     => [
                    'MD5' => 'MD5',
                    'RSA' => 'RSA',
                ],
                'description' => __('Enter your Alipay APPID. 开放平台密钥中的"APPID"，授权回调地址：', 'wprs-wc-global-alipay') . home_url('wc-api/wprs-wc-global-alipay-notify/'),
            ],
            'md5_key'      => [
                'title'       => __('MD5 Private Key', 'wprs-wc-global-alipay'),
                'type'        => 'text',
                'description' => __('Enter your Alipay secret key. (rsa_private_key.pem 文件的全部内容，创建订单时使用)', 'wprs-wc-global-alipay'),
            ],
            'rsa_key'      => [
                'title'       => __('RSA Private Key', 'wprs-wc-global-alipay'),
                'type'        => 'textarea',
                'description' => __('Enter your Alipay secret key. (rsa_private_key.pem 文件的全部内容，创建订单时使用)', 'wprs-wc-global-alipay'),
                'css'         => 'height:120px',
            ],
            'is_debug_mod' => [
                'title'       => __('Debug Mode', 'wprs-wc-wechatpay'),
                'label'       => __('Enable debug mod', 'wprs-wc-wechatpay'),
                'type'        => 'checkbox',
                'description' => sprintf(__('If checked, plugin will show program errors in frontend.', 'wprs-wc-global-alipay')),
                'default'     => 'no',
            ],
        ];

        if ( ! in_array($this->current_currency, ['RMB', 'CNY'])) {

            $this->form_fields[ 'exchange_rate' ] = [
                'title'       => __('Exchange Rate', 'wprs-wc-global-alipay'),
                'type'        => 'text',
                'description' => sprintf(__("Please set the %s against Chinese Yuan exchange rate, eg if your currency is US Dollar, then you should enter 6.19",
                    'wprs-wc-global-alipay'), $this->current_currency),
            ];

        }
    }


    /**
     * 管理选项
     */
    public function admin_options()
    { ?>

        <h3><?php echo ( ! empty($this->method_title)) ? $this->method_title : __('Settings', 'wprs-wc-global-alipay'); ?></h3>

        <?php echo ( ! empty($this->method_description)) ? wpautop($this->method_description) : ''; ?>

        <table class="form-table">
        <?php $this->generate_settings_html(); ?>
        </table><?php
    }


    /**
     * 检查是否满足需求
     *
     * @access public
     * @return void
     */
    function requirement_checks()
    {
        if ( ! in_array($this->current_currency, ['RMB', 'CNY']) && ! $this->exchange_rate) {
            echo '<div class="error"><p>' . sprintf(__('Alipay is enabled, but the store currency is ·not set to Chinese Yuan. Please <a href="%1s">set the %2s against the Chinese Yuan exchange rate</a>.',
                    'wprs-wc-global-alipay'), admin_url('admin.php?page=wc-settings&tab=checkout&section=wprs-wc-global-alipay#woocommerce_wprs-wc-global-alipay_exchange_rate'),
                    $this->current_currency) . '</p></div>';
        }
    }


    /**
     * 检查是否可用
     *
     * @return bool
     */
    function is_available()
    {

        $is_available = ('yes' === $this->enabled) ? true : false;

        if ($this->multi_currency_enabled) {
            if ( ! in_array(get_woocommerce_currency(), ['RMB', 'CNY']) && ! $this->exchange_rate) {
                $is_available = false;
            }
        } elseif ( ! in_array($this->current_currency, ['RMB', 'CNY']) && ! $this->exchange_rate) {
            $is_available = false;
        }

        if (wprs_is_wechat()) {
            $is_available = false;
        }

        return $is_available;
    }


    /**
     * 获取支付网关
     *
     * @return mixed
     */
    public function get_gateway()
    {

        /**
         * @var \omnipay\GlobalAlipay\WapGateway | \Omnipay\GlobalAlipay\WebGateway $gateway
         */

        if (wp_is_mobile()) {
            $gateway = Omnipay::create('GlobalAlipay_Wap');
        } else {
            $gateway = Omnipay::create('GlobalAlipay_Web');
        }

        $gateway->setSignType($this->sign_type);
        $gateway->setPartner($this->app_id);

        if ('MD5' == $this->sign_type) {
            $gateway->setKey($this->md5_key);
        } else {
            $gateway->setPrivateKey($this->rsa_key);
        }

        $gateway->setReturnUrl(WC()->api_request_url('wprs-wc-global-alipay-return'));
        $gateway->setNotifyUrl(WC()->api_request_url('wprs-wc-global-alipay-notify'));

        // dd($gateway);

        if ($this->is_debug_mod == 'yes') {
            $gateway->setEnvironment('sandbox');
        }

        return $gateway;
    }


    /**
     * WooCommerce 支付处理 function/method.
     *
     * @inheritdoc
     *
     * @param int $order_id
     *
     * @return mixed
     */
    public function process_payment($order_id)
    {
        $order    = wc_get_order($order_id);
        $order_no = $order->get_order_number();
        $total    = $this->get_order_total();

        $exchange_rate = floatval($this->get_option('exchange_rate'));
        if ($exchange_rate <= 0) {
            $exchange_rate = 1;
        }

        $total = round($total * $exchange_rate, 2);

        do_action('wenprise_woocommerce_alipay_before_process_payment');

        // 调用响应的方法来处理支付
        $gateway = $this->get_gateway();

        $order_data = apply_filters('woocommerce_wenprise_global_alipay_args',
            [
                'out_trade_no' => $order_no,
                'subject'      => __('Pay for order #', 'wprs-wc-global-alipay') . $order_no . __(' At ', 'wprs-wc-global-alipay') . get_bloginfo('name'),
                'body'         => __('Pay for order #', 'wprs-wc-global-alipay') . $order_no . __(' At ', 'wprs-wc-global-alipay') . get_bloginfo('name'),
                'total_fee'    => $total,
                'currency'     => 'USD',
            ]
        );

        // 生成订单并发送支付
        /** @var  $request \Omnipay\GlobalAlipay\Message\WebPurchaseRequest | \Omnipay\GlobalAlipay\Message\WapPurchaseRequest */
        $request = $gateway->purchase($order_data);

        /** @var \Omnipay\GlobalAlipay\Message\WapPurchaseResponse | \Omnipay\GlobalAlipay\Message\WebPurchaseResponse $response */
        $response = $request->send();

        // 生成订单后清空购物车，以免订单重复
        WC()->cart->empty_cart();

        do_action('woocommerce_WENPRISE_GLOBAL_ALIPAY_before_payment_redirect', $response);

        update_post_meta($order_id, '_gateway_payment_url', $response->getRedirectUrl());

        // 返回支付连接，由 Woo Commerce 跳转到支付宝支付
        if ($response->isRedirect()) {

            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];

        } else {
            $error = $response->getMessage();

            $order->add_order_note(sprintf("%s Payments Failed: '%s'", $this->method_title, $error));

            if ($this->is_debug_mod == 'yes') {
                $this->log($error);
                wc_add_notice($error, 'error');
            }

            return [
                'result'   => 'failure',
                'messages' => $response->getMessage(),
            ];
        }

    }


    /**
     * 订单支付页面
     *
     * @param $order_id
     */
    public function pay_for_order($order_id)
    {
        $order       = wc_get_order($order_id);
        $payment_url = get_post_meta($order_id, '_gateway_payment_url', true);

        wc_enqueue_js('
                jQuery("body").block({
                        message: "' . __('Thank you for your order. We are now redirecting you to Alipay to make payment.', 'wprs-wc-global-alipay') . '",
                        overlayCSS:
                        {
                            background: "#000",
                            opacity: 0.5
                        },
                        css: {
                            padding:        20,
                            color:          "#555",
                            border:         "3px solid #aaa",
                            backgroundColor:"#fff",
                            cursor:         "wait",
                            lineHeight:     "32px"
                        }
                    });
                jQuery("#js-rs-to-alipay").click();
            ');

        echo '<p>' . __('Thank you for your order. We are now redirecting you to Alipay to make payment.', 'wprs-wc-global-alipay') . '</p>';

        echo '<div id="js-alipay-confirm-modal" data-order_id="' . $order_id . '" class="rs-confirm-modal" style="display: none;">
                <div class="rs-modal">
                    <header class="rs-modal__header">
                      在线支付
                    </header>
                    <div class="rs-modal__content">
                        <form action="' . $payment_url . '" method="post" id="wprs-alipay-form">
                             <input type="submit" class="button" id="js-rs-to-alipay" value="' . __('Pay via Alipay', 'wprs-wc-global-alipay') . '" />
                             <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'wprs-wc-global-alipay') . '</a>
                        </form>
                        <div class="rs-alert rs-alert--warning">
                            请您在新打开的支付宝页面上完成支付，如果页面没有自动跳转，根据支付结果点击下面按钮查询。
                        </div>
                        <p>如果支付成功后，如果订单依然显示未支付、请联系网站客服进行处理。</p>
                    </div>
                    <footer class="rs-modal__footer">
                       <button type="button" id="js-alipay-success" class="button alt is-primary">支付成功</button>
                       <button type="button" id="js-alipay-fail" class="button">支付失败</button>
                    </footer>
                </div>
            </div>
            ';
    }


    /**
     * 监听支付网关同步返回信息
     * 处理支付接口异步返回的信息
     */
    public function listen_return_notify()
    {

        if (isset($_REQUEST[ 'out_trade_no' ]) && ! empty($_REQUEST[ 'out_trade_no' ])) {

            $order   = wc_get_order($_REQUEST[ 'out_trade_no' ]);
            $gateway = $this->get_gateway();
            $args    = stripslashes_deep(array_merge($_POST, $_GET));

            /**
             * 获取支付宝返回的参数
             */
            /** @var \Omnipay\GlobalAlipay\Message\CompletePurchaseRequest $request */
            $request = $gateway->completePurchase($args);

            try {

                /** @var \Omnipay\GlobalAlipay\Message\CompletePurchaseResponse $response */
                $response = $request->send();

                if ($response->isPaid()) {

                    // 添加订单备注
                    $this->complete_order($order, $_REQUEST[ 'trade_no' ]);

                    if ($_SERVER[ 'REQUEST_METHOD' ] == 'POST') {
                        echo "success";
                    } else {
                        wp_redirect($this->get_return_url($order));
                    }

                } else {

                    $error = $response->getMessage();

                    $order->add_order_note(sprintf("%s Payments Failed: '%s'", $this->method_title, $error));
                    wc_add_notice($error, 'error');
                    $this->log($error);

                    wp_redirect(wc_get_checkout_url());

                }

            } catch (\Exception $e) {

                $this->log($e->getMessage());
                wp_die($e->getMessage());

            }


        }

    }


    /**
     * 主动查询支付宝订单支付状态
     *
     * https://docs.open.alipay.com/api_1/alipay.trade.query
     */
    public function query_alipay_order()
    {
        $order_id = $_POST[ 'order_id' ];

        $gateway = $this->get_gateway();
        $order   = wc_get_order($order_id);

        $args = [
            'out_trade_no' => $order_id,
        ];

        /** @var \Omnipay\GlobalAlipay\Message\CompletePurchaseRequest $request */
        $request = $gateway->query($args);

        try {
            /** @var \Omnipay\GlobalAlipay\Message\CompletePurchaseResponse $response */
            $response = $request->send();

            if ($response->isPaid()) {

                $response_data = $response->getData()[ 'alipay_trade_query_response' ];
                $this->complete_order($order, $response_data[ 'trade_no' ]);

                wp_send_json_success($order->get_checkout_order_received_url());
            } else {
                wp_send_json_error($order->get_checkout_payment_url());
            }
        } catch (\Exception $e) {
            wp_send_json_error($order->get_checkout_payment_url());

            $this->log($e->getMessage());
            wp_die($e->getMessage());
        }

    }


    /**
     * 处理退款
     *
     * If the gateway declares 'refunds' support, this will allow it to refund.
     * a passed in amount.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount.
     * @param string $reason   Refund reason.
     *
     * @return boolean True or false based on success, or a WP_Error object.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $gateway = $this->get_gateway();
        $order   = wc_get_order($order_id);
        $total   = $this->get_order_total();

        $exchange_rate = floatval($this->get_option('exchange_rate'));
        if ($exchange_rate <= 0) {
            $exchange_rate = 1;
        }

        $total         = round($total * $exchange_rate, 2);
        $refund_amount = round($amount * $exchange_rate, 2);

        if ($refund_amount <= 0 || $refund_amount > $total) {
            false;
        }

        $args = [
            'out_trade_no'   => $order_id,
            'trade_no'       => $order->get_transaction_id(),
            'refund_amount'  => $refund_amount,
            'out_request_no' => date('YmdHis') . mt_rand(1000, 9999),
        ];

        /** @var \Omnipay\GlobalAlipay\Message\TradeRefundRequest $request */
        $request = $gateway->refund($args);

        try {
            /** @var \Omnipay\GlobalAlipay\Message\TradeRefundResponse $response */
            $response = $request->send();

            if ($response->isSuccessful()) {
                $order->add_order_note(
                    sprintf(__('Refunded %1$s', 'woocommerce'), $amount)
                );

                return true;
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }


    /**
     * 完成支付、支付网关验证成功后调用此方法
     *
     * @param $order    \WC_Order
     * @param $trade_no string
     */
    public function complete_order($order, $trade_no)
    {
        // 添加订单备注
        if ($order->get_status() == 'pending') {
            $order->add_order_note(sprintf(__('Alipay payment complete (Alipay ID: %s)', 'wprs-wc-global-alipay'), $trade_no));

            $order->payment_complete($trade_no);
        }

        delete_post_meta($order->get_id(), '_gateway_payment_url');
    }


    /**
     * Logger 辅助功能
     *
     * @param $message
     */
    public function log($message)
    {
        if ($this->is_debug_mod == 'yes') {
            if ( ! ($this->log)) {
                $this->log = new WC_Logger();
            }
            $this->log->add('woocommerce_wprs-wc-global-alipay', $message);
        }
    }

}
