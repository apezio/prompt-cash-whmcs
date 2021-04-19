<?php
// This file gets called when the payment status changes (paid or expired).

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
echo "ok"; // any response is fine (ignored by Prompt.Cash)

// Read the application/json POST data.
// Afterwards you can access JSON variables like $post['payment']['status']
// similar to $post for url-encoded form data.
$post = json_decode(file_get_contents('php://input'), true);


if (empty($post)) {
    echo "no data received";
}

$crypto_txn_id = $post['payment']['hash'];
$whmcs_txn_id = $post['payment']['tx_id'];
$client_id = explode('_', $whmcs_txn_id)[1];
$invoice_id = explode('_', $whmcs_txn_id)[2];
$hash = explode('_', $whmcs_txn_id)[3];
$amount_crypto = $post['payment']['amount_crypto'];
$amount_fiat = $post['payment']['amount_fiat'];
$currency = $post['payment']['fiat_currency'];

// Enable debugging to WHMCS Gateway Transaction Log
//logTransaction($gatewaymodule, $whmcs_txn_id, "Error: Something went wrong. Check web server logs, ssl cert, or for other issues");


// Check if the payment is complete
if ($post['token'] === $secretKey) { // prevent spoofing
	
	// Make sure invoice exists in WHMCS
	$invoice_id = checkCbInvoiceID($invoice_id, $gatewaymodule);

	// Create a hash
	$hash2 = md5($invoice_id . $amount_fiat . $secretKey);
	// Compare created hash to data in post and secret key
	if ($hash != $hash2) {
		logTransaction($gatewaymodule, 'Invoice: ' . $invoice_id . ' Amount: ' . $amount_fiat . ' Hash1: ' . $hash . ' Hash2: ' . $hash2, "Error: Hash Verification Failure");
		return 'Hash Verification Failure';
	}
	// Prompt.cash is doing most of the work here, letting us know if the invoice balance is paid in full
    if ($post['payment']['status'] === 'PAID') {
        // Payment complete. Update your database and ship your order.
		return handle_whmcs($invoice_id, $amount_crypto, $amount_fiat, $crypto_txn_id, $whmcs_txn_id, $currency, $gatewaymodule, $client_id);
    }
    else if ($post['payment']['status'] === 'EXPIRED') {
        // The customer did not pay in time. You can cancel this order or send a new payment link.
    }
return;
}


function handle_whmcs($invoice_id, $amount_crypto, $amount_fiat, $crypto_txn_id, $whmcs_txn_id, $currency, $gatewaymodule, $client_id) {
	
	// Check if transaction already exists in WHMCS
	$record = Capsule::table('tblaccounts')->where('transid', $whmcs_txn_id)->get();
	$transaction_exists = $record[0]->transid;
	if (!$transaction_exists) {
		// Credit the payment to clients invoice
		checkCbTransID($whmcs_txn_id);
		add_payment("AddInvoicePayment", $invoice_id, $crypto_txn_id, $gatewaymodule, $amount_fiat, $amount_crypto, $whmcs_txn_id, $client_id);
	}
	// check if invoice has been marked as fully paid, if not, mark paid.  WHMCS normally wont mark as Paid if the amount isnt at least invoice due amount, which can sometimes stop service auto-deployments due to WHCMS waiting for those few missing sats.
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

// Add the payment to WHMCS and log the transaction in WHMCS
function add_payment($command, $invoice_id, $crypto_txn_id, $gatewaymodule, $amount_fiat, $amount_crypto, $whmcs_txn_id, $client_id) {

	$postData = array(
		'action' => $command,
		'invoiceid' => $invoice_id,
		'transid' => $crypto_txn_id,
		'gateway' => $gatewaymodule,
		'amount_fiat' => $amount_fiat,
		'amount_crypto' => $amount_crypto,
		'paymentid' => $whmcs_txn_id,
		'fee' => '',
	);
    addInvoicePayment($invoice_id,$crypto_txn_id,$amount_fiat, ' ' ,$gatewaymodule);
	logTransaction($gatewaymodule, $postData, "Success");
	
}



// Note: this only works if web server has write access to the enclosing directory 
// Write the callback JSON to a file for debugging. You can comment/remove this for production.
//if (file_put_contents("./callback-payment.json", json_encode($post)) === false)
//   echo "error writing callback file";
//else
//    echo "written callback";
?>
