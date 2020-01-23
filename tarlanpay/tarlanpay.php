<?php

defined('_JEXEC') or die;

if (!class_exists('vmPSPlugin')) {
	require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

Class plgVmPaymentTarlanPay extends vmPSPlugin {
	
	function __construct(& $subject, $config) {

        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
	
		$varsToPush = array(
			'slug' => array('Tarlan Payments', 'string'),
            'payment_name' => array('Tarlan Payments','string'),
            'merchant_id' => array(0,'int'),
            'secret_key' => array('','string'),
            'test_mode' => array(0,'int'),
			'status_paid' => array('', 'string'),
			'status_pending' => array('', 'string'),
			'status_refund' => array('', 'string'),
			'status_failed' => array('', 'string')
        );

		$varsToPush = $this->getVarsToPush();
		$this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
	
	public function getVmPluginCreateTableSQL() {

        return $this->createTableSQL('Payment 2Checkout Table');
    }
	
	function getTableSQLFields() {

        $SQLfields = array(
            'id' => 'int(11) unsigned NOT NULL AUTO_INCREMENT ',
            'virtuemart_order_id' => 'int(11) UNSIGNED',
            'order_number' => 'char(32)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) '
        );
        return $SQLfields;
    }
	
	function plgVmConfirmedOrder($cart, $order){
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
		if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
		$session = JFactory::getSession();
        $return_context = $session->getId();
        $this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');
		if (!class_exists('TableVendors'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		
		$params = explode('|', $method->payment_params);
		$merchant_id_param = $params[0];
		$secret_key_param = $params[1];
		$test_mode_param = $params[2];
		parse_str($merchant_id_param, $merchant_id_value);
		parse_str($secret_key_param, $secret_key_value);
		parse_str($test_mode_param, $test_mode_value);
		$merchant_id = str_replace('"', '', $merchant_id_value['merchant_id']);
		$secret_key = str_replace('"', '', $secret_key_value['secret_key']);
		$test_mode = str_replace('"', '', $test_mode_value['test_mode']);
		$order_id = $order['details']['BT']->order_number;
		$amount = $order['details']['BT']->order_total;
		$user_email = $order['details']['BT']->email;

		$callbackUrl = JROUTE::_(JURI::root() . "index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=" . $order['details']['BT']->virtuemart_paymentmethod_id . "&reference_id={$order['details']['BT']->order_number}");
		
		$postData = [
			'reference_id' => $order_id,
			'amount' => $amount,
			'description' => 'joomla virtuemart',
			'merchant_id' => $merchant_id,
			'secret_key' => $secret_key,
			'is_test' => $test_mode,
			'back_url' => $callbackUrl,
			'request_url' => 'http://'.$_SERVER['HTTP_HOST'],
			'email' => $user_email
		];
		
		$postData['is_test'] = $test_mode = '0' ? false : true;
		$postData['secret_key'] = password_hash($order_id.$secret_key, PASSWORD_BCRYPT, ['cost' => 10]);
		
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$this->storePSPluginInternalData($dbValues);	
		
		$ctp_url = 'https://api.tarlanpayments.kz/invoice/create';
		
		$curl = curl_init($ctp_url);
		
		curl_setopt($curl, CURLOPT_HTTPHEADER, array (
		 'Accept: application/json'
		));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
		
		$response = curl_exec($curl);
		
		$decoded_response = json_decode($response, true);
		$redirect_url = $decoded_response['data']['redirect_url'];
		
		header("Location: $redirect_url");	

        return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, $this->renderPluginName($method, $order), 'P');
	}
	
	function plgVmOnPaymentResponseReceived(&$html){
 		
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
		
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
                        return NULL;
        }	
		if (!$this->selectedThisElement($method->payment_element)) {
			return NULL;
		}
		
		if (!class_exists('VirtueMartCart'))
        require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
        if (!class_exists('shopFunctionsF'))
        require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		vmLanguage::loadJLang('com_virtuemart');
        $modelOrder = VmModel::getModel('orders');
	
		$payment_name = $this->renderPluginName($method);

		$tarlanResponse = file_get_contents('php://input');
		$tarlanData = json_decode($tarlanResponse, true);

		$order_number = $tarlanData['reference_id'];
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		$order = $modelOrder->getOrder($virtuemart_order_id);
		$customer_total = (number_format((float)$order['details']['BT']->order_total, 2, '.', ''));
		$payment_name = $this->renderPluginName($method);
		 if (!class_exists('VirtueMartModelOrders'))
		require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
        $modelOrder = new VirtueMartModelOrders();
		if(!empty($tarlanResponse)){
			switch($tarlanData['status']){
				case 0:
				$order['order_status'] = 'P';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				case 1:
				$order['order_status'] = 'C';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				case 2:
				$order['order_status'] = 'P';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				case 3:
				$order['order_status'] = 'P';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				case 4:
				$order['order_status'] = 'X';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				case 5:
				$order['order_status'] = 'R';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				case 6:
				$order['order_status'] = 'D';
				$order['customer_notified'] = 1;
				$modelOrder->updateStatusForOneOrder ($virtuemart_order_id, $order, TRUE);
				break;
				default:
				echo 'Undefined transaction status!<br>To solve this problem write to <a href="mailto:support@tarlanpayments.kz">support@tarlanpayments.kz</a>';
				break;
			}
		}
		return true;
	}
	
	protected function checkConditions($cart, $method, $cart_prices) {

		return parent::checkConditions($cart, $method, $cart_prices);

    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {

        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}


