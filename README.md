# prompt-cash-whmcs
Prompt.Cash plugin for WHMCS to accept Bitcoin Cash with no fees and no banks.


Allow any web hosting, server hosting, or other hosting business using WHMCS (https://www.whmcs.com/) to begin accepting Bitcoin Cash via Prompt.Cash (https://prompt.cash/).


Its almost working.

Please help with some funding:

http://flipstarter.forked.net

Thanks!

Basic Instructions:

1) Place promptcash.php into modules/gateways/
2) Place promptcashcallback.php into modules/gateways/callback/
3) Go to Setup->Payments->Payment Gateways in WHMCS.
4) Click All Payment Gateways and find Prompt.Cash.  Click it to enable.
5) If you aren't already taken the to module config page, click Manage Existing Gateways in WHMCS and scroll to the bottom to find Prompt.Cash
6) Grab your API credentials from https://prompt.cash/account and fill in the values for Public Token and Secret Token them into the module.
Note: For now, make sure Discount Percentage is set to 1.  If you want to give a discount to your clients for paying with Bitcoin Cash enter a number like .95 (5% discount) or .9 (10% discount).
