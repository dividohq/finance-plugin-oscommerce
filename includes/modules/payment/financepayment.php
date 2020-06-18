<?php
/**
 * financepayment payment method class
 *
 * @package paymentMethod
 * @copyright Copyright 2003-2017 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Author: DrByte   Modified in v1.5.5f $
 */
/**
 * finance payment method class
 */
require_once DIR_FS_CATALOG. 'includes/languages/english/modules/payment/financepayment.php';
require_once __DIR__. '/../../../vendor/autoload.php';
require_once __DIR__. '/FinanceApi.php';




class financepayment {
    /**
     * $code determines the internal 'code' name used to designate "this" payment module
     *
     * @var string
     */
    var $code;
    /**
     * $title is the displayed name for this payment method
     *
     * @var string
     */
    var $title;
    /**
     * $description is a soft name for this payment method
     *
     * @var string
     */
    var $description;
    /**
     * $enabled determines whether this module shows or not... in catalog.
     *
     * @var boolean
     */
    var $enabled;

    /**
     * A string defining the current plugin version
     *
     * @var string
     */
    var $plugin_version;
    /**
     * vars
     */
    var $gateway_mode;
    var $reportable_submit_data;
    var $payment;
    var $auth_code;
    var $transaction_id;
    var $order_status;
    var $status_arr;
    var $awaiting_status_name;
    var $all_plans_config_keys;
    /**
     * @var string the currency enabled in this gateway's merchant account
     */
    private $gateway_currency;

    /**
     * @var FinanceApi class connecting to sdk
     */
    private $financeApi;
    /**
     * Constructor
     */
    function __construct() {
        global $order;

        $this->financeApi = new FinanceApi();
        $this->code = 'financepayment';
        $this->title = MODULE_PAYMENT_FINANCEPAYMENT_TEXT_ADMIN_TITLE; // Payment module title in Admin
        $this->description = MODULE_PAYMENT_FINANCEPAYMENT_TEXT_DESCRIPTION;
        $this->enabled = ((MODULE_PAYMENT_FINANCEPAYMENT_STATUS == 'True') ? true : false);
        $this->sort_order = MODULE_PAYMENT_FINANCEPAYMENT_SORT_ORDER;
        $this->awaiting_status_name = 'Awaiting Finance response';
        $this->plugin_version ='1.3.0';

        // only run api key validation check if it exists
        if(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY != "MODULE_PAYMENT_FINANCEPAYMENT_APIKEY" && !empty(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY) ){
            $this->checkApiKeyValidation();
        }

        $this->all_plans_config_keys = array();
        if(is_array($this->status_arr))
            foreach ($this->status_arr as $key => $value) {
                $this->all_plans_config_keys[] = 'MODULE_PAYMENT_FINANCEPAYMENT_PLAN_'.$key;
            }

    }


    function checkApiKeyValidation() {

        $plans = $this->getAllPlans();
        $plans_s = 'array(';
        $status_arr = array();
        foreach ($plans as $key => $value) {
            $status_arr[] = $value->text;
        }
        $this->status_arr = $status_arr;
        if(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY != MODULE_PAYMENT_FINANCEPAYMENT_APIKEY_HIDDEN && strpos($_SERVER['PHP_SELF'],'modules.php')) {
            if(!empty($status_arr)) {
                $this->removeOtherFields();
                $this->addOtherOptions($status_arr);
            } else {
                $this->removeOtherFields();
            }
            tep_db_query("UPDATE " . TABLE_CONFIGURATION . " SET configuration_value = '".MODULE_PAYMENT_FINANCEPAYMENT_APIKEY."' WHERE configuration_key = 'MODULE_PAYMENT_FINANCEPAYMENT_APIKEY_HIDDEN'");
        }
    }

    function awaitingStatusExists()
    {
        $res = tep_db_fetch_array(tep_db_query("SELECT orders_status_id FROM ".TABLE_ORDERS_STATUS." WHERE orders_status_name ='".$this->awaiting_status_name."'"));
        if(isset($res['orders_status_id']) && $res['orders_status_id'] > 0)
            return $res['orders_status_id'];
        else
            return false;
    }

    function CheckFinanceActiveCall($oID)
    {

        global $messageStack;
        if(MODULE_PAYMENT_FINANCEPAYMENT_USE_ACTIVATIONCALL != 'True') {
            return false;
        }

        $order = new order((int)$oID);
        $order_status = tep_db_fetch_array(tep_db_query(
            'SELECT o.`orders_status`,o.`payment_method`,fr.`order_status_id`,fr.`transaction_id` FROM `orders` o
        LEFT JOIN finance_requests fr ON(fr.`order_id` = '.$oID.')
        WHERE o.`orders_id` = "'.(int)$oID.'"
        AND transaction_id != ""'
        ));

        // get products for SDK
        $items = array(
            array(
                'name'     => "Order id: ". $order_status['transaction_id'],
                'quantity' => 1,
                'price'    => (int)((float)(substr($order->info['total'], 1)) * 100),
            ),
        );

        if ($order_status['payment_method'] != $this->title || $order_status['orders_status'] == $order_status['order_status_id']) {
            return;
        }
        if ($order_status['orders_status'] == MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS && !empty($order_status)) {

            $request_data = array(
                'application_id' => $order_status['transaction_id'],
                'amount' => (int)((float)(substr($order->info['total'], 1)) * 100),
                'items' => $items,
                'delivery_method' => $order->info['shipping_method'],
                'tracking_number' => '1234',
            );

            // use new sdk to make application request
            $this->financeApi->activateApplicationWithSDK($request_data);

            tep_db_query('UPDATE finance_requests SET `order_status_id` = "'.MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS.'" WHERE `order_id` = '.(int)$oID);


        }
    }

    function updatePlans($id,$plans)
    {
        $plans = explode(',', $plans);
        $plans_str = array();
        foreach ($plans as $key => $value) {
            $plans_str[] = $this->status_arr[$value];

        }
        $plans = implode(',', $plans_str);
        $result = tep_db_query("select * from `finance_product` where products_id = '".(int)$id."'");
        if (tep_db_num_rows($result)) {
            tep_db_query('UPDATE finance_product SET `plans` = "'.$plans.'" WHERE `products_id` = '.(int)$id);
        } else {
            tep_db_query('INSERT INTO finance_product (`plans`,`products_id`) VALUES ("'.$plans.'","'.$id.'")');
        }
        return true;
    }

