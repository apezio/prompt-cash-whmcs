<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function pcash_Config() {
    return array(
     "FriendlyName" => array("Type" => "System", "Value"=>"Prompt.Cash"),
     "token" => array("FriendlyName" => "Public Token", "Type" => "text", "Size" => "32", ),
     "ipn_secret" => array("FriendlyName" => "Secret Token", "Type" => "password", "Size" => "32", ),
	//1 for full price. 0.95 to give a 5% discount, etc (currently disabled)
     "discount_percentage" => array("FriendlyName" => "Discount Percentage", "Type" => "text", "Size" => "3", "Value"=>"0%" )
    );
}

function pcash_getRandomString() {
    $chars = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $max = strlen($chars)-1;
    mt_srand();
    $random = '';
    for ($i = 0; $i < 12; $i++)
        $random .= $chars[mt_rand(0, $max)];
    return $random;
}



function pcash_link($params) {
	$gatewaymodule = "pcash"; # Enter your gateway module name here replacing template
	$gateway = getGatewayVariables($gatewaymodule);
	if(!$gateway["type"]) die("Module not activated");



//	$discount = money_format('%i', $params['amount']);

	if ($discount_percentage != '0') {

		//strip non-numbers
		$discount_percentage = 100 - (preg_replace("/[^0-9]/", "", $params['discount_percentage']));
		$total_after_discount = $params['amount'] * ($discount_percentage / 100);
		$amount_due = money_format('%i', $total_after_discount);
	} else {
		$amount_due = money_format('%i', $params['amount']);
	}

//	logModuleCall($gatewaymodule, "pcash_link", "", "", $discount_setting, "");

	$rand_string = pcash_getRandomString();

	$secretKey = $gateway['ipn_secret'];
	$hash = md5($params['invoiceid'] . $amount_due . $secretKey);

	$fields = array(
		'cmd' => '_pay_auto',
		'token' => $params['token'],
		'reset' => '1',
		'invoice' => $params['invoiceid'],
		'clientid' => $params['clientdetails']['id'],
		'email' => $params['clientdetails']['email'],
		'first_name' => $params['clientdetails']['firstname'],
		'last_name' => $params['clientdetails']['lastname'],
		'tx_id' => $rand_string . '_' . $params['clientdetails']['id'] . '_' . $params['invoiceid'] . '_' . $hash . '_' . $params['discount_percentage'], 
		'amount' => $amount_due,
		'currency' => $params['currency'],
		'desc' => $params["description"],

		// the URL to send the customer back to after payment
		'return' =>  $params['systemurl'] . '/viewinvoice.php?id=' . $params['invoiceid'],

		// Where to notify you of changes in the payment status (expired or paid).
		// This must be on a public domain. The callback will not work when you are testing on localhost!
		// Requires a valid SSL certificate
		'callback' => $params['systemurl'] .'/modules/gateways/callback/pcashcallback.php',
		'time' => time(),
		'signature' => '',
	);

	// Create the payment form and button
	$code = '<form name="prompt-cash-form" action="https://prompt.cash/pay" method="get">';
	foreach ($fields as $n => $v) {
		$code .= '<input type="hidden" name="'.$n.'" value="'.htmlspecialchars($v).'" />';
		//Uncomment for debugging on viewinvoice.php 
		//echo $n ." ". $v."<br>";
	}
	if ($discount_percentage == "0") {
		$code .=    '<button type="submit" class="btn btn-primary">Pay with Bitcoin Cash (BCH)</button>';
	} else {
		$code .=    '<button type="submit" class="btn btn-primary">Pay with Bitcoin Cash (BCH) ('.(100-$discount_percentage) .'% discount)</button>';
	}
	$code .= '</form>';
	return $code;
}
