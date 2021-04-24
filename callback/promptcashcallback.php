<?php
// This file gets called when the payment status changes (paid or expired).

include("../../../init.php"); 
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");

use Illuminate\Database\Capsule\Manager as Capsule;

$gatewaymodule = "pcash"; # Enter your gateway module name here replacing template
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
$discount_percentage = explode('_', $whmcs_txn_id)[4];
$amount_crypto = $post['payment']['amount_crypto'];
$amount_after_dsct = money_format('%i', $post['payment']['amount_fiat']);
$currency = $post['payment']['fiat_currency'];

// Enable debugging to WHMCS Gateway Transaction Log
//logTransaction($gatewaymodule, $whmcs_txn_id, "If something went wrong, check web server logs, ssl cert, or for other issues");


// Check if the payment is complete
if ($post['token'] === $secretKey) { // prevent spoofing
	
	// Make sure invoice exists in WHMCS
	$invoice_id = checkCbInvoiceID($invoice_id, $gatewaymodule);

	// Create a hash
	$hash2 = md5($invoice_id . $amount_after_dsct . $secretKey);
	// Compare created hash to data in post and secret key
	if ($hash != $hash2) {
		logTransaction($gatewaymodule, 'Invoice: ' . $invoice_id . ' Amount: ' . $amount_after_dsct . ' Hash1: ' . $hash . ' Hash2: ' . $hash2, "Error: Hash Verification Failure");
		logModuleCall($gatewaymodule, "debug Hash Verification Failure", "", "", 'Invoice: ' . $invoice_id . ' Amount: ' . $amount_after_dsct . ' Hash1: ' . $hash . ' Hash2: ' . $hash2, "");

		return 'Hash Verification Failure';
	}
	// Prompt.cash is doing most of the work here, letting us know if the invoice balance is paid in full
    if ($post['payment']['status'] === 'PAID') {
        // Payment complete. Update your database and ship your order.
		return handle_whmcs($invoice_id, $amount_crypto, $amount_after_dsct, $crypto_txn_id, $whmcs_txn_id, $currency, $gatewaymodule, $client_id, $discount_percentage);
    }
    else if ($post['payment']['status'] === 'EXPIRED') {
        // The customer did not pay in time. You can cancel this order or send a new payment link.
    }
return;
}


function getAdminUserName() {
	logModuleCall($gatewaymodule, "debug getAdminUserName", "", "", "", "");

    $adminData = Capsule::table('tbladmins')
            ->where('disabled', '=', 0)
            ->first();
    if (!empty($adminData))
        return $adminData->username;
    else
        die('no admin');
}


function handle_whmcs($invoice_id, $amount_crypto, $amount_after_dsct, $crypto_txn_id, $whmcs_txn_id, $currency, $gatewaymodule, $client_id, $discount_percentage) {
	logModuleCall($gatewaymodule, "debug handle_whmcs", "", "", $invoice_id.' '.$amount_crypto.' '.$amount_after_dsct.' '.$crypto_txn_id.' '.$whmcs_txn_id.' '.$currency.' '.$gatewaymodule.' '.$client_id.' '.$discount_percentage, "");
	$adminUsername = getAdminUserName();

	// Check if transaction already exists in WHMCS
	$record = Capsule::table('tblaccounts')->where('transid', $crypto_txn_id)->get();
	$transaction_exists = $record[0]->transid;
	
	logModuleCall($gatewaymodule, "debug transaction_exists", "", "", $transaction_exists.' '.$whmcs_txn_id.' '.$crypto_txn_id .' '.$discounttotal, "");

	// are we looking for whmcs_txn_id or crypto_txn_id here?
	if (!$transaction_exists) {

		// check if bch txid already exists, exit if it does
		checkCbTransID($crypto_txn_id);

		// Credit the payment to clients invoice
		add_payment("AddInvoicePayment", $invoice_id, $crypto_txn_id, $gatewaymodule, $amount_after_dsct, $amount_crypto, $whmcs_txn_id, $client_id, $discount_percentage);
	}
	// check if invoice has been marked as fully paid, if not, mark paid.  
	$command = 'GetInvoice';
	$postData = array(
		'invoiceid' => $invoice_id,
	);
	$results = localAPI($command, $postData, $adminUsername);

	// check whmcs invoice status
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
function add_payment($command, $invoice_id, $crypto_txn_id, $gatewaymodule, $amount_after_dsct, $amount_crypto, $whmcs_txn_id, $client_id, $discount_percentage) {
	$adminUsername = getAdminUserName();


	if ($discount_percentage != '0') {
		$orig_amount = 100 * ($amount_after_dsct / (100-$discount_percentage));
		$discounttotal = money_format('%i', ($orig_amount - $amount_after_dsct));
		logModuleCall($gatewaymodule, "debug add_payment2", "", "", $amount_after_dsct.' '.$discount_percentage.' '.$orig_amount .' '.$discounttotal, "");
	// add negative line item for discount if there is one.
		$postData = array(
			'invoiceid' => $invoice_id,
			'gateway' => $gatewaymodule,
			'newitemdescription' => array(0 => $discount_percentage . '% Discount for paying with Bitcoin Cash'),
			'newitemamount' => array(0 => '-' . $discounttotal),
			'newitemtaxed' => array(0 => false),
		);
		
		$results = localAPI("UpdateInvoice", $postData, $adminUsername);
	}

//foreach ($results as $key => $value) {
//    $resultsout .= "Key: $key; Value: $value\n";
//}

//foreach ($postData as $key => $value) {
//    $postDataout .= "Key: $key; Value: $value\n";
//}
//	logTransaction($gatewaymodule, $resultout . ' ' . $postDataout , "debug2");




// need to add checking for duplicate transaction ids before adding payment.

	$postData = array(
		'action' => $command,
		'invoiceid' => $invoice_id,
		'transid' => $crypto_txn_id,
		'gateway' => $gatewaymodule,
		'amount_fiat' => $amount_after_dsct,
		'amount_crypto' => $amount_crypto,
		'paymentid' => $whmcs_txn_id,
		'fee' => '',
	);
    addInvoicePayment($invoice_id,$crypto_txn_id,$amount_after_dsct, ' ' ,$gatewaymodule);
	logTransaction($gatewaymodule, $postData, "Success");
	
}



// Note: this only works if web server has write access to the enclosing directory 
// Write the callback JSON to a file for debugging. You can comment/remove this for production.
//if (file_put_contents("./callback-payment.json", json_encode($post)) === false)
//   echo "error writing callback file";
//else
//    echo "written callback";
?>
