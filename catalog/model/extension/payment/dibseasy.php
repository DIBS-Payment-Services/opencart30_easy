<?php
class ModelExtensionPaymentDibseasy extends Model {

    const METHOD_CODE = 'dibseasy';
    const PAYMENT_API_TEST_URL = 'https://test.api.dibspayment.eu/v1/payments';
    const PAYMENT_API_LIVE_URL = 'https://api.dibspayment.eu/v1/payments';
    const PAYMENT_TRANSACTION_URL_PATTERN_TEST = 'https://test.api.dibspayment.eu/v1/payments/{transactionId}';
    const PAYMENT_TRANSACTION_URL_PATTERN_LIVE = 'https://api.dibspayment.eu/v1/payments/{transactionId}';
    const CHECKOUT_SCRIPT_TEST = 'https://test.checkout.dibspayment.eu/v1/checkout.js?v=1';
    const CHECKOUT_SCRIPT_LIVE = 'https://checkout.dibspayment.eu/v1/checkout.js?v=1';
    protected $products = array();
    protected $logger;
    public $paymentId;

        public function __construct($registry) {
                $this->logger = new Log('dibs.easy.log');
                parent::__construct($registry);
        }

	public function getMethod($address, $total) {
            $this->load->language('extension/payment/dibseasy');
            if('hosted' == $this->config->get('payment_dibseasy_checkout_type')) {
               $method_data = array(
				'code'       => self::METHOD_CODE,
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => (int) $this->config->get('payment_dibseasy_sort_order'));
            }
            if('embedded' == $this->config->get('payment_dibseasy_checkout_type')) {
                $method_data = array();
            }
           return $method_data;
	}

