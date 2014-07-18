moodle_email_repository
=======================

ATTENTION: In order to use this plugin you need PHP compiled with imap- and openssl-extension.
           If you are using ssl-encrypted connections and certificate-warnings appear you may
           use an outdated version of openssl or you compiled the imap-extension without 
           openssl-support.

Moodle Plugin for a repository to import attached files from emails from an imap-Account

This provides the opportunity to import attachments from e-Mails. This can be an advantage if you
want to upload files from tablets like iPads or if it is not possible to install other repository plugins.

There exist many other repository plugins like Dropbox or FTP which either need an appropriate
infrastructure within your organization (access to ftp / webdav sources) or force your users to
do some configuration steps. This can be a barrier for some organizations or users.

The main advantage of using the email repository is that you can use nearly every mailbox that
provides access via imap, which is rather easy. Any user can send an email to this address
e.g. moodle@yourorganization.com or ourmoodle@gmail.com. As long as the senders mail-address equals
the mail-address of the moodle-user the user can import the attachment into moodle.

On the configuration page of the moodle plugin there is a parameter to specify after how many
days old emails should be deleted.

The e-Mail Repository Plugin is distributed under GPL 3 on github –> https://github.com/rschrenk/moodle_email_repository
