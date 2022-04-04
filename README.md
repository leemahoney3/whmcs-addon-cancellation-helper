# WHMCS Addon Cancellation Helper

A simple WHMCS hook that automatically processes everything related to the cancellation of an addon.
 * Adds a note to the addon referencing the date, admin user and ticket ID (if supplied in the custom field) to the addon.
 * Cancels any related unpaid invoices and if those invoices have other services on them, the invoice gets split and a new invoice is created for the unrelated services. A new invoice email is also sent out. 



## How to install

1. Copy the ```includes``` folder to your root WHMCS directory.

2. Create a custom field on your product addons with the name **Cancellation Ticket ID**
3. Enjoy! Once you cancel an addon, everything is done in the background.



## Contributions

Feel free to fork the repo, make changes, then create a pull request! For ideas on what you can help with, check the project issues.