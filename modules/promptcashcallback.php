<?php
// This file gets called when the payment status changes (paid or expired).

//todo fill $message var
//	fix discount percent, check for null field for no discount etc.

include("../../../init.php"); 
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

use Illuminate\Database\Capsule\Manager as Capsule;


$gatewaymodule = "promptcash"; # Enter your gateway module name here replacing template
$GATEWAY = getGatewayVariables($gatewaymodule);
if (!$GATEWAY["type"]) die("Module Not Activated"); # Checks gateway module is active before accepting callback

$secretKey = $GATEWAY['ipn_secret'];

// The content type to respond is ignored by Prompt.Cash but you should return HTTP Status Code 200
header('Content-Type: text/plain; charset=UTF-8');
echo "okz"; // any response is fine (ignored by Prompt.Cash)

// Read the application/json POST data.
// Afterwards you can access JSON variables like $post['payment']['status']
// similar to $post for url-encoded form data.
$post = json_decode(file_get_contents('php://input'), true);


if (empty($post)) {
    echo "no data received";
}



$crypto_txn_id = $post['payment']['hash'];
$payment_id = $post['payment']['tx_id'];
$client_id = explode('_', $payment_id)[1];
$invoice_id = explode('_', $payment_id)[2];
$hash = explode('_', $payment_id)[3];
$amount_crypto = $post['payment']['amount_crypto'];
$amount_fiat = $post['payment']['amount_fiat'];
$currency = $post['payment']['fiat_currency'];

//debugging to whmcs Gateway Log
//	logTransaction($gatewaymodule, $hash, "Error Log: ".$message);


// check if the payment is complete
if ($post['token'] === $secretKey) { // prevent spoofing

	$invoice_id = checkCbInvoiceID($invoice_id, $gatewaymodule);


	$hash2 = md5($invoice_id . $amount_fiat . $secretKey);
	if ($hash != $hash2) {
		logTransaction($gatewaymodule, 'Invoice: ' . $invoice_id . ' Amount: ' . $amount_fiat . ' Hash1: ' . $hash . ' Hash2: ' . $hash2, "Error: Hash Verification Failure");
		return 'Hash Verification Failure';
	}

    if ($post['payment']['status'] === 'PAID') {
        // Payment complete. Update your database and ship your order.
		return handle_whmcs($invoice_id, $amount_crypto, $amount_fiat, $crypto_txn_id, $payment_id, $currency, $gatewaymodule, $client_id);

    }
    else if ($post['payment']['status'] === 'EXPIRED') {
        // The customer did not pay in time. You can cancel this order or send him a new payment link.
    }
return;
}



function handle_whmcs($invoice_id, $amount_crypto, $amount_fiat, $crypto_txn_id, $payment_id, $currency, $gatewaymodule, $client_id) {
	global $currency_symbol;
	
	//check if unique transaction already exists in WHMCS
	$record = Capsule::table('tblaccounts')->where('transid', $payment_id)->get();
	$transaction_exists = $record[0]->transid;
	if (!$transaction_exists) {
		//check one more time then add the payment if the transaction has not been added.
		checkCbTransID($payment_id);
		add_payment("AddInvoicePayment", $invoice_id, $crypto_txn_id, $gatewaymodule, $amount_fiat, $amount_crypto, $payment_id, $client_id);
	}
	$command = 'GetInvoice';
	$postData = array(
		'invoiceid' => $invoice_id,
	);
	$results = localAPI($command, $postData, $adminUsername);
	if ($results['status'] == "Unpaid") {
		$postData = array(
			'action' => "UpdateInvoice",
			'invoiceid' => $invoice_id,
			'status' => "Paid",
		);
		$results = localAPI("UpdateInvoice", $postData, $adminUsername);
	}
	return "Payment has been received.";
}

function add_payment($command, $invoice_id, $crypto_txn_id, $gatewaymodule, $amount_fiat, $amount_crypto, $payment_id, $client_id) {

	$postData = array(
		'action' => $command,
		'invoiceid' => $invoice_id,
		'transid' => $crypto_txn_id,
		'gateway' => $gatewaymodule,
		'amount_fiat' => $amount_fiat,
		'amount_crypto' => $amount_crypto,
		'paymentid' => $payment_id,
	);
	// Add the invoice payment - either line below would work
	// $results = localAPI($command, $postData, $adminUsername);
    addInvoicePayment($invoice_id,$crypto_txn_id,$amount_fiat,$gatewaymodule);
	logTransaction($gatewaymodule, $postData, "Success: ".$message);
	
}



//this only works if web server has write access to the enclosing directory 
// Write the callback JSON to a file for debugging. You can remove this for production.
if (file_put_contents("./callback-payment.json", json_encode($post)) === false)
   echo "error writing callback file";
else
    echo "written callback";
?>
