<?php

use WHMCS\Database\Capsule;

/**
 * Addon Cancellation Helper Hook
 *
 * A helper that automatically processes everything related to the cancellation of an addon.
 * 
 * Adds a note to the addon referencing the date, admin user and ticket ID (if supplied in the custom field) to the addon.
 * 
 * Cancels any related unpaid invoices and if those invoices have other services on them, the invoice gets split and a new invoice
 * is created for the unrelated services. A new invoice email is also sent out. 
 * 
 *
 * @package    WHMCS
 * @author     Lee Mahoney <lee@leemahoney.dev>
 * @copyright  Copyright (c) Lee Mahoney 2022
 * @license    MIT License
 * @version    1.0.2
 * @link       https://leemahoney.dev
 */



if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}


/**
 * addon_cancellation_helper_event
 *
 * @param  mixed $vars
 * @return void
 */
function addon_cancellation_helper_event($vars) {

    # Custom field name for cancellation ticket ID
    $fieldName = 'Cancellation Ticket ID';

    # Grab the addon's data
    $addonData = Capsule::table('tblhostingaddons')->where('id', $vars['id'])->first();

    # Note keeping, let's grab a couple of things we'll need to make the note.
    $fieldID        = Capsule::table('tblcustomfields')->where(['fieldname' => $fieldName, 'type' => 'addon', 'relid' => $addonData->id])->first()->id;
    $ticketID       = Capsule::table('tblcustomfieldsvalues')->where(['fieldid' => $fieldID, 'relid' => $addonData->id])->first()->value;
    $date           = date('d/m/Y');
    $currentUser    = new \WHMCS\Authentication\CurrentUser;
    $username       = $currentUser->admin()->username;

    # Grab current notes
    $notes = $addonData->notes;

    # Append to the current notes on the service
    if ($ticketID) {
        $notes .= "\nAddon cancelled by {$username} on {$date} through ticket {$ticketID}";
    } else {
        $notes .= "\nAddon cancelled by {$username} on {$date}";
    }

    # Update the notes
    try {

        Capsule::table('tblhostingaddons')->where('id', $addonData->id)->update([
            'notes' => $notes
        ]);
    
    } catch (\Exception $e) {
        logActivity("Unable to update notes on addon #{$addonData->id}. Reason: {$e->getMessage()}", 0);
    }

    if ($addonData->subscriptionid) {

        $gateway = new \WHMCS\Module\Gateway;

        $gateway->load($addonData->paymentmethod);

        if ($gateway->functionExists('cancelSubscription')) {
            $gateway->call('cancelSubscription', ['subscriptionID' => $addonData->subscriptionid]);
        }

        Capsule::table('tblhostingaddons')->where('id', $addonData->id)->update([
            'subscriptionid' => ''
        ]);

    }

    # Loop through all users invoices where the status is Unpaid (to prevent us cancelling paid or draft invoices)
    foreach (Capsule::table('tblinvoices')->where(['userid' => $addonData->userid, 'status' => 'Unpaid'])->get() as $userInvoice) {
    
        # Create two arrays, one to hold related invoice items (to the addon. Actually it should just be the addon ^_^) and one to hold all unrelated invoice items
        $related    = [];
        $unrelated  = [];

        # Reset the count otherwise the logic will never work and invoices will never be marked as cancelled.
        $count = 0;

        # get a total count of invoice items that match the invoice id
        $invoiceItemCount = Capsule::table('tblinvoiceitems')->where('invoiceid', $userInvoice->id)->count();

        # Loop through invoice items that match the invoice id
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $userInvoice->id)->get() as $invoiceItem) {
            
            # Check if the relid on the invoice item matches the addon id and the type is set to Addon, if so then increase the count and add to array. Otherwise, add to unrelated array
            if ($invoiceItem->relid == $addonData->id && $invoiceItem->type == "Addon") {
                $count++;
                $related[] = $invoiceItem->id;
            } else {
                $unrelated[] = $invoiceItem->id;
            }

        }
        
        # If the count is not zero (actually could just do a !empty($related) here also, both work) we know the invoice is related so cancel it, move the unrelated items to a new invoice and issue a new invoice email
        # This is messy, WHMCS don't provide an API function to split the invoice?!
        if ($count != 0) {

            try {
                Capsule::table('tblinvoices')->where('id', $userInvoice->id)->update([
                    'date_cancelled' => date('Y-m-d H:i:s'),
                    'status' => 'Cancelled'
                ]);
            } catch (\Exception $e) {
                logActivity("Unable to cancel invoice #{$userInvoice->id} for addon #{$vars['id']}. Reason: {$e->getMessage()}", 0);
            }

            # Call WHMCS's local API to create a new invoice (so we can generate an invoice number)
            $command = 'CreateInvoice';

            $postData = [
                'userid'        => $userInvoice->userid,
                'status'        => 'Unpaid',
                'sendinvoice'   => '0',
                'paymentmethod' => $userInvoice->paymentmethod,
                'taxrate'       => $userInvoice->taxrate,
                'date'          => date('YYYY-MM-DD'),
                'duedate'       => $userInvoice->duedate,
            ];

            $result         = localAPI($command, $postData, $currentUser->admin()->username);
            $newInvoiceID   = $result['invoiceid'];

            # Loop through invoice line items in the unrelated array and update them to the new invoice ID
            foreach($unrelated as $id) {

                try {
                    Capsule::table('tblinvoiceitems')->where('id', $id)->update([
                        'invoiceid' => $newInvoiceID,
                    ]);
                } catch (\Exception $e) {
                    logActivity("Failed to add new invoice items to invoice #{$newInvoiceID}. Reason: {$e->getMessage()}", 0);
                }

            }

            # Update the subtotal on the invoice
            $newInvoice = Capsule::table('tblinvoices')->where('id', $newInvoiceID)->first();
            updateTotals($newInvoice, $unrelated);

            # Update the old invoice's subtotal (as it would be still what it was before we moved the items off, surprised whmcs hardcodes this to the database still!)
            updateTotals($userInvoice, $related);

            # Send out the Invoice Created email for the new invoice
            $command = 'SendEmail';
                
            $postData = array(
                'messagename' => 'Invoice Created',
                'id' => $newInvoiceID,
            );

            localAPI($command, $postData, $currentUser->admin()->username);
            
        }
        
    }

}

