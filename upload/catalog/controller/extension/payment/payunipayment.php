<?php

class ControllerExtensionPaymentPayunipayment extends Controller {

    private $error = array();
    private $prefix;
    private $configSetting = array();

    public function __construct($registry) {
        parent::__construct($registry);
        $this->prefix = (version_compare(VERSION, '3.0', '>=')) ? 'payment_' : '';

        if ($this->config->get($this->prefix . 'payunipayment_status')) {
            $data = $this->load->language('extension/payment/payunipayment');
            $this->configSetting = [
                'front_name'          => $this->config->get($this->prefix . 'payunipayment_front_name'),
                'test_mode'           => $this->config->get($this->prefix . 'payunipayment_test_mode'),
                'merchant_id'         => $this->config->get($this->prefix . 'payunipayment_merchant_id'),
                'hash_key'            => $this->config->get($this->prefix . 'payunipayment_hash_key'),
                'hash_iv'             => $this->config->get($this->prefix . 'payunipayment_hash_iv'),
                'item_info'           => $this->config->get($this->prefix . 'payunipayment_item_info'),
                'order_status'        => $this->config->get($this->prefix . 'payunipayment_order_status'),
                'order_finish_status' => $this->config->get($this->prefix . 'payunipayment_order_finish_status'),
                'order_fail_status'   => $this->config->get($this->prefix . 'payunipayment_order_fail_status'),
                'sort_order'          => $this->config->get($this->prefix . 'payunipayment_sort_order'),
            ];
        }
    }

    public function index() {

		$this->load->model('checkout/order');
        // Test Mode
        if ($this->configSetting['test_mode'] == 1) {
            $data['action'] = "https://sandbox-api.payuni.com.tw/api/upp"; //測試網址
        } else {
            $data['action'] = "https://api.payuni.com.tw/api/upp"; // 正式網址
        }

        $data['params'] = $this->uppOnePointHandler();
        $data['item_info'] = $this->configSetting['item_info'];

        return $this->load->view('extension/payment/payunipayment', $data);
    }

    public function confirm() {
        $json = array();
            if ($this->session->data['payment_method']['code'] == 'payunipayment') {
                $this->load->model('checkout/order');
                $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $this->configSetting['order_status']);
                $json['redirect'] = $this->url->link('checkout/success');
            }
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     *upp資料處理
     *
     * @access private
     * @version 1.0
     * @return array
     */
    private function uppOnePointHandler() {
        $this->load->model('checkout/order');
        // 訂單資料
        $orderInfo    = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        // 商品資料
        $productsInfo = $this->model_checkout_order->getOrderProducts($this->session->data['order_id']);
        $prodDesc     = [];
        foreach ($productsInfo as $product) {
            $prodDesc[] = $product['name'] . ' * ' . $product['quantity'];
        }

        $encryptInfo = [
            'MerID'      => $this->configSetting['merchant_id'],
            'MerTradeNo' => $orderInfo['order_id'],
            'TradeAmt'   => (int) $orderInfo['total'],
            'ProdDesc'   => implode(';', $prodDesc),
            'ReturnURL'  => $this->url->link('extension/payment/payunipayment/returnInfo'), //幕前
            'NotifyURL'  => $this->url->link('extension/payment/payunipayment/notify'), //幕後
            'UsrMail'    => (isset($orderInfo['email'])) ? $orderInfo['email'] : '',
            'Timestamp'  => time()
        ];
        $parameter['MerID']       = $this->configSetting['merchant_id'];
        $parameter['Version']     = '1.0';
        $parameter['EncryptInfo'] = $this->Encrypt($encryptInfo);
        $parameter['HashInfo']    = $this->HashInfo($parameter['EncryptInfo']);
        return $parameter;
    }

    /**
     * 接收returnInfo相關處理
     */
    public function returnInfo() {

        // 交易結果
        $result = $this->ResultProcess($_POST);

        $encryptInfo = $result['message']['EncryptInfo'];

        /**
         * 頁面資料
         */
        $this->language->load('checkout/success');
        $this->language->load('checkout/success_error');

        if (isset($this->session->data['order_id'])) {
            $this->cart->clear();
            unset($this->session->data['order_id']);
            unset($this->session->data['payment_address']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['shipping_address']);
            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['comment']);
            unset($this->session->data['coupon']);
            unset($this->session->data['reward']);
            unset($this->session->data['voucher']);
            unset($this->session->data['vouchers']);
        }

        // 訂單付款明細寫入歷程
        $this->load->model('checkout/order');
        $orderInfo = $this->model_checkout_order->getOrder($encryptInfo['MerTradeNo']);

        // 訂單是待處理狀態才更新歷程
        if ( $orderInfo['order_status_id'] == $this->configSetting['order_status'] ) {
            $this->model_checkout_order->addOrderHistory(
                $orderInfo['order_id'],
                4, // 待付款
                $this->SetNotice($encryptInfo),
                true
            );
        }