    /**
     * compute HMAC-MD5
     *
     * @param string $key
     * @param string $data
     * @return string
     */
    function hmac ($key, $data)
    {
    }
    // end code from lance (resume authorize.net code)

    /**
     * Inserts the hidden variables in the HTML FORM required for SIM
     * Invokes hmac function to calculate fingerprint.
     *
     * @param string $loginid
     * @param string $txnkey
     * @param float $amount
     * @param string $sequence
     * @param float $currency
     * @return string
     */
    function InsertFP ($loginid, $txnkey, $amount, $sequence, $currency = "") {
    }
    // end authorize.net-provided code

    // class methods
    /**
     * Calculate zone matches and flag settings to determine whether this module should display to customers or not
     */
    function update_status() {
    }
    /**
     * JS validation which does error-checking of data-entry if this module is selected for use
     * (Number, Owner Lengths)
     *
     * @return string
     */
    function javascript_validation() {
        return false;
    }
    /**
     * Display Credit Card Information Submission Fields on the Checkout Payment Page
     *
     * @return array
     */
    function selection() {
        global $order;
        if(empty($this->getCartPlans($order,true)))
            return false;
        if ($this->gateway_mode == 'offsite') {
            $selection = array('id' => $this->code,
                'module' => $this->title);
        } else {

            $financeEnv= $this->financeApi->getFinanceEnv();
            $apiKey = $this->getJsKey();
            $widgetAmount = round($order->info["total"] * 100);
            $selection = array('id' => $this->code,
                'module' => '<span class="financepayment_title">'.MODULE_PAYMENT_FINANCEPAYMENT_PAYMENT_TITLE.'</span><br>
                         <script>
                         var '.$financeEnv.'Key = "'.$this->getJsKey().'";
                         $(document).ready(function() {
                          $(\'input[name="payment"]\').on("click",function() {
                            showPop($(this));
                         })
                           setTimeout(function() {
                              showPop($(\'input[name="payment"]:checked\'));
                           })
                         })
                         function showPop(ths){
                            if(ths.val() == "financepayment") {
                              $("#'.$financeEnv.'-checkout").slideDown();
                            } else {
                              $("#'.$financeEnv.'-checkout").slideUp();
                            }
                         }
                         function selectRowEffect(object, buttonSelect) {
                            if (!selected) {
                              if (document.getElementById) {
                                selected = document.getElementById(\'defaultSelected\');
                              } else {
                                selected = document.all[\'defaultSelected\'];
                              }
                            }

                            if (selected) selected.className = \'moduleRow\';
                            object.className = \'moduleRowSelected\';
                            selected = object;

                          // one button is not an array
                            if (document.checkout_payment.payment[0]) {
                              document.checkout_payment.payment[buttonSelect].checked=true;
                            } else {
                              document.checkout_payment.payment.checked=true;
                            }
                            showPop($(\'input[name="payment"]:checked\'));
                          }
                         </script>
                         <input type="hidden" name="divido_total" value="'.$order->info["total"].'">
                         <div id="'.$financeEnv.'-checkout" style="display:none;">
    <div data-calculator-widget data-mode="calculator" data-api-key="'.$apiKey.'" data-amount="'.$widgetAmount.'" data-plans="'.$this->getCartPlans($order,true).'"></div></div>
    <script type="text/javascript" src="https://cdn.divido.com/widget/v3/'.$financeEnv.'.calculator.js"></script>
    ',
            );
        }
        return $selection;
    }
    /**
     * Evaluates the Credit Card Type for acceptance and the validity of the Credit Card Number & Expiration Date
     *
     */
    function pre_confirmation_check() {
    }
    /**
     * Display Credit Card Information on the Checkout Confirmation Page
     *
     * @return array
     */
    function confirmation() {
        global $order , $currencies;
        if (isset($_POST['payment'])) {
            $total_dicount = $GLOBALS['ot_coupon']->deduction+$GLOBALS['ot_group_pricing']->deduction+$GLOBALS['ot_gv']->deduction;
            $_SESSION['finance_deposit'] = $_POST['divido_deposit'];
            $_SESSION['finance_plan'] = $_POST['divido_plan'];
            $_SESSION['finance_total'] = $_POST['divido_total'];
            $_SESSION['total_dicount'] = $total_dicount;
            $_SESSION['finance_total'] = ($_SESSION['finance_total'] == $order->info['total']) ? $_SESSION['finance_total']-$total_dicount : $_SESSION['finance_total'];
            $confirmation = array('title' => MODULE_PAYMENT_FINANCEPAYMENT_TEXT_DETAILS,
                'fields' => array(array('title' => MODULE_PAYMENT_FINANCEPAYMENT_TEXT_DEPOSIT,
                    'field' => $_SESSION['finance_deposit']
                ),
                    array('title' => MODULE_PAYMENT_FINANCEPAYMENT_TEXT_PLAN,
                        'field' => $this->getPlanTextById($_SESSION['finance_plan'])
                    ),
                    array('title' => MODULE_PAYMENT_FINANCEPAYMENT_TEXT_TOTAL,
                        'field' => $currencies->format($_SESSION['finance_total'], true, $order->info['currency'], $order->info['currency_value'])
                    ),
                )
            );
        } else {
            $confirmation = array(); //array('title' => $this->title);
        }
        return $confirmation;
    }
    /**
     * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
     * This sends the data to the payment gateway for processing.
     * (These are hidden fields on the checkout confirmation page)
     *
     * @return string
     */
    function process_button() {
    }
    /**
     * Store the CC info to the order and process any results that come back from the payment gateway
     *
     */
    function before_process() {
        global $messageStack, $order;
        $this->payment = $_POST;
        if (!(isset($_SESSION['finance_deposit']) && isset($_SESSION['finance_plan']) && isset($_SESSION['finance_total']))) {
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
        if ($this->enabled == false) {
            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_FINANCEPAYMENT_TEXT_MODULE_DISABLED , 'error');
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
        if ((int)$order->info['total'] != (int)$_SESSION['finance_total']) {
            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_FINANCEPAYMENT_TEXT_PAYMENT_MISMATCH , 'error');
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }

        $response = $this->getConfirmation();
        if ($response['status']) {
            header("HTTP/1.1 302 Object Moved");
            tep_redirect($response['url']);
        } else {
            $messageStack->add_session('checkout_payment', $response['message'], 'error');
            tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }
    }

    function getConfirmation()
    {
        // var_dump("get confirmation");
        global $order, $order_totals;

        $deposit = $_SESSION['finance_deposit'];
        $finance = $_SESSION['finance_plan'];
        $cart = $_SESSION['cart'];
        $customer = $order->customer;
        $country = $order->billing['country']['iso_code_2'];

        $language = $_SESSION['languages_code'];
        $currency = $_SESSION['currency'];

        $cart_id = $_SESSION['cartID'];

        $firstName = $customer['firstname'];
        $lastName = $customer['lastname'];
        $email = $customer['email_address'];
        $telephone = $customer['telephone'];
        $address = array([
            'text' => $customer['street_address']. " " . $customer['suburb'] . " " . $customer['suburb'] . " " .$customer['city']. " " .$customer['postcode']
        ]);

        $products  = array();
        foreach ($order->products as $product) {
            $products[] = array(
                //     'type' => 'product',
                'name' => $product['name'],
                'quantity' => (int)$product['qty'],
                'price'  => round($product['final_price'] * 100),
            );
        }

        $sub_total = $_SESSION['finance_total'];

        $shiphandle = round($order->info['shipping_cost'] * 100);
        $disounts = -round($_SESSION['total_discount'] * 100);


        $products[] = array(
            //  'type'     => 'product',
            'name'     => 'Shipping & Handling',
            'quantity' => 1,
            'price'    => $shiphandle,
        );

        $products[] = array(
            'name'     => 'Discount',
            'quantity' => 1,
            'price'    => $disounts,
        );

        $response_url = tep_href_link('finance_main_handler.php', 'type=financepayment&response=1', 'SSL', true,true, true);
        $redirect_url = tep_href_link('finance_main_handler.php', 'type=financepayment&confirmation=1&cartID='.$cart_id, 'SSL', true,true, true);
        $checkout_url = tep_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false);
        $order->info['payment_method'] = $this->title;
        $order->info['payment_module_code'] = $this->code;
        $order_id = $this->create_order();
        $order->info['order_status'] = MODULE_PAYMENT_FINANCEPAYMENT_AWAITING_STATUS;
        $salt = uniqid('', true);
        $hash = hash('sha256', $cart_id.$salt);

        $request_data = array(
            'merchant' => MODULE_PAYMENT_FINANCEPAYMENT_APIKEY,
            'deposit_amount'  => round($deposit),
            'finance'  => $finance,
            'country'  => $country,
            'language' => $language,
            'currency' => $currency,
            'metadata' => array(
                'order_id' => $order_id,
                'cart_id' => $cart_id,
                'ecom_platform'         => 'oscommerce',
                'ecom_platform_version' => PROJECT_VERSION,
                'ecom_base_url'         => htmlspecialchars_decode($checkout_url),
                'plugin_version'        => $this->plugin_version
            ),
            'customer' => array(
                'firstName'    => $firstName,
                'lastName'     => $lastName,
                'email'         => $email,
                'phoneNumber'  => $telephone,
                'addresses' => $address
            ),
            'products' => $products,
            'response_url' => htmlspecialchars_decode($response_url),
            'redirect_url' => htmlspecialchars_decode($redirect_url),
            'checkout_url' => htmlspecialchars_decode($checkout_url),
        );

        try{
            $response = $this->financeApi->createAnApplication($request_data);
            $_SESSION['order_id'] = $order_id;
            $this->saveHash($cart_id,$hash,$sub_total,$order_id,$response['result_id']);
            unset($_SESSION['cartID']);
            unset($_SESSION['cart']);
            return array(
                'status' => true,
                'url'    => $response['redirect_url']
            );
        }
        catch(\Exception $e){
            return  array(
                'status'  => false,
                'message' => $e->getMessage()
            );
        }
    }

    public function saveHash($cart_id, $salt, $total,$order_id = '',$transaction_id = '')
    {
        $extra = '';
        if($order_id == '') {
            $result = tep_db_query(
                'SELECT * FROM `finance_requests` WHERE `cart_id` = "'.(int)$cart_id.'"'
            );
            $where = 'WHERE `cart_id` = '.(int)$cart_id;
        } else {
            $result = tep_db_query(
                'SELECT * FROM `finance_requests` WHERE `order_id` = "'.(int)$order_id.'"'
            );
            $where = 'WHERE `order_id` = '.(int)$order_id;
            if($transaction_id != '')
                $extra = ' ,`transaction_id` = "'.$transaction_id.'" ';

        }

        if (tep_db_num_rows($result)) {
            tep_db_query('UPDATE finance_requests SET `hash` = "'.$salt.'",
          `total` = "'.$total.'"'.$extra.''.$where) ;
        } else {
            tep_db_query('INSERT INTO finance_requests (`hash`,`total`,`cart_id`,`order_id`,`transaction_id`) VALUES ("'.$salt.'","'.$total.'","'.(int)$cart_id.'","'.$order_id.'","'.$transaction_id.'")');
        }
    }

    /**
     * Post-processing activities
     *
     * @return boolean
     */
    function after_process() {
    }

    function getConfigValue($key)
    {
        $res = tep_db_fetch_array(tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = '".$key."'"));
        return $res['configuration_value'];
    }
    /**
     * Check to see whether module is installed
     *
     * @return boolean
     */
    function check() {
        if (!isset($this->_check)) {
            $check_query = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_FINANCEPAYMENT_STATUS'");
            $this->_check = tep_db_num_rows($check_query);
        }
        return $this->_check;
    }
    /**
     * Install the payment module and its configuration settings
     *
     */
    function install() {
        global $messageStack;
        if (defined('MODULE_PAYMENT_FINANCEPAYMENT_STATUS')) {
            $messageStack->add_session('financepayment module already installed.', 'error');
            tep_redirect(tep_href_link(FILENAME_MODULES, 'set=payment&module=financepayment', 'NONSSL'));
            return 'failed';
        }
        tep_db_query('CREATE TABLE IF NOT EXISTS `finance_product` (`id_finance_product` int(11) NOT NULL AUTO_INCREMENT, `products_id` int(11) NOT NULL, `display` text NOT NULL, `plans` text NOT NULL, PRIMARY KEY  (`id_finance_product`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8');

        tep_db_query('CREATE TABLE IF NOT EXISTS `finance_requests` ( `id_finance_requests` int(11) NOT NULL AUTO_INCREMENT, `cart_id` int(11) NOT NULL, `hash` text NOT NULL, `total` text NOT NULL, `order_id` TEXT NOT NULL, `transaction_id` Text NOT NULL,`order_status_id` int(11) NOT NULL, PRIMARY KEY  (`id_finance_requests`) ) ENGINE= InnoDB DEFAULT CHARSET=utf8');

        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_FINANCEPAYMENT_SORT_ORDER', '0', 'Sort order of displaying payment options to the customer. Lowest is displayed first.', '6', '0', now())");
        //payment status MODULE_PAYMENT_FINANCEPAYMENT_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Finance Payment Module', 'MODULE_PAYMENT_FINANCEPAYMENT_STATUS', 'True', 'Do you want to accept Finance Payment payments?', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");

        //API key MODULE_PAYMENT_FINANCEPAYMENT_APIKEY
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('API KEY', 'MODULE_PAYMENT_FINANCEPAYMENT_APIKEY', '', 'The API KEY used for the Finance payment service', '6', '0', now())");
        //API key hidden field MODULE_PAYMENT_FINANCEPAYMENT_APIKEY
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,use_function,set_function, date_added) values ('', 'MODULE_PAYMENT_FINANCEPAYMENT_APIKEY_HIDDEN', '', '', '6', '0','financepayment->hiddenField','tep_draw_hidden_field(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY_HIDDEN, ', now())");
    }

    function addOtherOptions($plans)
    {
        // Activation status call MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS
        $res = tep_db_fetch_array(tep_db_query('SELECT * FROM '.TABLE_CONFIGURATION.' WHERE configuration_key ="MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS"'));
        if(!empty($res))
            return false;
        $awaiting_status_id = $this->awaitingStatusExists();
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS', '3', 'Order status to make Finance Payment activation call', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //Calculator MODULE_PAYMENT_FINANCEPAYMENT_USE_ACTIVATIONCALL
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,set_function, date_added) values ('Enable/Disable activation call functionality', 'MODULE_PAYMENT_FINANCEPAYMENT_USE_ACTIVATIONCALL', 'True', 'Use Finance activation call functionality', '6', 'False', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");

        //payment title MODULE_PAYMENT_FINANCEPAYMENT_PAYMENT_TITLE
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Finance Payment module\'s title', 'MODULE_PAYMENT_FINANCEPAYMENT_PAYMENT_TITLE', 'Pay in instalments', 'The Title used for the Finance payment service', '6', '0', now())");

        //Finance plan MODULE_PAYMENT_FINANCEPAYMENT_PLAN
        foreach ($plans as $key => $value) {
            tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,set_function, date_added) values ('Finance plan', 'MODULE_PAYMENT_FINANCEPAYMENT_PLAN_".$key."', 'True', '".$value."', '6', '0','tep_cfg_select_option(array(\'True\', \'False\'), ', now())");
        }

        //Widget on product page MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_WIDGET
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,set_function, date_added) values ('Widget on product page', 'MODULE_PAYMENT_FINANCEPAYMENT_WIDGET', 'True', 'Show Finance payment widget on product page', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");

        //Calculator MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_CALCULATOR
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,set_function, date_added) values ('Calculator on product page', 'MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_CALCULATOR', 'True', 'Show Finance payment calculator on product page', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");

        //Require whole cart MODULE_PAYMENT_FINANCEPAYMENT_WHOLE_CART
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,set_function, date_added) values ('Require whole cart to be available on finance', 'MODULE_PAYMENT_FINANCEPAYMENT_WHOLE_CART', 'False', 'Require whole cart to be available on finance', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");

        //Minimum cart value MODULE_PAYMENT_FINANCEPAYMENT_MIN_CART
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Cart amount minimum', 'MODULE_PAYMENT_FINANCEPAYMENT_MIN_CART', '0', 'Cart amount minimum for the Finance payment module', '6', '0', now())");

        //Product selection MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_SELECTION
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,set_function, date_added) values ('Product Selection', 'MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_SELECTION', 'All Products', 'Product Selection', '6', '0', 'tep_cfg_select_option(array(\'All Products\',\'Selected Products\', \'Products above minimum value\',), ', now())");

        //Product selection min product for product above MODULE_PAYMENT_FINANCEPAYMENT_MIN_PRODUCT
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Product price minimum', 'MODULE_PAYMENT_FINANCEPAYMENT_MIN_PRODUCT', '0', 'Product price minimum', '6', '0', now())");

        //Accepted status MODULE_PAYMENT_FINANCEPAYMENT_ACCEPTED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('ACCEPTED', 'MODULE_PAYMENT_FINANCEPAYMENT_ACCEPTED_STATUS', '2', 'Status for Accepted', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //DEPOSITE-PAID status MODULE_PAYMENT_FINANCEPAYMENT_DEPOSIT-PAID_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('DEPOSIT-PAID', 'MODULE_PAYMENT_FINANCEPAYMENT_DEPOSIT-PAID_STATUS', '".$awaiting_status_id."', 'Status for Deposite-paid', '6', '0','tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //SIGNED status MODULE_PAYMENT_FINANCEPAYMENT_SIGNED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('SIGNED', 'MODULE_PAYMENT_FINANCEPAYMENT_SIGNED_STATUS', '4', 'Status for SIGNED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //READY status MODULE_PAYMENT_FINANCEPAYMENT_READY_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('READY', 'MODULE_PAYMENT_FINANCEPAYMENT_READY_STATUS', '2', 'Status for READY', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //ACTION-LENDER status MODULE_PAYMENT_FINANCEPAYMENT_ACTION-LENDER_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('ACTION-LENDER', 'MODULE_PAYMENT_FINANCEPAYMENT_ACTION-LENDER_STATUS', '2', 'Status for ACTION-LENDER', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //CANCELLED status MODULE_PAYMENT_FINANCEPAYMENT_CANCELLED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('CANCELLED', 'MODULE_PAYMENT_FINANCEPAYMENT_CANCELLED_STATUS', '1', 'Status for CANCELLED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //COMPLETED status MODULE_PAYMENT_FINANCEPAYMENT_COMPLETED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function,date_added) values ('COMPLETED', 'MODULE_PAYMENT_FINANCEPAYMENT_COMPLETED_STATUS', '2', 'Status for COMPLETED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //DECLINED status MODULE_PAYMENT_FINANCEPAYMENT_DECLINED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('DECLINED', 'MODULE_PAYMENT_FINANCEPAYMENT_DECLINED_STATUS', '1', 'Status for DECLINED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //DEFERRED status MODULE_PAYMENT_FINANCEPAYMENT_DEFERRED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('DEFERRED', 'MODULE_PAYMENT_FINANCEPAYMENT_DEFERRED_STATUS', '1', 'Status for DEFERRED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //REFERRED status MODULE_PAYMENT_FINANCEPAYMENT_REFERRED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('REFERRED', 'MODULE_PAYMENT_FINANCEPAYMENT_REFERRED_STATUS', '1', 'Status for REFERRED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");

        //FULFILLED status MODULE_PAYMENT_FINANCEPAYMENT_FULFILLED_STATUS
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('FULFILLED', 'MODULE_PAYMENT_FINANCEPAYMENT_FULFILLED_STATUS', '2', 'Status for FULFILLED', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
        $this->addFinanceAwaitingStatus();
    }

    function addFinanceAwaitingStatus()
    {
        $languages = tep_get_languages();
        $languages = $languages;
        $awaiting_status_id = $this->awaitingStatusExists();
        if (!$awaiting_status_id) {
            for ($i=0, $n=sizeof($languages); $i<$n; $i++) {
                $language_id = $languages[$i]['id'];

                $sql_data_array = array('orders_status_name' => tep_db_prepare_input($this->awaiting_status_name));
                if (empty($orders_status_id)) {
                    $next_id = tep_db_fetch_array(tep_db_query("select max(orders_status_id)
                                           as orders_status_id from " . TABLE_ORDERS_STATUS . ""));

                    $awaiting_status_id = $next_id['orders_status_id'] + 1;
                }

                $insert_sql_data = array('orders_status_id' => $awaiting_status_id,
                    'language_id' => $language_id);

                $sql_data_array = array_merge($sql_data_array, $insert_sql_data);

                tep_db_perform(TABLE_ORDERS_STATUS, $sql_data_array);
                //READY status MODULE_PAYMENT_FINANCEPAYMENT_READY_STATUS
            }
        }
        tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order,  set_function, use_function, date_added) values ('Awaiting status', 'MODULE_PAYMENT_FINANCEPAYMENT_AWAITING_STATUS', '".$awaiting_status_id."', 'Status for AWAITING', '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
    }

    function removeAwaitingStatus()
    {
        tep_db_query("delete from " . TABLE_ORDERS_STATUS . "
                      where orders_status_id = '" . tep_db_input($this->awaiting_status_name) . "'");
    }
    /**
     * Remove the module and all its settings
     *
     */
    function removeOtherFields()
    {
        $this->removeAwaitingStatus();
        tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_AWAITING_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_PAYMENT_TITLE','MODULE_PAYMENT_FINANCEPAYMENT_WIDGET','MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_CALCULATOR','MODULE_PAYMENT_FINANCEPAYMENT_WHOLE_CART', 'MODULE_PAYMENT_FINANCEPAYMENT_MIN_CART', 'MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_SELECTION','MODULE_PAYMENT_FINANCEPAYMENT_MIN_PRODUCT', 'MODULE_PAYMENT_FINANCEPAYMENT_ACCEPTED_STATUS', 'MODULE_PAYMENT_FINANCEPAYMENT_DEPOSIT-PAID_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_SIGNED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_READY_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_ACTION-LENDER_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_CANCELLED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_COMPLETED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_DECLINED_STATUS', 'MODULE_PAYMENT_FINANCEPAYMENT_DEFERRED_STATUS', 'MODULE_PAYMENT_FINANCEPAYMENT_REFERRED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_FULFILLED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_USE_ACTIVATIONCALL')");
        tep_db_query("DELETE FROM ".TABLE_CONFIGURATION." WHERE `configuration_key` LIKE '%MODULE_PAYMENT_FINANCEPAYMENT_PLAN%'");
    }

    /**
     * Remove the module and all its settings
     *
     */
    function remove() {
        $this->removeAwaitingStatus();
        tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function updateOrderStatus($order_id,$new_order_status,$status_comment = null,$trans_id = null)
    {

        if(!$order_id > 0 || !$new_order_status > 0)
            return false;
        $sql_data_array = array('orders_id' => $order_id,
            'orders_status_id' => (int)$new_order_status,
            'date_added' => 'now()',
            'comments' => $status_comment,
            'customer_notified' => 0
        );
        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
        tep_db_query("update " . TABLE_ORDERS  . "
                  set orders_status = '" . (int)$new_order_status . "'
                  where orders_id = '" . (int)$order_id . "'");
        $this->CheckFinanceActiveCall($order_id);
        return true;

    }
    /**
     * Internal list of configuration keys used for configuration of the module
     *
     * @return array
     */
    function keys() {
        $keys = array('MODULE_PAYMENT_FINANCEPAYMENT_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_APIKEY','MODULE_PAYMENT_FINANCEPAYMENT_SORT_ORDER');
        $keys_1 = array('MODULE_PAYMENT_FINANCEPAYMENT_PAYMENT_TITLE','MODULE_PAYMENT_FINANCEPAYMENT_USE_ACTIVATIONCALL','MODULE_PAYMENT_FINANCEPAYMENT_ACTIVATED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_WIDGET','MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_CALCULATOR','MODULE_PAYMENT_FINANCEPAYMENT_WHOLE_CART','MODULE_PAYMENT_FINANCEPAYMENT_MIN_CART','MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_SELECTION','MODULE_PAYMENT_FINANCEPAYMENT_MIN_PRODUCT','MODULE_PAYMENT_FINANCEPAYMENT_AWAITING_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_ACCEPTED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_DEPOSIT-PAID_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_SIGNED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_READY_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_ACTION-LENDER_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_CANCELLED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_COMPLETED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_DECLINED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_DEFERRED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_REFERRED_STATUS','MODULE_PAYMENT_FINANCEPAYMENT_FULFILLED_STATUS');
        if(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY == '')
            return $keys;
        return array_merge($keys,$this->all_plans_config_keys,$keys_1);
    }

    //get Finance plans for payment
    public function getGlobalSelectedPlans()
    {
        $all_plans = $this->getAllPlans();

        $plans = array();
        foreach ($all_plans as $plan) {
            if (in_array($plan->text, $this->status_arr)) {
                $key = array_search($plan->text,$this->status_arr);
                if($this->getConfigValue("MODULE_PAYMENT_FINANCEPAYMENT_PLAN_".$key) == 'True') {
                    $plans[$plan->id] = $plan;
                }
            }
        }

        return $plans;
    }

    public function getSelectedPlansString($products_id,$product_price = 0)
    {
        $plans = $this->getProductPlans($product_price,$products_id);
        $plans_str = array();
        foreach ($plans as $key => $value) {
            $plans_str[] = $value->id;
        }
        return implode(',', $plans_str);
    }
    public function getJsKey()
    {
        $key_parts = explode('.', MODULE_PAYMENT_FINANCEPAYMENT_APIKEY);
        $js_key    = strtolower(array_shift($key_parts));
        return $js_key;
    }

    public function getPlans($default_plans = false)
    {
        if ($default_plans) {
            $plans = $this->getGlobalSelectedPlans();
        } else {
            $plans = $this->getAllPlans();
        }

        return $plans;
    }
    public function getPlanTextById($id)
    {
        if($id == '')
            return '';
        $plan = $this->getGlobalSelectedPlans();
        return isset($plan[$id]) ? $plan[$id]->text : '';
    }

    public function getAllPlans()
    {
        if (!MODULE_PAYMENT_FINANCEPAYMENT_APIKEY) {
            return array();
        }

        $plans = $this->financeApi->getAllFinancePlansFromSDK();

        $plans_plain = array();
        foreach ($plans as $plan) {
            $plan_copy = new stdClass();

            $plan_copy->id                 = $plan->id;
            $plan_copy->text                = $plan->description;
            $plan_copy->country            = $plan->country;
            $plan_copy->min_amount         = $plan->min_amount;
            $plan_copy->min_deposit        = $plan->min_deposit;
            $plan_copy->max_deposit        = $plan->max_deposit;
            $plan_copy->interest_rate      = $plan->interest_rate;
            $plan_copy->deferral_period    = $plan->deferral_period;
            $plan_copy->agreement_duration = $plan->agreement_duration;

            $plans_plain[$plan->id] = $plan_copy;
        }

        return $plans_plain;
    }
    public function getProductPlans($product_price, $products_id)
    {
        if(!$this->enabled)
            return array();
        $settings = $this->getProductSettings($products_id);
        $product_selection = MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_SELECTION;
        $price_threshold   = MODULE_PAYMENT_FINANCEPAYMENT_MIN_PRODUCT;

        $plans = $this->getPlans(true);
        if ($product_selection == 'All Products') {
            return $plans;
        }

        if ($product_selection == 'Products above minimum value' && $price_threshold > $product_price) {
            return null;
        } elseif ($product_selection == 'Products above minimum value') {
            return $plans;
        }

        $available_plans = $this->getPlans(false);
        $selected_plans  = $settings['plans'];
        if(count($selected_plans) > 0) {
            $plans = array();
            foreach ($available_plans as $plan) {
                if (strpos(' '.$selected_plans,$plan->text)) {
                    $plans[$plan->id] = $plan;
                }
            }
        }

        if (empty($plans)) {
            return null;
        }

        return $plans;
    }

    public function getCartPlans($order,$string = false)
    {
        $plans = array();
        if(!$this->enabled)
            return $plans;
        $s_plans = array();
        if($order->delivery != $order->billing)
            return $s_plans;
        foreach ($order->products as $product) {
            $product_plans = $this->getProductPlans($product['final_price']*$product['qty'], $product['id']);
            if ($product_plans) {
                $plans = array_merge($plans, $product_plans);
            }else if(MODULE_PAYMENT_FINANCEPAYMENT_WHOLE_CART == 'True' && !$product_plans) {
                return array();
            }
        }
        if((int)MODULE_PAYMENT_FINANCEPAYMENT_MIN_CART > $order->info['total']) {
            return array();
        }
        if($string) {
            foreach ($plans as $key => $value) {
                $s_plans[] = $value->id;
            }
            return implode(',', $s_plans);
        }
        return $plans;
    }

    public static function getProductSettings($products_id)
    {
        $query = "select * from `finance_product` where products_id = '".(int)$products_id."'";
        return tep_db_fetch_array(tep_db_query($query));
    }
    /**
     * Calculate validity of response
     */
    function calc_md5_response($trans_id = '', $amount = '') {
    }
    /**
     * Used to do any debug logging / tracking / storage as required.
     */
    function _debugActions($response, $mode, $order_time= '', $sessID = '') {
    }
    /**
     * Check and fix table structure if appropriate
     */
    function tableCheckup() {

    }

    function create_order()
    {
        global $language, $order,$payment,$shipping,$order_totals,$currencies;
        include(DIR_WS_LANGUAGES . $language . '/' . FILENAME_CHECKOUT_PROCESS);
        $order = new order;

        // Stock Check
        $any_out_of_stock = false;
        if (STOCK_CHECK == 'true') {
            for ($i=0, $n=sizeof($order->products); $i<$n; $i++) {
                if (tep_check_stock($order->products[$i]['id'], $order->products[$i]['qty'])) {
                    $any_out_of_stock = true;
                }
            }
            // Out of Stock
            if ( (STOCK_ALLOW_CHECKOUT != 'true') && ($any_out_of_stock == true) ) {
                tep_redirect(tep_href_link(FILENAME_SHOPPING_CART));
            }
        }
        $customer_id = $_SESSION['customer_id'];
        $sql_data_array = array('customers_id' => $customer_id,
            'customers_name' => $order->customer['firstname'] . ' ' . $order->customer['lastname'],
            'customers_company' => $order->customer['company'],
            'customers_street_address' => $order->customer['street_address'],
            'customers_suburb' => $order->customer['suburb'],
            'customers_city' => $order->customer['city'],
            'customers_postcode' => $order->customer['postcode'],
            'customers_state' => $order->customer['state'],
            'customers_country' => $order->customer['country']['title'],
            'customers_telephone' => $order->customer['telephone'],
            'customers_email_address' => $order->customer['email_address'],
            'customers_address_format_id' => $order->customer['format_id'],
            'delivery_name' => trim($order->delivery['firstname'] . ' ' . $order->delivery['lastname']),
            'delivery_company' => $order->delivery['company'],
            'delivery_street_address' => $order->delivery['street_address'],
            'delivery_suburb' => $order->delivery['suburb'],
            'delivery_city' => $order->delivery['city'],
            'delivery_postcode' => $order->delivery['postcode'],
            'delivery_state' => $order->delivery['state'],
            'delivery_country' => $order->delivery['country']['title'],
            'delivery_address_format_id' => $order->delivery['format_id'],
            'billing_name' => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
            'billing_company' => $order->billing['company'],
            'billing_street_address' => $order->billing['street_address'],
            'billing_suburb' => $order->billing['suburb'],
            'billing_city' => $order->billing['city'],
            'billing_postcode' => $order->billing['postcode'],
            'billing_state' => $order->billing['state'],
            'billing_country' => $order->billing['country']['title'],
            'billing_address_format_id' => $order->billing['format_id'],
            'payment_method' => $order->info['payment_method'],
            'cc_type' => $order->info['cc_type'],
            'cc_owner' => $order->info['cc_owner'],
            'cc_number' => $order->info['cc_number'],
            'cc_expires' => $order->info['cc_expires'],
            'date_purchased' => 'now()',
            'orders_status' => $order->info['order_status'],
            'currency' => $order->info['currency'],
            'currency_value' => $order->info['currency_value']);
        tep_db_perform(TABLE_ORDERS, $sql_data_array);
        $insert_id = tep_db_insert_id();
        for ($i=0, $n=sizeof($order_totals); $i<$n; $i++) {
            $sql_data_array = array('orders_id' => $insert_id,
                'title' => $order_totals[$i]['title'],
                'text' => $order_totals[$i]['text'],
                'value' => $order_totals[$i]['value'],
                'class' => $order_totals[$i]['code'],
                'sort_order' => $order_totals[$i]['sort_order']);
            tep_db_perform(TABLE_ORDERS_TOTAL, $sql_data_array);
        }

        $customer_notification = (SEND_EMAILS == 'true') ? '1' : '0';
        $sql_data_array = array('orders_id' => $insert_id,
            'orders_status_id' => $order->info['order_status'],
            'date_added' => 'now()',
            'customer_notified' => $customer_notification,
            'comments' => $order->info['comments']);
        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

        // initialized for the email confirmation
        $products_ordered = '';

        for ($i=0, $n=sizeof($order->products); $i<$n; $i++) {
            // Stock Update - Joao Correia
            if (STOCK_LIMITED == 'true') {
                if (DOWNLOAD_ENABLED == 'true') {
                    $stock_query_raw = "SELECT products_quantity, pad.products_attributes_filename 
                                FROM " . TABLE_PRODUCTS . " p
                                LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES . " pa
                                 ON p.products_id=pa.products_id
                                LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
                                 ON pa.products_attributes_id=pad.products_attributes_id
                                WHERE p.products_id = '" . tep_get_prid($order->products[$i]['id']) . "'";
                    // Will work with only one option for downloadable products
                    // otherwise, we have to build the query dynamically with a loop
                    $products_attributes = (isset($order->products[$i]['attributes'])) ? $order->products[$i]['attributes'] : '';
                    if (is_array($products_attributes)) {
                        $stock_query_raw .= " AND pa.options_id = '" . (int)$products_attributes[0]['option_id'] . "' AND pa.options_values_id = '" . (int)$products_attributes[0]['value_id'] . "'";
                    }
                    $stock_query = tep_db_query($stock_query_raw);
                } else {
                    $stock_query = tep_db_query("select products_quantity from " . TABLE_PRODUCTS . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                }
                if (tep_db_num_rows($stock_query) > 0) {
                    $stock_values = tep_db_fetch_array($stock_query);
                    // do not decrement quantities if products_attributes_filename exists
                    if ((DOWNLOAD_ENABLED != 'true') || (!$stock_values['products_attributes_filename'])) {
                        $stock_left = $stock_values['products_quantity'] - $order->products[$i]['qty'];
                    } else {
                        $stock_left = $stock_values['products_quantity'];
                    }
                    tep_db_query("update " . TABLE_PRODUCTS . " set products_quantity = '" . (int)$stock_left . "' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                    if ( ($stock_left < 1) && (STOCK_ALLOW_CHECKOUT == 'false') ) {
                        tep_db_query("update " . TABLE_PRODUCTS . " set products_status = '0' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
                    }
                }
            }

            // Update products_ordered (for bestsellers list)
            tep_db_query("update " . TABLE_PRODUCTS . " set products_ordered = products_ordered + " . sprintf('%d', $order->products[$i]['qty']) . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");

            $sql_data_array = array('orders_id' => $insert_id,
                'products_id' => tep_get_prid($order->products[$i]['id']),
                'products_model' => $order->products[$i]['model'],
                'products_name' => $order->products[$i]['name'],
                'products_price' => $order->products[$i]['price'],
                'final_price' => $order->products[$i]['final_price'],
                'products_tax' => $order->products[$i]['tax'],
                'products_quantity' => $order->products[$i]['qty']);
            tep_db_perform(TABLE_ORDERS_PRODUCTS, $sql_data_array);
            $order_products_id = tep_db_insert_id();

            //------insert customer choosen option to order--------
            $attributes_exist = '0';
            $products_ordered_attributes = '';
            if (isset($order->products[$i]['attributes'])) {
                $attributes_exist = '1';
                for ($j=0, $n2=sizeof($order->products[$i]['attributes']); $j<$n2; $j++) {
                    if (DOWNLOAD_ENABLED == 'true') {
                        $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename 
                                   from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa 
                                   left join " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
                                    on pa.products_attributes_id=pad.products_attributes_id
                                   where pa.products_id = '" . (int)$order->products[$i]['id'] . "' 
                                    and pa.options_id = '" . (int)$order->products[$i]['attributes'][$j]['option_id'] . "' 
                                    and pa.options_id = popt.products_options_id 
                                    and pa.options_values_id = '" . (int)$order->products[$i]['attributes'][$j]['value_id'] . "' 
                                    and pa.options_values_id = poval.products_options_values_id 
                                    and popt.language_id = '" . (int)$languages_id . "' 
                                    and poval.language_id = '" . (int)$languages_id . "'";
                        $attributes = tep_db_query($attributes_query);
                    } else {
                        $attributes = tep_db_query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa where pa.products_id = '" . (int)$order->products[$i]['id'] . "' and pa.options_id = '" . (int)$order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . (int)$order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . (int)$languages_id . "' and poval.language_id = '" . (int)$languages_id . "'");
                    }
                    $attributes_values = tep_db_fetch_array($attributes);

                    $sql_data_array = array('orders_id' => $insert_id,
                        'orders_products_id' => $order_products_id,
                        'products_options' => $attributes_values['products_options_name'],
                        'products_options_values' => $attributes_values['products_options_values_name'],
                        'options_values_price' => $attributes_values['options_values_price'],
                        'price_prefix' => $attributes_values['price_prefix']);
                    tep_db_perform(TABLE_ORDERS_PRODUCTS_ATTRIBUTES, $sql_data_array);

                    if ((DOWNLOAD_ENABLED == 'true') && isset($attributes_values['products_attributes_filename']) && tep_not_null($attributes_values['products_attributes_filename'])) {
                        $sql_data_array = array('orders_id' => $insert_id,
                            'orders_products_id' => $order_products_id,
                            'orders_products_filename' => $attributes_values['products_attributes_filename'],
                            'download_maxdays' => $attributes_values['products_attributes_maxdays'],
                            'download_count' => $attributes_values['products_attributes_maxcount']);
                        tep_db_perform(TABLE_ORDERS_PRODUCTS_DOWNLOAD, $sql_data_array);
                    }
                    $products_ordered_attributes .= "\n\t" . $attributes_values['products_options_name'] . ' ' . $attributes_values['products_options_values_name'];
                }
            }
            //------insert customer choosen option eof ----
            $products_ordered .= $order->products[$i]['qty'] . ' x ' . $order->products[$i]['name'] . ' (' . $order->products[$i]['model'] . ') = ' . $currencies->display_price($order->products[$i]['final_price'], $order->products[$i]['tax'], $order->products[$i]['qty']) . $products_ordered_attributes . "\n";
        }
        return $insert_id;
    }

    function sendOrderEmails($order_id)
    { global $order, $order_totals,$navigation,$cart,$payment,$customer_id,$sendto,$billto;
        global $$payment;
        // if the customer is not logged on, redirect them to the login page
        if (!tep_session_is_registered('customer_id')) {
            $navigation->set_snapshot(array('mode' => 'SSL', 'page' => FILENAME_CHECKOUT_PAYMENT));
            tep_redirect(tep_href_link(FILENAME_LOGIN, '', 'SSL'));
        }
        // lets start with the email confirmation
        $email_order = STORE_NAME . "\n" .
            EMAIL_SEPARATOR . "\n" .
            EMAIL_TEXT_ORDER_NUMBER . ' ' . $order_id . "\n" .
            EMAIL_TEXT_INVOICE_URL . ' ' . tep_href_link(FILENAME_ACCOUNT_HISTORY_INFO, 'order_id=' . $order_id, 'SSL', false) . "\n" .
            EMAIL_TEXT_DATE_ORDERED . ' ' . strftime(DATE_FORMAT_LONG) . "\n\n";
        if ($order->info['comments']) {
            $email_order .= tep_db_output($order->info['comments']) . "\n\n";
        }
        $email_order .= EMAIL_TEXT_PRODUCTS . "\n" .
            EMAIL_SEPARATOR . "\n" .
            $products_ordered .
            EMAIL_SEPARATOR . "\n";
        if(is_array($order_totals) || is_object($order_totals))
            for ($i=0, $n=sizeof($order_totals); $i<$n; $i++) {
                $email_order .= strip_tags($order_totals[$i]['title']) . ' ' . strip_tags($order_totals[$i]['text']) . "\n";
            }
        if ($order->content_type != 'virtual') {
            $email_order .= "\n" . EMAIL_TEXT_DELIVERY_ADDRESS . "\n" .
                EMAIL_SEPARATOR . "\n" .
                tep_address_label($customer_id, $sendto, 0, '', "\n") . "\n";
        }

        $email_order .= "\n" . EMAIL_TEXT_BILLING_ADDRESS . "\n" .
            EMAIL_SEPARATOR . "\n" .
            tep_address_label($customer_id, $billto, 0, '', "\n") . "\n\n";
        if (is_object($$payment)) {
            $email_order .= EMAIL_TEXT_PAYMENT_METHOD . "\n" .
                EMAIL_SEPARATOR . "\n";
            $payment_class = $$payment;
            $email_order .= $order->info['payment_method'] . "\n\n";
            if (isset($payment_class->email_footer)) {
                $email_order .= $payment_class->email_footer . "\n\n";
            }
        }
        tep_mail($order->customer['firstname'] . ' ' . $order->customer['lastname'], $order->customer['email_address'], EMAIL_TEXT_SUBJECT, $email_order, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);

        // send emails to other people
        if (SEND_EXTRA_ORDER_EMAILS_TO != '') {
            tep_mail('', SEND_EXTRA_ORDER_EMAILS_TO, EMAIL_TEXT_SUBJECT, $email_order, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
        }

        $cart->reset(true);

        // unregister session variables used during checkout
        tep_session_unregister('sendto');
        tep_session_unregister('billto');
        tep_session_unregister('shipping');
        tep_session_unregister('payment');
        tep_session_unregister('comments');
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
    }

    function get_product_price($products_id)
    { global $currencies,$languages_id;
        $product_info_query = tep_db_query("select p.products_id, p.products_price, p.products_tax_class_id from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd where p.products_status = '1' and p.products_id = '" . (int)$products_id . "' and pd.products_id = p.products_id and pd.language_id = '" . (int)$languages_id . "'");
        $product_info = tep_db_fetch_array($product_info_query);
        if ($new_price = tep_get_products_special_price($product_info['products_id'])) {
            $products_price = tep_round(tep_add_tax($new_price, tep_get_tax_rate($product_info['products_tax_class_id'])), $currencies->currencies[DEFAULT_CURRENCY]['decimal_places']);
        } else {
            $products_price = tep_round(tep_add_tax($product_info['products_price'], tep_get_tax_rate($product_info['products_tax_class_id'])), $currencies->currencies[DEFAULT_CURRENCY]['decimal_places']);
        }
        return $products_price;
    }

    function getProductOptionsAdmin($products_id)
    {
        $before_s = '';
        if($products_id) {
            $before_s = '<tr><td colspan="2">'.tep_draw_separator("pixel_black.gif", "100%", "3").'</td></tr><tr>';
            $selected_plans = $this->getProductSettings($products_id);
            $selected_plans = $selected_plans['plans'];
            foreach ($this->status_arr as $key => $value) {
                $name = 'MODULE_PAYMENT_FINANCEPAYMENT_PRODUCT_FINANACE_PLANS';
                $string .= '<br><input type="checkbox" name="'.$name.'" value="' . $key . '"';
                if (strpos(' '.$selected_plans,$value)) $string .= ' CHECKED';
                $string .= ' id="' . strtolower($value . '-' . $name) . '"> ' . '<label for="' . strtolower($value . '-' . $name) . '" class="inputSelect">' . $value . '</label>' . "\n";
            }
            $before_s .='<td class="main">Selected Plans for this product</td><td class="main">'.tep_draw_separator('pixel_trans.gif', '24', '15').'&nbsp;' .$string.'</td></tr>
      <input type="hidden" name="financepayment" id="financepayment" value="'.$selected_plans.'">
      ';
        }
        return $before_s;
    }
    public function hiddenField($value) {
        return '';
    }





}