/**
 * updateTotals
 *
 * @param  mixed $invoice
 * @param  mixed $items
 * @return void
 */
function updateTotals($invoice, $items) {

    $subTotal = 0;
    $total    = 0;

    # Loop through each invoice item
    foreach ($items as $item) {

        # Add amount of each item to the sub total of the invoice
        $itemData = Capsule::table('tblinvoiceitems')->where('id', $item)->first();
        $subTotal += $itemData->amount;

    }
    
    # Correctly format it for WHMCS
    $subTotal = number_format($subTotal, 2, '.', '');

    # Get both tax values
    $tax    = $subTotal * $invoice->taxrate / 100;
    $tax2   = $subTotal * $invoice->taxrate2 / 100;

    # Get the final total of the invoice
    $total = ($subTotal + $tax + $tax2) - $invoice->credit;

    # Update the values on the invoice
    try {

        Capsule::table('tblinvoices')->where('id', $invoice->id)->update([
            'subTotal' => $subTotal,
            'tax' => $tax,
            'tax2' => $tax2,
            'total'    => $total,
        ]);
    
    } catch(\Exception $e) {
        logActivity("Unable to update totals on invoice #{$invoice->id}. Reason: {$e->getMessage()}", 0);
    }

}

/**
 * Register hook function call.
 *
 * @param string $hookPoint The hook point to call.
 * @param integer $priority The priority for the hook function.
 * @param function The function name to call or the anonymous function.
 *
 * @return This depends on the hook function point.
 */
add_hook('AddonCancelled', 1, 'addon_cancellation_helper_event');