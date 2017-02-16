# Bulk-Mailer
A PHP script to send mass emails spread over time.

This tool is used in my websites to send user notifications based on their subscriptions.  Because most hosting services have limits on how many emails can be sent per hour or day, it sends them out in batches over time.

Keep in mind that this script is not stand-alone, and relies on other parts of my site software to operate, like Database (SQLite), User and Mailer classes.

To trigger pseudo-chron job, do this on every page hit:
    include_once('class/Mailer_bulk.php');
    new Mailer_bulk; // Just to ping chrons.

To administer the mailer, do this when Admin is logged in:
    include_once('class/Mailer_bulk.php');
    new Mailer_bulk(true); // To see the mail form and monitor outputs.
