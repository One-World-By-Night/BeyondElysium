# Secure Printing

Whether printed character sheets and reports carry a digital signature, what this site has configured, and how to get a signing certificate if your host won't let you make one yourself.

## Who can use this

Site administrators. A Storyteller runs a chronicle; a signing key belongs to whoever runs the server, so the certificate generator is administrator-only even though the rest of the screen is visible to anyone who can manage chronicles.

## How to get there

wp-admin sidebar → Beyond Elysium → System Config → **Secure Printing** tab.

## Printing always works

This is the important part, and it is deliberate: **printing never refuses**. With secure printing switched off, with no certificate installed, or on a host that cannot sign at all, sheets and reports still print through exactly the same typesetter and come out looking the same. Every page is stamped **UNSIGNED**.

An unsigned print can never be mistaken for a signed one, and a chronicle that will never have a certificate is not locked out of printing. The stamp is the answer for those chronicles, not a consolation prize.

## The two things that must both be true

A print is signed only when **both** of these hold:

1. A usable certificate is configured, through three `wp-config.php` constants.
2. An administrator has ticked **Sign printed sheets and reports** on this screen.

The switch exists separately from the certificate on purpose. A certificate arriving on the server is not the same as a decision to sign with it - you might be testing one, or have inherited one from whoever ran the site before you. Signing stays off until someone says so.

The switch is site-wide, not per chronicle, because the certificate is site-wide. A per-chronicle switch would imply per-chronicle certificates, which multiplies the one genuinely delicate thing here: handling a private key.

## Installing a certificate

The plugin never holds your private key. It is never uploaded through the browser, never written into the database, and never stored in the uploads folder. You put the files on the server yourself, over SFTP, somewhere outside the web root, and point three constants at them in `wp-config.php`:

```php
define( 'BE_PDF_SIGNING_CERT', '/home/you/private/be-signing.crt' );
define( 'BE_PDF_SIGNING_KEY', '/home/you/private/be-signing.key' );
define( 'BE_PDF_SIGNING_PASSPHRASE', 'your passphrase' );
```

If the key has no passphrase, leave the third constant out. That is a real configuration, not a mistake, and the screen reports it as such.

The **Status** table tells you which of the three are set and whether the two files can actually be read - never the key itself, and never the passphrase.

### If you have shell access

This is the command both production chronicles used:

```sh
openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 \
  -keyout be-signing.key -out be-signing.crt -cipher aes-256-cbc
```

## If your host has no shell

Plenty of shared hosting gives you no command line, so `openssl req` is simply unavailable - and that, not knowing where to put a file, is what locks a chronicle out of signed printing for good.

The screen can mint a pair for you. Give it a signer name and a passphrase, and it builds a self-signed certificate and an encrypted private key **in memory** and hands them to you once.

Nothing is written to the server and nothing is saved in the database - not the key, not the passphrase, not the certificate. Copy both files somewhere safe before you leave the page. Ask again and you get a different certificate, not the same one back.

From there it is the same as above: SFTP the two files somewhere outside the web root and add the three constants.

### If the button isn't there

Generating needs PHP's `openssl` extension. Where it's missing the screen says so instead of offering a button that cannot work.

That is not an extra requirement this feature invents. A PDF is signed through that same extension, so a host without it cannot sign a sheet no matter where the certificate came from. Those sites print unsigned, which is exactly what the UNSIGNED stamp is for.

## "Signature valid, signer not trusted"

A PDF reader will say something like this about a self-signed certificate, rather than showing a green tick. That is expected, and it is not a failure.

A green tick means a commercial certificate authority vouches for the signer. A self-signed certificate means *this chronicle* vouches for the document - which is what a Storyteller attesting to their own player's sheet actually is. The signature still proves the file has not been altered since it was printed, which is the thing that matters when a sheet travels between chronicles.

## See also

- [Printing and exporting a sheet](sheet-print-export.md)
- [Signed sheets](signed-sheets.md)
- [Verifying a sheet](verify.md)
- [Reports](reports.md)
