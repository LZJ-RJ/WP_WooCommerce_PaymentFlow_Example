<?php
if (! defined('ABSPATH')){
    exit; // Exit if accessed directly
}

if(!class_exists('WC_Gateway_bank_CreditCard')) {
    class WC_Gateway_bank_CreditCard extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->text_domain = 'bank';
            $this->id = 'wc_gateway_bank_creditcard';                   //一定要用
            $this->method_title = '某銀信用卡';                           //一定要用
            $this->method_description = '某銀行的信用卡串接';               //一定要用
            $this->has_fields = ($this->description != '');             //一定要用

            $this->init_form_fields();                                  //一定要用
            $this->init_settings();                                     //一定要用

            $this->title = $this->get_option('title');                  //一定要用
            $this->description = $this->get_option('description');      //一定要用

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options')); //一定要用
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));           //一定要用
            add_action('woocommerce_api_' . 'bank_cc_result_callback', array($this, 'result_callback'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'show_payment_result'));   //上面function的結帳頁面的某個地方(看hook)
        }

        public function init_form_fields()
        {
            $wc_shipping_methods = WC()->shipping()->get_shipping_methods();
            $excluded_shipping_options = [];
            foreach ($wc_shipping_methods as $shipping_id => $wc_shipping_method) {
                $excluded_shipping_options[$shipping_id] = $wc_shipping_method->method_title;
            }
            //TODO : 參數的部分請以各API文件調整
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', $this->text_domain),
                    'type' => 'checkbox',
                    'label' => __('Enable', $this->text_domain),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', $this->text_domain),
                    'type' => 'text',
                    'description' => __('Payment title', $this->text_domain),
                    'default' => __('某銀信用卡', $this->text_domain),
                ),
                'merID' => array(
                    'title' => __('merID', $this->text_domain),
                    'type' => 'text',
                    'description' => '特店網站之代碼，不同於MerchantID，長度最長為10。',
                    'default' => '27780802'
                ),
                'MerchantID' => array(
                    'title' => __('MerchantID', $this->text_domain),
                    'type' => 'text',
                    'description' => '收單銀行授權使用的特店代號，固定長度為15位數字。',
                    'default' => '007277808029001'
                ),
            );
        }

        //按下結帳按鈕後進來這邊
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->add_order_note('付款方式: 信用卡');
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            ];
        }

        //process_payment之後會進到receipt_page，這邊處理要傳過去銀行的資料
        public function receipt_page($order_id)
        {
            //TODO : 參數的部分請以各API文件調整
            //取得參數的順序是依照API串接文件的
            $merID = $this->get_option('merID');
            $MerchantID = $this->get_option('MerchantID');
            $order = wc_get_order($order_id);
            $lidm = $order->post->ID;
            $purchAmt = $order->get_total();
            if($this->get_option('AutoCap') == 'no')
                $AutoCap = 0;
            else
                $AutoCap = 1;
            $mysite_url = home_url();
            if ($order) {
                $AuthResURL = $mysite_url . '/wc-api/bank_cc_result_callback';
            }
            //token驗證機制-沒用到
            $LocalDate = date('Ymd',current_time( 'timestamp' ));
            $LocalTime  = date('Gis',current_time('timestamp'));
            if(strlen($LocalTime) == 5){
                $LocalTime = '0' . $LocalTime;
            }
            $pattern_reqToken = '1234567890abcdefghijklmnopqrstuvwxyz';
            $reqToken = '';
            for($i=0;$i<65;$i++){
                $reqToken .= $pattern_reqToken{rand(0,35)};
            }
            update_post_meta($lidm,'reqToken', $reqToken);
            //token驗證機制-此範例剛好沒使用到
            //交易逾時機制
            $timeoutDate  = $LocalDate;
            $timeoutTime = $LocalTime;
            $timeoutSecs = '150';
            //交易逾時機制
            $service_url = 'https://www.focas.fisc.com.tw/FOCAS_WbankPOS/online/';
            $pass_parameters = array(
                'merID' => $merID, 'MerchantID' => $MerchantID,
            );
            //TODO : 參數的部分請以各API文件調整
            ?>
            <form id="_bank_auto_form" method="POST" action=<?= $service_url; ?> charset="Big-5" />
            <?php
            foreach ($pass_parameters as $key => $value):?>
               <input type=hidden name="<?= $key ;?>" value="<?=$value;?>">
            <?php endforeach;
            ?>
            </form>
            <script>
                document.getElementById('_bank_auto_form').submit();
            </script>
            <?php
        }

        //收到回傳資訊處理的地方 檢查碼 &  轉址到付款的頁面
        public function result_callback()
        {
            //取得參數的順序是依照API串接文件 & 授權後才能取
            $status = $_POST['status'];
            $errcode = $_POST['errcode'];
            $authCode = $_POST['authCode'];
            $authAmt = $_POST['authAmt'];
            $lidm = $_POST['lidm'];
            $xid = $_POST['xid'];
            $merID = $_POST['merID'];
            $lastPan4 = $_POST['lastPan4'];
            $cardBrand = $_POST['cardBrand'];
            $pan = $_POST['pan'];
            $authRespTime = $_POST['authRespTime'];
            $reqToken  = $_POST['reqToken']; //65字元-此為空值
            $order = wc_get_order($lidm);

            //TODO : 參數的部分請以各API文件調整
            update_post_meta($lidm, '_wcgcc_authAmt', $authAmt);


            $order_note  = '';
            $order_note .= '卡別：' . $cardBrand . '<br />';
            $order_note .= '卡號：' . $pan . '<br />';
            $order_note .= '授權金額：台幣' . $authAmt . '<br />';
            $order_note .= '交易序號：' . $xid . '<br />';
            $order_note .= '授權時間：' . $authRespTime . '<br />';
//            && $reqToken == get_post_meta($lidm, 'reqToken' , true)  之後若要增加再加入到下方判斷式中
            //授權成功
            if
            (
                    $status == 0  && $authCode != '' && $xid != '' &&
                    $errcode == 00 && $merID == $this->get_option('merID') &&
                    $lastPan4 == substr($pan,-4)
            )
            {
                    update_post_meta($lidm, '_wcgcc_authresponse', 'Success');
                    $order_note .= '授權碼：' . $authCode . '<br />';
                    $order_note .= '授權結果：' . get_post_meta($lidm,'_wcgcc_authresponse',true) . '<br />';
                    if(version_compare(WC()->version, '3.0', ">=" ))
                        wc_reduce_stock_levels($lidm);
                    else
                        $order->reduce_order_stock();
                    $order->update_status('processing');
            }
            else
            {
                update_post_meta($lidm, '_wcgcc_authresponse', 'Failed');
                update_post_meta($lidm, '_wcgcc_errcode', $errcode);
                $order_note .= '錯誤代碼：' . $errcode . '<br />';
                $order_note .= '授權結果：' . get_post_meta($lidm,'_wcgcc_authresponse',true) . '<br />';
                $order->update_status('pending');
            }
            $order->add_order_note($order_note);
            wp_safe_redirect($order->get_checkout_order_received_url()); //轉址到屬於那個訂單的付款頁面
        }

        //修改付款的頁面的部分純顯示&撈資料
        public function show_payment_result($order_id){
            //TODO : 參數的部分請以各API文件調整
            echo
                '<h2>信用卡資訊</h2>'.
                '<pre>'.
                '卡別：' . get_post_meta($order_id,'_wcgcc_cardBrand',true) . '<br />'.
                '卡號：' . get_post_meta($order_id,'_wcgcc_pan',true).'<br />'.
                '授權金額：NTD ' . get_post_meta($order_id,'_wcgcc_authAmt',true) . '<br />'.
                '交易序號：' . get_post_meta($order_id,'_wcgcc_xid',true) .'<br />'.
                '授權時間：' . get_post_meta($order_id,'_wcgcc_authRespTime',true) . '<br />';

            if(($result = get_post_meta($order_id,'_wcgcc_authresponse',true)) == 'Success')
                echo '授權碼：' . get_post_meta($order_id,'_wcgcc_authCode',true) . '<br />';
            else
                echo '錯誤代碼：' . get_post_meta($order_id,'_wcgcc_errcode',true) . '<br />';

            echo
                '授權結果：' . $result . '<br />',
                '</pre>';
        }
    }
}