        // 顯示的 Title
        $title = ($encryptInfo['Status'] == 'SUCCESS') ? $this->language->get('heading_title') : $this->language->get('heading_title_fail') . $encryptInfo['Message'];

        $this->document->setTitle($title);

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'href' => $this->url->link('common/home'),
            'text' => $this->language->get('text_home'),
            'separator' => false
        );

        $data['breadcrumbs'][] = array(
            'href' => $this->url->link('checkout/cart'),
            'text' => $this->language->get('text_basket'),
            'separator' => $this->language->get('text_separator')
        );

        $data['breadcrumbs'][] = array(
            'href' => $this->url->link('checkout/checkout', '', 'SSL'),
            'text' => $this->language->get('text_checkout'),
            'separator' => $this->language->get('text_separator')
        );

        $data['breadcrumbs'][] = array(
            'href' => $this->url->link('checkout/success'),
            'text' => $this->language->get('text_success'),
            'separator' => $this->language->get('text_separator')
        );

        $data['text_message'] = $this->SetNotice($encryptInfo);

        $data['continue'] = $this->url->link('common/home', 'language=' . $this->config->get('config_language'));

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $data['heading_title']  = $title;

        $this->response->setOutput($this->load->view('common/success', $data));
    }

    /**
     * 接收notify相關處理
     */
    public function notify() {

        // 交易結果
        $result = $this->ResultProcess($_POST);

        if ($result['success'] == false) {
            $this->writeLog("解密失敗");
            exit();
        }

        if ($result['message']['Status'] != 'SUCCESS') {
            $this->writeLog("交易失敗：" . $result['message']['Status'] . "(" . $result['message']['EncryptInfo']['Message'] . ")");
            exit();
        }

        // 取得該筆交易資料
        $encryptInfo = $result['message']['EncryptInfo'];
        $this->load->model('checkout/order');
        $orderInfo = $this->model_checkout_order->getOrder($encryptInfo['MerTradeNo']);

        // 1. 檢查訂單是否存在
        if (!$orderInfo) {
            $this->writeLog("取得訂單失敗，訂單編號：" . $encryptInfo['MerTradeNo']);
            exit();
        }

        // 2. 檢查交易總金額
        if (intval($orderInfo['total']) != $encryptInfo['TradeAmt']) {
            $msg = "錯誤: 結帳金額與訂單金額不一致";

            // 更新訂單狀態並寫入訂單歷程
            $this->model_checkout_order->addOrderHistory(
                $orderInfo['order_id'],
                $this->configSetting['order_fail_status'],
                $msg,
                true
            );

            $this->writeLog($msg);
            exit();
        }

        // 3. 檢查訂單狀態是否為已付款
        if ($encryptInfo['TradeStatus'] == '1') {
            $msg = $orderInfo['order_id'] . ' OK';  //訂單成功
  
            // 已付款，更新訂單狀態並寫入訂單歷程
            $this->model_checkout_order->addOrderHistory(
                $orderInfo['order_id'],
                $this->configSetting['order_finish_status'],
                $this->SetNotice($encryptInfo),
                true
            );

        } else {
            $msg = "錯誤: 訂單付款失敗";

            // 付款失敗，更新訂單狀態並寫入訂單歷程
            $this->model_checkout_order->addOrderHistory(
                $orderInfo['order_id'],
                $this->configSetting['order_fail_status'],
                $msg,
                true
            );
        }

        $this->writeLog($msg);
        exit(true);
    }

    /**
     * 產生訊息內容
     * return string
     */
    private function SetNotice(Array $encryptInfo) {
        $trdStatus = ['待付款','已付款','付款失敗','付款取消'];
        $message   = "<<<code>統一金流 PAYUNi</code>>>";
        switch ($encryptInfo['PaymentType']){
            case '1': // 信用卡
                $authType = [1=>'一次', 2=>'分期', 3=>'紅利', 7=>'銀聯'];
                $message .= "</br>授權狀態：" . $encryptInfo['Message'];
                $message .= "</br>訂單狀態：" . $trdStatus[$encryptInfo['TradeStatus']];
                $message .= "</br>UNi序號：" . $encryptInfo['TradeNo'];
                $message .= "</br>卡號：" . $encryptInfo['Card6No'] . '******' . $encryptInfo['Card4No'];
                if ($encryptInfo['CardInst'] > 1) {
                    $message .= "</br>分期數：" . $encryptInfo['CardInst'];
                    $message .= "</br>首期金額：" . $encryptInfo['FirstAmt'];
                    $message .= "</br>每期金額：" . $encryptInfo['EachAmt'];
                }
                $message .= "</br>授權碼：" . $encryptInfo['AuthCode'];
                $message .= "</br>授權銀行代號：" . $encryptInfo['AuthBank'];
                $message .= "</br>授權銀行：" . $encryptInfo['AuthBankName'];
                $message .= "</br>授權類型：" . $authType[$encryptInfo['AuthType']];
                $message .= "</br>授權日期：" . $encryptInfo['AuthDay'];
                $message .= "</br>授權時間：" . $encryptInfo['AuthTime'];
                break;
            case '2': // atm轉帳
                $message .= "</br>訂單狀態：" . $trdStatus[$encryptInfo['TradeStatus']];
                $message .= "</br>UNi序號：" . $encryptInfo['TradeNo'];
                $message .= "</br>銀行代碼：" . $encryptInfo['BankType'];
                $message .= "</br>繳費帳號：" . $encryptInfo['PayNo'];
                $message .= "</br>繳費截止時間：" . $encryptInfo['ExpireDate'];
                break;
            case '3': // 超商代碼
                $store = ['SEVEN' => '統一超商 (7-11)'];
                $message .= "</br>訂單狀態：" . $trdStatus[$encryptInfo['TradeStatus']];
                $message .= "</br>UNi序號：" . $encryptInfo['TradeNo'];
                $message .= "</br>繳費方式：" . $store[$encryptInfo['Store']];
                $message .= "</br>繳費代號：" . $encryptInfo['PayNo'];
                $message .= "</br>繳費截止時間：" . $encryptInfo['ExpireDate'];
                break;
            case '6': // ICP 愛金卡
                $message .= "</br>訂單狀態：" . $trdStatus[$encryptInfo['TradeStatus']];
                $message .= "</br>UNi序號：" . $encryptInfo['TradeNo'];
                $message .= "</br>愛金卡交易序號：" . $encryptInfo['PayNo'];
                $message .= "</br>付款日期時間：" . $encryptInfo['PayTime'];
                break;
            default: // 預設顯示資訊
                $message .= "</br>訂單狀態：" . $trdStatus[$encryptInfo['TradeStatus']];
                $message .= "</br>UNi序號：" . $encryptInfo['TradeNo'];
                break;
        }
        return $message;
    }

    /**
     * 處理api回傳的結果
     * @ author    Yifan
     * @ dateTime 2022-08-26
     */
    private function ResultProcess($result) {
        $msg = '';
        if (is_array($result)) {
            $resultArr = $result;
        }
        else {
            $resultArr = json_decode($result, true);
            if (!is_array($resultArr)){
                $msg = 'Result must be an array';
                $this->writeLog($msg);
                return ['success' => false, 'message' => $msg];
            }
        }
        if (isset($resultArr['EncryptInfo'])){
            if (isset($resultArr['HashInfo'])){
                $chkHash = $this->HashInfo($resultArr['EncryptInfo']);
                if ( $chkHash != $resultArr['HashInfo'] ) {
                    $msg = 'Hash mismatch';
                    $this->writeLog($msg);
                    return ['success' => false, 'message' => $msg];
                }
                $resultArr['EncryptInfo'] = $this->Decrypt($resultArr['EncryptInfo']);
                return ['success' => true, 'message' => $resultArr];
            }
            else {
                $msg = 'missing HashInfo';
                $this->writeLog($msg);
                return ['success' => false, 'message' => $msg];
            }
        }
        else {
            $msg = 'missing EncryptInfo';
            $this->writeLog($msg);
            return ['success' => false, 'message' => $msg];
        }
    }

    /**
     * 加密
     */
    private function Encrypt($encryptInfo) {
        $tag = '';
        $encrypted = openssl_encrypt(http_build_query($encryptInfo), 'aes-256-gcm', trim($this->configSetting['hash_key']), 0, trim($this->configSetting['hash_iv']), $tag);
        return trim(bin2hex($encrypted . ':::' . base64_encode($tag)));
    }

    /**
     * 解密
     */
    private function Decrypt(string $encryptStr = '') {
        list($encryptData, $tag) = explode(':::', hex2bin($encryptStr), 2);
        $encryptInfo = openssl_decrypt($encryptData, 'aes-256-gcm', trim($this->configSetting['hash_key']), 0, trim($this->configSetting['hash_iv']), base64_decode($tag));
        parse_str($encryptInfo, $encryptArr);
        return $encryptArr;
    }

    /**
     * hash
     */
    private function HashInfo(string $encryptStr = '') {
        return strtoupper(hash('sha256', $this->configSetting['hash_key'].$encryptStr.$this->configSetting['hash_iv']));
    }

    /**
     * log
     */
    private function writeLog($msg = '', $with_input = true)
    {
        $file_path = DIR_LOGS; // 檔案路徑
        if(! is_dir($file_path)) {
            return;
        }

        $file_name = 'payuni_' . date('Ymd', time()) . '.txt';  // 取時間做檔名 (YYYYMMDD)
        $file = $file_path . $file_name;
        $fp = fopen($file, 'a');
        $input = ($with_input) ? '|REQUEST:' . json_encode($_REQUEST) : '';
        $log_str = date('Y-m-d h:i:s') . '|' . $msg . $input . "\n";
        fwrite($fp, $log_str);
        fclose($fp);
    }
}