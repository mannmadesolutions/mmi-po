# Security Policy

## Reporting a vulnerability

Please report security issues privately through GitHub: open the **Security** tab of
this repository and choose **Report a vulnerability**. Do not open a public issue or pull
request for a suspected vulnerability.

Include the affected version, the steps to reproduce, and the impact you observed. We
acknowledge reports within 3 business days and aim to ship a fix for confirmed High or
Critical issues within 30 days. We credit reporters in the release notes unless you
ask us not to.

## Supported versions

Only the latest released version receives security fixes.

## Security model

Sending a purchase order to a supplier always takes a human click per message; nothing is sent automatically.

- **Access control.** Every admin action checks a capability and a nonce. Day-to-day PO work
  (building, sending, downloading, buying and voiding labels) needs `manage_woocommerce`; the
  Settings tab and ShipStation credentials need `manage_options`. Both are filterable through
  `mmi_po_required_capability` (context `operate` or `manage`).
- **Label purchases** take a database lock and write a pending record before ShipStation is called,
  so a double click or retry cannot buy postage twice. The price is re-checked on the server and the
  purchase is refused if ShipStation now quotes more than the price the user confirmed. A purchase
  that gets no clear answer blocks further purchases on that PO until someone checks ShipStation.
- **Email** goes only through the site's own `wp_mail()` transport, with at most 10 recipients per
  message and 30 sends per user per hour.
- **Credentials** are stored server-side, encrypted where the plugin holds API secrets, shown
  masked, and never sent to the browser.
- **Audit log.** Settings and credential changes, PO emails, label purchases and voids, PDF
  downloads, PO deletions and refused requests are written to the suite's append-only, hash-chained
  `{prefix}mmi_audit_log` table, reviewed under **MannMade → Audit Log**. Secret values are
  redacted before storage.
- **Client IP** is taken from `REMOTE_ADDR`. Behind a proxy or CDN, configure your web server to
  restore the real client IP rather than trusting forwarded headers.