        /*
         * The data required for checkout paget
         */
        public function getCheckoutData() {
            $data['products'] = array();
            foreach ($this->cart->getProducts() as $product) {
                    $option_data = array();
                    foreach ($product['option'] as $option) {
                            if ($option['type'] != 'file') {
                                    $value = $option['value'];
                            } else {
                                    $upload_info = $this->model_tool_upload->getUploadByCode($option['value']);
                                    if ($upload_info) {
                                            $value = $upload_info['name'];
                                    } else {
                                            $value = '';
                                    }
                            }
                            $option_data[] = array(
                                    'name'  => $option['name'],
                                    'value' => (utf8_strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value)
                            );
                    }
                    $recurring = '';
                    if ($product['recurring']) {
                            $frequencies = array(
                                    'day'        => $this->language->get('text_day'),
                                    'week'       => $this->language->get('text_week'),
                                    'semi_month' => $this->language->get('text_semi_month'),
                                    'month'      => $this->language->get('text_month'),
                                    'year'       => $this->language->get('text_year'),
                            );
                            if ($product['recurring']['trial']) {
                                    $recurring = sprintf($this->language->get('text_trial_description'), $this->currency->format($this->tax->calculate($product['recurring']['trial_price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['recurring']['trial_cycle'], $frequencies[$product['recurring']['trial_frequency']], $product['recurring']['trial_duration']) . ' ';
                            }

                            if ($product['recurring']['duration']) {
                                    $recurring .= sprintf($this->language->get('text_payment_description'), $this->currency->format($this->tax->calculate($product['recurring']['price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['recurring']['cycle'], $frequencies[$product['recurring']['frequency']], $product['recurring']['duration']);
                            } else {
                                    $recurring .= sprintf($this->language->get('text_payment_cancel'), $this->currency->format($this->tax->calculate($product['recurring']['price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['recurring']['cycle'], $frequencies[$product['recurring']['frequency']], $product['recurring']['duration']);
                            }
                    }
                    $data['products'][] = array(
                            'cart_id'    => $product['cart_id'],
                            'product_id' => $product['product_id'],
                            'name'       => $product['name'],
                            'model'      => $product['model'],
                            'option'     => $option_data,
                            'recurring'  => $recurring,
                            'quantity'   => $product['quantity'],
                            'subtract'   => $product['subtract'],
                            'price'      => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']),
                            'total'      => $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity'], $this->session->data['currency']),
                            'href'       => $this->url->link('product/product', 'product_id=' . $product['product_id'])
                    );
            }
            $data['checkoutkey'] = trim($this->config->get('payment_dibseasy_checkoutkey'));
            if($this->config->get('payment_dibseasy_testmode') == 0) {
                $data['checkoutkey'] = trim($this->config->get('payment_dibseasy_checkoutkey_live'));
            } else {
                $data['checkoutkey'] =  trim($this->config->get('payment_dibseasy_checkoutkey_test'));
            }
            $data['language'] = $this->config->get('payment_dibseasy_language');

            if($this->config->get('payment_dibseasy_testmode') == 0) {
                 $data['checkout_script'] = self::CHECKOUT_SCRIPT_LIVE;
            } else {
                 $data['checkout_script'] = self::CHECKOUT_SCRIPT_TEST;
            }
           $data['checkoutconfirmurl'] = $this->url->link('extension/payment/dibseasy/confirm', '', true);
           return $data;
        }

        public function createOrder() {
                $this->session->data['comment'] = '';
    		// Set totals
                $totals = array();
                $taxes = $this->cart->getTaxes();
                $total = 0;
               // Because __call can not keep var references so we put them into an array.
                $total_data = array(
                        'totals' => &$totals,
                        'taxes'  => &$taxes,
                        'total'  => &$total
                );
                $this->load->model('setting/extension');
                $sort_order = array();
                $results = $this->model_setting_extension->getExtensions('total');
                foreach ($results as $key => $value) {
                        $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
                }
                array_multisort($sort_order, SORT_ASC, $results);
                foreach ($results as $result) {
                        if ($this->config->get('total_' . $result['code'] . '_status')) {
                                $this->load->model('extension/total/' . $result['code']);
                                // We have to put the totals in an array so that they pass by reference.
                                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                        }
                }
                $sort_order = array();
                foreach ($totals as $key => $value) {
                        $sort_order[$key] = $value['sort_order'];
                }
                array_multisort($sort_order, SORT_ASC, $totals);
                $order_data['totals'] = $totals;
                $this->load->language('checkout/checkout');
		$order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
		$order_data['store_id'] = $this->config->get('config_store_id');
		$order_data['store_name'] = $this->config->get('config_name');
		if ($order_data['store_id']) {
			$order_data['store_url'] = $this->config->get('config_url');
		} else {
			$order_data['store_url'] = HTTP_SERVER;
		}
		if ($this->customer->isLogged()) {
			$this->load->model('account/customer');
			$customer_info = $this->model_account_customer->getCustomer($this->customer->getId());
			$order_data['customer_id'] = $this->customer->getId();
			$order_data['customer_group_id'] = $customer_info['customer_group_id'];
			$order_data['firstname'] = $customer_info['firstname'];
			$order_data['lastname'] = $customer_info['lastname'];
			$order_data['email'] = $customer_info['email'];
			$order_data['telephone'] = $customer_info['telephone'];
			$order_data['custom_field'] = json_decode($customer_info['custom_field'], true);
		} elseif (isset($this->session->data['guest'])) {
                        $order_data['customer_id'] = 0;
            		$order_data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
			$order_data['firstname'] = $this->session->data['guest']['firstname'];
			$order_data['lastname'] = $this->session->data['guest']['lastname'];
			$order_data['email'] = $this->session->data['guest']['email'];
			$order_data['telephone'] = $this->session->data['guest']['telephone'];
			$order_data['custom_field'] = $this->session->data['guest']['custom_field'];
		} else {
                        $order_data['customer_id'] = 0;
                        $order_data['customer_group_id'] = 1;
                        $order_data['firstname'] = $this->session->data['shipping_address']['firstname'];
                        $order_data['lastname'] = $this->session->data['shipping_address']['lastname'];
                        $order_data['email'] = $this->session->data['shipping_address']['email'];
                        $order_data['telephone'] = $this->session->data['payment_address']['telephone'];
                        $order_data['fax'] = '';
                }
		$order_data['payment_firstname'] = $this->session->data['payment_address']['firstname'];
		$order_data['payment_lastname'] = $this->session->data['payment_address']['lastname'];
                if(!empty($this->session->data['payment_address']['company'])) {
                    $order_data['payment_company'] = $this->session->data['payment_address']['company'];
                } else {
                    $order_data['payment_company'] = '';
                }
                $order_data['payment_address_1'] = $this->session->data['payment_address']['address_1'];
		$order_data['payment_address_2'] = $this->session->data['payment_address']['address_2'];
		$order_data['payment_city'] = $this->session->data['payment_address']['city'];
		$order_data['payment_postcode'] = $this->session->data['payment_address']['postcode'];
		$order_data['payment_zone'] = $this->session->data['payment_address']['zone'];
		$order_data['payment_zone_id'] = $this->session->data['payment_address']['zone_id'];
		$order_data['payment_country'] = $this->session->data['payment_address']['country'];
		$order_data['payment_country_id'] = $this->session->data['payment_address']['country_id'];
		$order_data['payment_address_format'] = '';
		$order_data['payment_custom_field'] = (isset($this->session->data['payment_address']['custom_field']) ? $this->session->data['payment_address']['custom_field'] : array());
		if (isset($this->session->data['payment_method']['title'])) {
			$order_data['payment_method'] = $this->session->data['payment_method']['title'];
		} else {
			$order_data['payment_method'] = '';
		}
		if (isset($this->session->data['payment_method']['code'])) {
			$order_data['payment_code'] = $this->session->data['payment_method']['code'];
		} else {
			$order_data['payment_code'] = '';
		}
		if ($this->cart->hasShipping()) {
			$order_data['shipping_firstname'] = $this->session->data['shipping_address']['firstname'];
			$order_data['shipping_lastname'] = $this->session->data['shipping_address']['lastname'];
                        if(!empty($this->session->data['shipping_address']['company'])) {
                            $order_data['shipping_company'] = $this->session->data['shipping_address']['company'];
                        }else {
                            $order_data['shipping_company'] = '';
                        }
			$order_data['shipping_address_1'] = $this->session->data['shipping_address']['address_1'];
			$order_data['shipping_address_2'] = $this->session->data['shipping_address']['address_2'];
			$order_data['shipping_city'] = $this->session->data['shipping_address']['city'];
			$order_data['shipping_postcode'] = $this->session->data['shipping_address']['postcode'];
			$order_data['shipping_zone'] = $this->session->data['shipping_address']['zone'];
			$order_data['shipping_zone_id'] = $this->session->data['shipping_address']['zone_id'];
			$order_data['shipping_country'] = $this->session->data['shipping_address']['country'];
			$order_data['shipping_country_id'] = $this->session->data['shipping_address']['country_id'];
			$order_data['shipping_address_format'] = '';
			$order_data['shipping_custom_field'] = (isset($this->session->data['shipping_address']['custom_field']) ? $this->session->data['shipping_address']['custom_field'] : array());
			if (isset($this->session->data['shipping_method']['title'])) {
				$order_data['shipping_method'] = $this->session->data['shipping_method']['title'];
			} else {
				$order_data['shipping_method'] = '';
			}
			if (isset($this->session->data['shipping_method']['code'])) {
				$order_data['shipping_code'] = $this->session->data['shipping_method']['code'];
			} else {
				$order_data['shipping_code'] = '';
			}
		} else {
			$order_data['shipping_firstname'] = '';
			$order_data['shipping_lastname'] = '';
			$order_data['shipping_company'] = '';
			$order_data['shipping_address_1'] = '';
			$order_data['shipping_address_2'] = '';
			$order_data['shipping_city'] = '';
			$order_data['shipping_postcode'] = '';
			$order_data['shipping_zone'] = '';
			$order_data['shipping_zone_id'] = '';
			$order_data['shipping_country'] = '';
			$order_data['shipping_country_id'] = '';
			$order_data['shipping_address_format'] = '';
			$order_data['shipping_custom_field'] = array();
			$order_data['shipping_method'] = '';
			$order_data['shipping_code'] = '';
		}
		$order_data['products'] = array();
		foreach ($this->cart->getProducts() as $product) {
			$option_data = array();

			foreach ($product['option'] as $option) {
				$option_data[] = array(
					'product_option_id'       => $option['product_option_id'],
					'product_option_value_id' => $option['product_option_value_id'],
					'option_id'               => $option['option_id'],
					'option_value_id'         => $option['option_value_id'],
					'name'                    => $option['name'],
					'value'                   => $option['value'],
					'type'                    => $option['type']
				);
			}
			$order_data['products'][] = array(
				'product_id' => $product['product_id'],
				'name'       => $product['name'],
				'model'      => $product['model'],
				'option'     => $option_data,
				'download'   => $product['download'],
				'quantity'   => $product['quantity'],
				'subtract'   => $product['subtract'],
				'price'      => $product['price'],
				'total'      => $product['total'],
				'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
				'reward'     => $product['reward']
			);
		}

		// Gift Voucher
		$order_data['vouchers'] = array();
		if (!empty($this->session->data['vouchers'])) {
			foreach ($this->session->data['vouchers'] as $voucher) {
				$order_data['vouchers'][] = array(
					'description'      => $voucher['description'],
					'code'             => token(10),
					'to_name'          => $voucher['to_name'],
					'to_email'         => $voucher['to_email'],
					'from_name'        => $voucher['from_name'],
					'from_email'       => $voucher['from_email'],
					'voucher_theme_id' => $voucher['voucher_theme_id'],
					'message'          => $voucher['message'],
					'amount'           => $voucher['amount']
				);
			}
		}

		$order_data['comment'] = $this->session->data['comment'];
		$order_data['total'] = $total;
		if (isset($this->request->cookie['tracking'])) {
			$order_data['tracking'] = $this->request->cookie['tracking'];
			$subtotal = $this->cart->getSubTotal();
			// Affiliate
			$this->load->model('affiliate/affiliate');
			$affiliate_info = $this->model_affiliate_affiliate->getAffiliateByCode($this->request->cookie['tracking']);
			if ($affiliate_info) {
				$order_data['affiliate_id'] = $affiliate_info['affiliate_id'];
				$order_data['commission'] = ($subtotal / 100) * $affiliate_info['commission'];
			} else {
				$order_data['affiliate_id'] = 0;
				$order_data['commission'] = 0;
			}
			// Marketing
			$this->load->model('checkout/marketing');
			$marketing_info = $this->model_checkout_marketing->getMarketingByCode($this->request->cookie['tracking']);
			if ($marketing_info) {
				$order_data['marketing_id'] = $marketing_info['marketing_id'];
			} else {
				$order_data['marketing_id'] = 0;
			}
		} else {
			$order_data['affiliate_id'] = 0;
			$order_data['commission'] = 0;
			$order_data['marketing_id'] = 0;
			$order_data['tracking'] = '';
		}
		$order_data['language_id'] = $this->config->get('config_language_id');
		$order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
		$order_data['currency_code'] = $this->session->data['currency'];
		$order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
		$order_data['ip'] = $this->request->server['REMOTE_ADDR'];
		if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
			$order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
		} elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
			$order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
		} else {
			$order_data['forwarded_ip'] = '';
		}
		if (isset($this->request->server['HTTP_USER_AGENT'])) {
			$order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
		} else {
			$order_data['user_agent'] = '';
		}
		if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
			$order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
		} else {
			$order_data['accept_language'] = '';
		}
		$this->load->model('checkout/order');
 		$this->session->data['order_id'] = $this->model_checkout_order->addOrder($order_data);
 	}
 
        protected function validateCart() {
            // Validate minimum quantity requirements.
            $products = $this->cart->getProducts();
            foreach ($products as $product) {
                    $product_total = 0;

                    foreach ($products as $product_2) {
                            if ($product_2['product_id'] == $product['product_id']) {
                                    $product_total += $product_2['quantity'];
                            }
                    }
                    if ($product['minimum'] > $product_total) {
                            $json['redirect'] = $this->url->link('checkout/cart');
                            return false;
                            break;
                    }
            }
            return true;
        }

        /**
         * 
         * @return string
         *
         */
        public function getPaymentId() {
            if(!$this->cart->hasProducts()) {
               unset($this->session->data['dibseasy']['paymentid']);
            }

            if(!empty($this->session->data['dibseasy']['paymentid'])) {
                return $this->session->data['dibseasy']['paymentid'];
            }

            $this->setPaymentMethod();
            if($this->config->get('payment_dibseasy_testmode') == 0) {
                $url = self::PAYMENT_API_LIVE_URL;
            } else {
                $url = self::PAYMENT_API_TEST_URL;
            }
            $ro = $this->createRequestObject();
            $response = $this->makeCurlRequest($url, $ro);
            if(!empty($response->errors)) {
                $resonseArray = (array) $response->errors;
                if(!empty( $resonseArray['checkout.Consumer.ShippingAddress.PostalCode'] )) {
                    unset($ro['checkout']['merchantHandlesConsumerData']);
                    $response = $this->makeCurlRequest($url, $ro);
                }
            }
            if(!empty($response->paymentId)) {
                $this->session->data['dibseasy']['paymentid'] = $response->paymentId;
                return $response->paymentId;
            } else {
                $this->logger->write($response);
            }
        }

        protected function setPaymentMethod() {
             $this->load->language('extension/payment/dibseasy');
             $this->session->data['payment_method'] =  array(
		'code'       => self::METHOD_CODE,
		'title'      => $this->language->get('text_title'),
                'sort_order' => '1');
        }

        /**
         * 
         * @param string $url
         * @param array $data
         * @param type $method
         * @return string
         */
        protected function makeCurlRequest($url, $data = array(), $method = 'POST') {
            $ch = curl_init();
            $headers[] = 'Content-Type: text/json';
            $headers[] = 'Accept: test/json';
            $headers[] = 'commercePlatformTag: OC30';
            if($this->config->get('payment_dibseasy_testmode') == 1) {
               $headers[] = 'Authorization: ' . str_replace('-', '', trim($this->config->get('payment_dibseasy_testkey')));
            } else {
               $headers[] = 'Authorization: ' . str_replace('-', '', trim($this->config->get('payment_dibseasy_livekey')));
            }
            $postData = $data;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if($postData) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
            }
            $response = curl_exec($ch);
            $info = curl_getinfo($ch);
            switch($info['http_code']) {
                
                case 401:
                  $message = 'NETS Easy authorization filed. Check you keys';
                break;
                
                case 400:
                   $message = 'NETS Easy. Bad request: ' . $response;
                break;
            
                case 404:
                    $message = 'Payment or charge not found';
                break;
            
                case 500:
                    $message = 'Unexpected error';
                break;
                
            }
            
            if(!empty($message)) {
               $this->log->write($message);
            }
            
            if(curl_error($ch)) {
               $this->log->write(curl_error($ch));
            }
           
            if($info['http_code'] == 200 || $info['http_code'] == 201 || $info['http_code'] == 400) {
                  if( $response ) {
                      $responseDecoded = json_decode($response);
                      if($this->config->get('payment_dibseasy_debug')) {
                         $this->logger->write('Curl response:');
                         $this->logger->write($response);
                   }
                   return ($responseDecoded) ? $responseDecoded : null;
               }
           }
        }

        protected function getTotalTaxRate($tax_class_id) {
             $totalRate = 0;
               foreach($this->tax->getRates(0, $tax_class_id) as $tax) {
                   if('P' == $tax['type']) {
                       $totalRate += $tax['rate'];
                   }
               }
              return $totalRate;
        }

        /*
         * Generate request object in json format that will be sended to API
         * @return array
         * 
         */
        public function createRequestObject() {
            $this->load->model('checkout/order');
            // add consumer type
            $customerType = $this->config->get('payment_dibseasy_allowed_customer_type');
            $supportedTypes = array();
            $consumerType = array();
            if(trim($customerType)) {
                $default = null;
                switch($customerType) {
                    case 'b2c' :
                        $supportedTypes = array('B2C');
                        $default = 'B2C';
                        break;
                    case 'b2b':
                        $supportedTypes = array('B2B');
                        $default = 'B2B';
                        break;
                    case 'b2c_b2b_b2c':
                        $supportedTypes = array('B2C', 'B2B');
                        $default = 'B2C';
                        break;
                    case 'b2b_b2c_b2b':
                        $supportedTypes = array('B2C', 'B2B');
                        $default = 'B2B';
                        break;
                }
                
              $consumerType = array('supportedTypes'=>$supportedTypes,'default'=>$default);
            }
            $data = array(
                'order' => array(
                    'items' => $this->getRequestObjectItems(),
                    'amount' => $this->getNetsIntValue($this->getGrandTotal()),
                    'currency' => $this->session->data['currency'],
                    'reference' => uniqid('opc_')),
                 'checkout' => array(
                        'termsUrl' => $this->config->get('payment_dibseasy_terms_and_conditions'),
                    ),
                );
            if('embedded' == $this->config->get('payment_dibseasy_checkout_type')) {
                $data['checkout']['url'] = $this->url->link('extension/payment/dibseasy/confirm', '', true);
            }
            if('hosted' == $this->config->get('payment_dibseasy_checkout_type')) {
                
                if('b2c' ==  $customerType) {
                    
                    $order_data = [];
                    $telephone = null;
                    if ($this->customer->isLogged()) {
                            $this->load->model('account/customer');
                            $customer_info = $this->model_account_customer->getCustomer($this->customer->getId());
                            $order_data['customer_id'] = $this->customer->getId();
                            $order_data['customer_group_id'] = $customer_info['customer_group_id'];
                            $order_data['firstname'] = $customer_info['firstname'];
                            $order_data['lastname'] = $customer_info['lastname'];
                            $order_data['email'] = $customer_info['email'];
                            $order_data['telephone'] = $customer_info['telephone'];
                            $order_data['custom_field'] = json_decode($customer_info['custom_field'], true);
                            $telephone = $order_data['telephone'];
                            $email = $order_data['email'];

                    }elseif (isset($this->session->data['guest'])) {
                            $order_data['customer_id'] = 0;
                            $order_data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
                            $order_data['firstname'] = $this->session->data['guest']['firstname'];
                            $order_data['lastname'] = $this->session->data['guest']['lastname'];
                            $order_data['email'] = $this->session->data['guest']['email'];
                            $order_data['telephone'] = $this->session->data['guest']['telephone'];
                            $order_data['custom_field'] = $this->session->data['guest']['custom_field'];
                            $email = $order_data['email'];
                            $telephone = $order_data['telephone'];
                    }
                    
                     $phonePrefix = substr($telephone, 0, 3);
                     $number = substr($telephone, 3);
                     $consumerData['phoneNumber'] = ['prefix' => $phonePrefix, 'number' => $number];

                     $consumerData = array(
                        'email' => $email,
                        "shippingAddress" => array(
                            "addressLine1"=> !empty($this->session->data['shipping_address']['address_1']) ? $this->session->data['shipping_address']['address_1']: null,
                            "addressLine2"=> !empty($this->session->data['shipping_address']['address_2']) ? $this->session->data['shipping_address']['address_2']: null,
                            "postalCode"=> !empty($this->session->data['shipping_address']['postcode']) ? $this->session->data['shipping_address']['postcode']: null,
                            "city"=> !empty($this->session->data['shipping_address']['city']) ? $this->session->data['shipping_address']['city']: null,
                            "country"=> !empty($this->session->data['shipping_address']['iso_code_3']) ? $this->session->data['shipping_address']['iso_code_3']: null
                          ),
                         'privatePerson' => array(
                            'firstName' => !empty($this->session->data['shipping_address']['firstname']) ?$this->session->data['shipping_address']['firstname']: 'FirstName',
                            'lastName' => !empty($this->session->data['shipping_address']['lastname']) ? $this->session->data['shipping_address']['lastname']: 'LastName',
                       )
                     );



                     
                     if( 'b2c' ==  $customerType && $this->validateAddress($consumerData) ) {
                         $data['checkout']['consumer'] = $consumerData;
                         $data['checkout']['merchantHandlesConsumerData'] = true;
                     }
                     
               }
                 $data['checkout']['returnUrl'] = $this->url->link('extension/payment/dibseasy/confirm', '', true);
                 $data['checkout']['integrationType'] = 'HostedPaymentPage';
            }
            
            if($consumerType) {
                $checkout = $data['checkout'];
                $checkout['consumerType'] = $consumerType;
                $data['checkout'] = $checkout;
            }
            if($this->config->get('payment_dibseasy_debug')) {
                   $this->logger->write("Collected data:");
                   $this->logger->write($data);
            }
            return $data;
        }

        public function getRequestObjectItems() {



            $this->load->model('checkout/order');
            $items = array();
            foreach ($this->cart->getProducts() as $product) {
                $netPrice = $this->formatPrice($product['price']);

                $rates = $this->cart->tax->getRates($netPrice, $product['tax_class_id']);

                $taxAmount = $this->cart->tax->getTax($netPrice, $product['tax_class_id']);

                $taxRate = $taxAmount / ( $netPrice / 100 );

                $taxAmount = $this->formatPrice( $taxAmount );

                $grossTotalAmount = $this->formatPrice($netPrice) + $this->formatPrice($taxAmount);

                $qty = $product['quantity'];

                $items[] = array(
                    'reference' => $product['product_id'],
                    'name' => str_replace(array('\'', '&'), '', $product['name']),
                    'quantity' => $qty,
                    'unit' => 'pcs',
                    'unitPrice' => $this->getNetsIntValue($netPrice),
                    'taxRate' => $this->getNetsIntValue($taxRate),
                    'taxAmount' => $this->getNetsIntValue($taxAmount * $qty),
                    'grossTotalAmount' => $this->getNetsIntValue($grossTotalAmount * $qty),
                    'netTotalAmount' => $this->getNetsIntValue($netPrice * $qty));
            }


            if(!empty($this->session->data['shipping_method']['cost'])) {

                $shippingNetPrice = $this->formatPrice( (float)$this->session->data['shipping_method']['cost']);

                $taxAmount = $this->cart->tax->getTax($shippingNetPrice, $this->session->data['shipping_method']['tax_class_id']);

                $taxRate = $taxAmount / ( $shippingNetPrice / 100 );

                $grossShippingPtice = $this->formatPrice($shippingNetPrice) + $this->formatPrice($taxAmount);

                $items[] = array(
                    'reference' => 'Shipping',
                    'name' => 'shipping',
                    'quantity' => 1,
                    'unit' => 'pcs',
                    'unitPrice' => $this->getNetsIntValue($shippingNetPrice),
                    'taxRate' => $this->getNetsIntValue($taxRate),
                    'taxAmount' => $this->getNetsIntValue($taxAmount),
                    'grossTotalAmount' => $this->getNetsIntValue($grossShippingPtice),
                    'netTotalAmount' => $this->getNetsIntValue($shippingNetPrice));
            }

            $totals = $this->getTotals(false);
            foreach($totals['totals'] as $total) {
                  if( in_array($total['code'], $this->additional_totals()) && abs($total['value']) > 0) {
                        $netPrice = $total['value'];
                        $taxAmount = 0;
                        $taxRate = 0;
                    $items[] = array(
                        'reference' => $total['code'],
                        'name' => $total['title'],
                        'quantity' => 1,
                        'unit' => 1,
                        'unitPrice' => $this->getNetsIntValue($netPrice),
                        'taxRate' => $taxRate,
                        'taxAmount' => $taxAmount,
                        'grossTotalAmount' => $this->getNetsIntValue($netPrice),
                        'netTotalAmount' => $this->getNetsIntValue($netPrice));
                  }
              }
              $itemsPriceSumma = 0;
              foreach($items as $total) {
                  $itemsPriceSumma += $total['grossTotalAmount'];
              }

              $orderGrandTotal = $this->getNetsIntValue($this->getGrandTotal());
              if ($orderGrandTotal != $itemsPriceSumma) {
                  $delta =  $orderGrandTotal - $itemsPriceSumma;
                  $items[] = array(
                      'reference' => 'rounding',
                      'name' => 'rounding',
                      'quantity' => 1,
                      'unit' => 1,
                      'unitPrice' => $delta,
                      'taxRate' => 0,
                      'taxAmount' => 0,
                      'grossTotalAmount' => $delta,
                      'netTotalAmount' => $delta);
              }
             return $items;
        }

        /**
         * 
         * @param type $transactionId
         * @return string | json object
         */
        public function getTransactionInfo($transactionId) {
             if($this->config->get('payment_dibseasy_testmode') == 1) {
                  $url = str_replace('{transactionId}', $transactionId, self::PAYMENT_TRANSACTION_URL_PATTERN_TEST);
             } else {
                  $url = str_replace('{transactionId}', $transactionId, self::PAYMENT_TRANSACTION_URL_PATTERN_LIVE);
             }
            return $this->makeCurlRequest($url, array(), 'GET');
        }

        public function getCountryByIsoCode3($iso_code_3) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "country WHERE `iso_code_3` = '" . $this->db->escape($iso_code_3) . "' AND `status` = '1'");
		return $query->row;
	}

        public function setAddresses($order_id, $data) {
            $setFields = '';
            foreach($data as $key => $value) {
               $setFields .= '`'.$key. '`' . "='" . $this->db->escape($value) . "',";
            }
            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET ". $setFields ." date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
        }

        public function getTotals($number_format = false) {
            $this->load->model('setting/extension');
            $this->load->language('checkout/dibseasy');

            $total_translations =
                ['sub_total' => $this->language->get('totals_subtotal_label'),
                 'shipping' => $this->language->get('totals_subtotal_shipping'),
                 'tax' =>   $this->language->get('totals_tax_label')
                ];

            $totals = array();
            $taxes = $this->cart->getTaxes();
            $total = 0;
            $total_data = array(
                    'totals' => &$totals,
                    'taxes'  => &$taxes,
                    'total'  => &$total
            );
            $sort_order = array();
            $results = $this->model_setting_extension->getExtensions('total');
            foreach ($results as $key => $value) {
                    $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
            }
            array_multisort($sort_order, SORT_ASC, $results);
            foreach ($results as $result) {
                    if ($this->config->get('total_' . $result['code'] . '_status')) {
                            $this->load->model('extension/total/' . $result['code']);
                            $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                    }
            }

            $taxTotal = array_sum($taxes);

            $decimal_place = $this->currency->getDecimalPlace($this->session->data['currency']);
            foreach($total_data['totals'] as $d) {
                $value = $d['value'];

                $code = $d['code'];
                if(isset($total_translations[$code])) {
                    $d['title'] = $total_translations[ $code ];
                    error_log($code);

                }

                if($number_format) {
                    $d['value'] = number_format($this->formatPrice($value), $decimal_place, ".", ",");
                } else {
                    $d['value'] = $this->formatPrice($value);
                }
                     $totals_rounded[] = $d;
               }
            $total_data['totals'] = $totals_rounded;

            if($taxTotal > 0) {
                $total_data['totals'][] = ['code' => 'tax_total_value',
                                           'title' => $this->language->get('totals_tax_label'),
                                           'value' =>  ($number_format == true) ? number_format($taxTotal ,$decimal_place, ".", ","): $taxTotal,
                                           'sort_order' => -1];
            }
            return $total_data;
        }

       /**
        * 
        * @return string
        */
       public function getGrandTotal() {
           $totals = $this->getTotals(false);
           $total = 0;
           foreach($totals['totals'] as $total) {
               if ($total['code'] == 'total') {
                   $total = $total['value'];
                   break;
               }
           }
           return $total;
       }

        protected function additional_totals() {
            return array('coupon');
        }

        public function getTaxAmount($value, $tax_class_id) {
            $amount = 0;
            $tax_rates = $this->tax->getRates($value,  $tax_class_id);
            foreach ($tax_rates as $tax_rate) {
                  if($tax_rate['type'] == 'F') {
                       $amount +=  $this->currency->format($tax_rate['amount'], $this->session->data['currency'], '', false);
                    } else {
                       $amount += $tax_rate['amount'];
                    }
            }
            $decimal_places = $this->currency->getDecimalPlace($this->session->data['currency']);
            if($decimal_places) {
                $amount = round($amount, $this->currency->getDecimalPlace($this->session->data['currency']));
            } else {
                $amount = round($amount);
            }
            return $amount;
	}

        /**
         * Get available shipping methods based on shipping address
         *
         * @return array
         * @throws Exception
         */
        public function getShippingMethods() {
            $result = array();
            if(!$this->cart->hasShipping()) {
                return $result;
            }
            $this->load->language('checkout/checkout');
            if (isset($this->session->data['shipping_address'])) {
                // Shipping Methods
                $method_data = array();

                $this->load->model('setting/extension');

                $results = $this->model_setting_extension->getExtensions('shipping');

                foreach ($results as $result) {
                        if ($this->config->get('shipping_' . $result['code'] . '_status')) {
                                $this->load->model('extension/shipping/' . $result['code']);

                                $quote = $this->{'model_extension_shipping_' . $result['code']}->getQuote($this->session->data['shipping_address']);

                                if (!empty($quote['quote'])) {
                                        $method_data[$result['code']] = array(
                                                'title'      => $quote['title'],
                                                'quote'      => $quote['quote'],
                                                'sort_order' => $quote['sort_order'],
                                                'error'      => $quote['error']
                                        );
                                }
                        }
                }

                $sort_order = array();

                foreach ($method_data as $key => $value) {
                        $sort_order[$key] = $value['sort_order'];
                }

                array_multisort($sort_order, SORT_ASC, $method_data);

                $result = $method_data;

                if($method_data) {
                    $method = current($method_data);
                    $quote = $method['quote'];
                    $current = current($quote);
                    $code = $current['code'];

                    // Set the first available shipping method
                    if(!isset($this->session->data['shipping_method'])) {
                      $this->setShippingMethod($code);
                    }

                    // If shipping from session is not in shippings list 
                    // set the first available shipping method 
                    if(isset($this->session->data['shipping_method'])) {

                        $sessinMethodIsInMethods = false;
                        foreach($method_data as $md) {
                              $quote = $md['quote'];
                              foreach($quote as $current) {
                                $cd = $current['code'];
                                if($cd == $this->session->data['shipping_method']['code']) {
                                     $sessinMethodIsInMethods = true;
                                }
                              }

                        }
                        if(!$sessinMethodIsInMethods) {
                             $this->setShippingMethod($code);
                        }
                    }
                }
                if($this->cart->hasShipping() && !$result) {
                    throw new Exception('No shipping methods available for current address');
                }
            }
            return $result;
        }

        /**
         * Set shipping method based on shipping code
         * 
         * @param type $shippingCode
         */
        public function setShippingMethod($shippingCode = null) {
           if ($this->validateCart() && $this->cart->hasShipping()) {
                $json['shipping_methods'] = array();
                $this->load->model('setting/extension');
                $shipping = explode('.', $shippingCode);
                	// Shipping Methods
			$method_data = array();
			$this->load->model('setting/extension');
			$results = $this->model_setting_extension->getExtensions('shipping');
			foreach ($results as $result) {
				if ($this->config->get('shipping_' . $result['code'] . '_status')) {
					$this->load->model('extension/shipping/' . $result['code']);

					$quote = $this->{'model_extension_shipping_' . $result['code']}->getQuote($this->session->data['shipping_address']);

					if ($quote) {
						$method_data[$result['code']] = array(
							'title'      => $quote['title'],
							'quote'      => $quote['quote'],
							'sort_order' => $quote['sort_order'],
							'error'      => $quote['error']
						);
					}
				}
			}
			$sort_order = array();
			foreach ($method_data as $key => $value) {
				$sort_order[$key] = $value['sort_order'];
			}
			array_multisort($sort_order, SORT_ASC, $method_data);
			$this->session->data['shipping_methods'] = $method_data;
                if($this->session->data['shipping_methods']) {
                    $this->session->data['shipping_method'] = $this->session->data['shipping_methods'][$shipping[0]]['quote'][$shipping[1]];
                    $this->updateCart();
                }
             }
       }

       /**
        * Update cart on Easy side:
        * https://tech.dibspayment.com/easy/api/rest/update-cart
        */
       public function updateCart() 
       {
           $totals = $this->getTotals(false);
           $requestData = array(
             'amount' => round($this->currency->format($totals['total'], $this->session->data['currency'], '', false) * 100),
             'items' => $this->getRequestObjectItems(),
             'shipping' => ['costSpecified' => true]
           );
           $paymentId = $this->session->data['dibseasy']['paymentid'];
           $this->makeCurlRequest($this->getApiUrlPrefix() . $paymentId . '/orderitems', $requestData, 'PUT');
       }

       /**
        * Retrieve payment by id
        */
       public function getPayment($paymentId) {
           $url = $this->getApiUrlPrefix() . $paymentId;
           return $this->makeCurlRequest($url, null, 'GET');
       }

       /**
        * Set shipping address from payment 
        * object and store in session 
        */
       public function setShippingAddress() {
               $paymentid = $this->session->data['dibseasy']['paymentid'];
               $paymentObject = $this->model_extension_payment_dibseasy->getPayment($paymentid);
               if(isset($paymentObject->payment->consumer->company->name)) {
                   if(isset($paymentObject->payment->consumer->company->contactDetails->firstName)) {
                     $this->session->data['shipping_address']['firstname'] = $paymentObject->payment->consumer->company->contactDetails->firstName; 
                   }
                   if(isset($paymentObject->payment->consumer->company->contactDetails->lastName)) {
                     $this->session->data['shipping_address']['lastname'] = $paymentObject->payment->consumer->company->contactDetails->lastName;
                   }
                   if(isset($paymentObject->payment->consumer->company->contactDetails->email)) {
                     $this->session->data['shipping_address']['email'] = $paymentObject->payment->consumer->company->contactDetails->email;
                   }
               } else {
                   if(isset($paymentObject->payment->consumer->privatePerson->firstName)) {
                     $this->session->data['shipping_address']['firstname'] = $paymentObject->payment->consumer->privatePerson->firstName; 
                   }
                   if(isset($paymentObject->payment->consumer->privatePerson->lastName)) {
                     $this->session->data['shipping_address']['lastname'] = $paymentObject->payment->consumer->privatePerson->lastName;
                   }
                   if(isset($paymentObject->payment->consumer->privatePerson->email)) {
                     $this->session->data['shipping_address']['email'] = $paymentObject->payment->consumer->privatePerson->email;
                   }
               }
               if($paymentObject->payment->consumer->shippingAddress->addressLine1) {
                  $this->session->data['shipping_address']['address_1'] = $paymentObject->payment->consumer->shippingAddress->addressLine1;
               }
               $this->session->data['shipping_address']['address_2'] = '';
               if($paymentObject->payment->consumer->shippingAddress->city) {
                  $this->session->data['shipping_address']['city'] = $paymentObject->payment->consumer->shippingAddress->city;
               }
               if($paymentObject->payment->consumer->shippingAddress->postalCode) {
                  $this->session->data['shipping_address']['postcode'] = $paymentObject->payment->consumer->shippingAddress->postalCode;
               }

               // we can't detect shipping zone, leave it blank for now
               $this->session->data['shipping_address']['zone'] = null;
               $this->session->data['shipping_address']['zone_id'] = null;
               if($paymentObject->payment->consumer->shippingAddress->country) {
                   $this->session->data['shipping_address']['country'] = $this->getCountryName($paymentObject->payment->consumer->shippingAddress->country);
                   $this->session->data['shipping_address']['country_id'] = $this->getCountryId($paymentObject->payment->consumer->shippingAddress->country);
               }
               $this->tax->setShippingAddress($this->session->data['shipping_address']['country_id'], $this->session->data['shipping_address']['zone_id']);
               $this->setPaymentAddress();
               $this->updateCart();
       }

       protected function setPaymentAddress() {
           $paymentid = $this->session->data['dibseasy']['paymentid'];
           $paymentObject = $this->model_extension_payment_dibseasy->getPayment($paymentid);
           if(isset($paymentObject->payment->consumer->company->name)) {

               if(isset($paymentObject->payment->consumer->company->contactDetails->firstName)) {
                 $this->session->data['payment_address']['firstname'] = $paymentObject->payment->consumer->company->contactDetails->firstName; 
               }
               if(isset($paymentObject->payment->consumer->company->contactDetails->lastName)) {
                 $this->session->data['payment_address']['lastname'] = $paymentObject->payment->consumer->company->contactDetails->lastName;
               }
               $this->session->data['payment_address']['company'] = $paymentObject->payment->consumer->company->name;
               if(isset($paymentObject->payment->consumer->company->contactDetails->email)) {
                 $this->session->data['payment_address']['email'] = $paymentObject->payment->consumer->company->contactDetails->email;
               }
               $this->session->data['payment_address']['telephone'] = $paymentObject->payment->consumer->company->contactDetails->phoneNumber->prefix;
               if(isset($paymentObject->payment->consumer->company->contactDetails->phoneNumber->prefix) && 
                       isset($paymentObject->payment->consumer->company->contactDetails->phoneNumber->number)) {
                  $this->session->data['payment_address']['telephone'] = $paymentObject->payment->consumer->company->contactDetails->phoneNumber->prefix .
                          $paymentObject->payment->consumer->company->contactDetails->phoneNumber->number;
               }
           } else {
               if(isset($paymentObject->payment->consumer->privatePerson->firstName)) {
                 $this->session->data['payment_address']['firstname'] = $paymentObject->payment->consumer->privatePerson->firstName; 
               }
               if(isset($paymentObject->payment->consumer->privatePerson->lastName)) {
                 $this->session->data['payment_address']['lastname'] = $paymentObject->payment->consumer->privatePerson->lastName;
               }
               if(isset($paymentObject->payment->consumer->privatePerson->email)) {
                 $this->session->data['payment_address']['email'] = $paymentObject->payment->consumer->privatePerson->email;
               }
               if(isset($paymentObject->payment->consumer->privatePerson->phoneNumber->prefix) && 
                       isset($paymentObject->payment->consumer->privatePerson->phoneNumber->number)) {
                  $this->session->data['payment_address']['telephone'] = $paymentObject->payment->consumer->privatePerson->phoneNumber->prefix .
                          $paymentObject->payment->consumer->privatePerson->phoneNumber->number;
               }
           }
           if($paymentObject->payment->consumer->shippingAddress->addressLine1) {
              $this->session->data['payment_address']['address_1'] = $paymentObject->payment->consumer->shippingAddress->addressLine1;
           }
	   $this->session->data['payment_address']['address_2'] = '';
           if($paymentObject->payment->consumer->shippingAddress->city) {
              $this->session->data['payment_address']['city'] = $paymentObject->payment->consumer->shippingAddress->city;
           }
           if($paymentObject->payment->consumer->shippingAddress->postalCode) {
              $this->session->data['payment_address']['postcode'] = $paymentObject->payment->consumer->shippingAddress->postalCode;
           }
           // we can't detect payment zone, leave it blank for now
           $this->session->data['payment_address']['zone'] = null;
           $this->session->data['payment_address']['zone_id'] = null;
           if($paymentObject->payment->consumer->shippingAddress->country) {
               $this->session->data['payment_address']['country'] = $this->getCountryName($paymentObject->payment->consumer->shippingAddress->country);
               $this->session->data['payment_address']['country_id'] = $this->getCountryId($paymentObject->payment->consumer->shippingAddress->country);
           }
       }

       public function start() {
           if(isset($this->session->data['dibseasy']['paymentid'])) {
            $this->updateCart();
           }
       }

       protected function getApiUrlPrefix() {
           $urlPrefix = '';
           if($this->config->get('payment_dibseasy_testmode') == 1) {
              $urlPrefix = 'https://test.api.dibspayment.eu/v1/payments/';
            } else {
                $urlPrefix = 'https://api.dibspayment.eu/v1/payments/';
            }
            return $urlPrefix;
      }

      protected function getCountryId($country_code) {
		$row = $this->db->query("SELECT `country_id` FROM `" . DB_PREFIX . "country` WHERE LOWER(`iso_code_3`) = '" . $this->db->escape(strtolower($country_code)) . "'")->row;
		if (isset($row['country_id']) && !empty($row['country_id'])) {
			return (int)$row['country_id'];
		}
		return 0;
      }

      protected function getCountryName($country_code) {
		$row = $this->db->query("SELECT `name` FROM `" . DB_PREFIX . "country` WHERE LOWER(`iso_code_3`) = '" . $this->db->escape(strtolower($country_code)) . "'")->row;
		if (isset($row['name']) && !empty($row['name'])) {
                    return $row['name'];
                }
                return '';
      }

      /**
     * Get phone from customers address
     * 
     * @return array | bool
     */
    protected function extractPhone($phone = null) 
    {
           $valid = true;
           $prefix = '';
           $countryCode = $this->session->data['shipping_address']['iso_code_3'];
           
           switch($countryCode) {
                case 'NO':
                    $prefix = '+47';
                break;
                case 'SWE':
                    $prefix = '+46';
                break;
                case 'DK':
                    $prefix = '+45';
                break;
                default:
                    $prefix = '';
            }
            $phoneCleaned = str_replace(array('-','(', ')',' '),'', $phone);
            if(empty($prefix)) {
                if(preg_match('/^\+[0-9]{8,15}/', $phoneCleaned) ) {
                    $prefix = substr($phoneCleaned, 0, 3);
                    if(empty($prefix)) {
                        $valid = false;
                    }
                    $postfix = substr($phoneCleaned, 3);
                } else {
                    $valid = false;
                }
            } else {
                 if(preg_match('/^\+?[0-9]{8,15}/', $phoneCleaned) ) {
                    $postfix = substr($phoneCleaned, -9);
                } else {
                   $valid = false;
                }
            }
           if($valid) {
              return array('prefix' => $prefix, 'number' => $postfix);
           }
           else return false;
    }

    function validateAddress($address) {
        return !empty($address['shippingAddress']['country'])
            && !empty($address['shippingAddress']['postalCode'])
            && (!empty($address['shippingAddress']['addressLine1'])
            || !empty( $address['shippingAddress']['addressLine1']));
    }

    public function debug($prefix = '', $data) {
           ob_start();
           echo $prefix . "\n";
           //echo "<pre>";
           var_dump($data);
           //echo "</pre>";
           $result = ob_get_clean();
           error_log($result);
    }

    private function formatPrice($value) {
        return $this->format($value, $this->session->data['currency'], '', false);
    }

    private function getNetsIntValue($value) {
        return (int) ($value * 100);
    }

    private function format($number, $currency, $value = '', $format = true) {
        $decimal_place = $this->currency->getDecimalPlace($currency);
        if(empty($decimal_place)) {
            $decimal_place = 2;
        }
        if (!$value) {
            $value = $this->currency->getValue($currency);
        }
        $amount = $value ? (float)$number * $value : (float)$number;
        $amount = round($amount, (int)$decimal_place);
        return $amount;
    }

    private function getCartHash() {
        $cart = $this->cart;
        $cartProducts = $cart->getProducts();
        return md5(serialize($cartProducts) . $this->session->getId());
    }
}
