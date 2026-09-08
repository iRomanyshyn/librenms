## Mail

The email transport uses the same email configuration as the rest of
LibreNMS. These are its configuration directives with their defaults:

An email attaches each graph of the `@signedGraphTag` directive. In
HTML format, the graphs are embedded. To disable the image
attachments, set `email_attach_graphs` to false.

!!! setting "alerting/email"
```bash
lnms config:set email_html true
lnms config:set email_attach_graphs false
```

**Example:**

| Config | Example |
| ------ | ------- |
| Email | me@example.com |

### Thread notifications

Enable **Thread notifications** on a Mail transport to group the problem,
repeat, acknowledgement, state-change, and recovery notifications for one
alert incident into an RFC email thread. LibreNMS gives the initial problem a
deterministic Message-ID; later notifications reply to that ID and use the
same subject rendered from the normal Alert Title. A recovery ends the
incident, so a later problem for the same device and rule starts a new thread.
The Message-ID namespace is derived from the installation's shared application
key, so all dispatchers in a distributed installation generate the same root ID.

This option is disabled by default for backward compatibility. While enabled,
the Recovery Title is not used for Mail notifications. The global LibreNMS
email and SMTP configuration continues to control delivery.
