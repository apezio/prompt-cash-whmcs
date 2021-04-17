<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function promptcash_Config() {
    return array(
     "FriendlyName" => array("Type" => "System", "Value"=>"Prompt.Cash"),
     "token" => array("FriendlyName" => "Public Token", "Type" => "text", "Size" => "32", ),
     "ipn_secret" => array("FriendlyName" => "Secret Token", "Type" => "password", "Size" => "32", ),
     "discount_percentage" => array("FriendlyName" => "Discount Percentage", "Type" => "text", "Size" => "3", )
    );
}



function promptcash_getRandomString() {
    $chars = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $max = strlen($chars)-1;
    mt_srand();
    $random = '';
    for ($i = 0; $i < 12; $i++)
        $random .= $chars[mt_rand(0, $max)];
    return $random;
}


function promptcash_link($params) {


	$gatewaymodule = "promptcash"; # Enter your gateway module name here replacing template
	$gateway = getGatewayVariables($gatewaymodule);
	if(!$gateway["type"]) die("Module not activated");

	$discount = $params['amount'] * $params['discount_percentage'];
	$rand_string = promptcash_getRandomString();

	$secretKey = $gateway['ipn_secret'];
	$hash = md5($params['invoiceid'] . $discount . $secretKey);

	$fields = array(
		'cmd' => '_pay_auto',

		//same as token below
		'token' => $params['token'],
		'reset' => '1',
		'invoice' => $params['invoiceid'],
		'amountf' => $params['amount'],
		'clientid' => $params['clientdetails']['id'],
		'email' => $params['clientdetails']['email'],
		'first_name' => $params['clientdetails']['firstname'],
		'last_name' => $params['clientdetails']['lastname'],
//		'success_url' => $params['systemurl'],
//		'cancel_url' => $params['systemurl'],
	//	'tx_id' => $params['invoiceid'],


	    'tx_id' => $rand_string . '_' . $params['clientdetails']['id'] . '_' . $params['invoiceid'] . '_' . $hash, 



		'amount' => $discount,

//    'amount' => sprintf("%.2f", 0.01), // make sure to use string with fixed decimals to avoid floating point inconsistency
		'currency' => $params['currency'],
	    'desc' => $params["description"],

    // the URL to send the customer back to after payment
		'return' =>  $params['systemurl'] . '/viewinvoice.php?id=' . $params['invoiceid'],

    // Where to notify you of changes in the payment status (expired or paid).
    // This must be on a public domain. The callback will not work when you are testing on localhost!
		'callback' => $params['systemurl'] .'/modules/gateways/callback/promptcashcallback.php',


	    'time' => time(),
    	'signature' => '',
);
		$code = '<form name="prompt-cash-form" action="https://prompt.cash/pay" method="get">';
        foreach ($fields as $n => $v) {
                $code .= '<input type="hidden" name="'.$n.'" value="'.htmlspecialchars($v).'" />';
echo $n ." ". $v."<br>";
        }
  
		//$code .=    '<input type="hidden" name="tx_id" value="'.$params['invoiceid'].$discount.'">';
		$code .=    '<button type="submit" class="btn btn-primary">Pay with Bitcoin Cash (BCH)</button>';
		$code .= '</form>';


	return $code;
}
