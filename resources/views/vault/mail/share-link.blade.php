{{--
    The email that carries a vault share link.

    THIS MESSAGE IS THE CREDENTIAL. The link has no password on the far side of
    it, so everything a mailbox does to an email - forward it, archive it for
    seven years, sync it to a phone, hand it to a scanner - it is also doing to
    the credential. Two rules follow and neither is negotiable:

      1. The entry's TITLE is the only thing about the credential in here.
         No username, no password, no 2FA code, no notes, no address field.
         What the recipient gets is a link and the discipline around it; the
         values are behind the reveal, on a page that spends a view to show
         them.
      2. The constraints are stated plainly, not buried. An expiry the reader
         did not notice becomes a support call; a one-view link they open on a
         train with no signal becomes a second, more careless, send.

    Deliberately plainer than a marketing template, for the same reason as the
    consumer's invitation mail: this is a security email, and the more it looks
    like a campaign the more it looks like phishing. One card, one button, the
    same link in text underneath for clients that strip anchors.

    NO EXTERNAL ASSETS AT ALL - no logo URL, no web font, no tracking pixel. A
    remote image here would be a read receipt on a credential and a broken-image
    box behind "load remote content?" everywhere it was blocked.

    Colours are literal rather than tokens: an email client has no CSS custom
    properties and no stylesheet of ours. #1b3933 / #3cbf7d / #f5f3ef mirror the
    brand pair the consuming CRM uses for its own outbound mail; publish
    `visns-packages-views` and point `vault.share.mail_view` at your own copy to
    change them.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="x-apple-disable-message-reformatting">
        <title>{{ $senderName }} has shared a secure credential with you</title>
    </head>
    <body style="margin:0; padding:0; background-color:#f5f3ef; -webkit-font-smoothing:antialiased;">
        {{-- The preheader: what a mail client shows next to the subject in the
             inbox list. Names the expiry rather than the credential - this line
             is visible in a list of messages over somebody's shoulder. --}}
        <div style="display:none; font-size:1px; color:#f5f3ef; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
            A secure link to one credential. It expires on {{ $expires }}.
        </div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f3ef;">
            <tr>
                <td align="center" style="padding:32px 16px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px; background-color:#ffffff; border:1px solid #e4e1dc; border-radius:8px;">
                        <tr>
                            <td style="padding:32px 32px 8px 32px; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;">
                                <p style="margin:0 0 6px 0; font-size:12px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#3cbf7d;">
                                    {{ $company }}
                                </p>
                                <h1 style="margin:0 0 20px 0; font-size:24px; line-height:1.25; font-weight:700; color:#1b3933;">
                                    A credential has been shared with you
                                </h1>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:0 32px; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#443c2b;">
                                @if ($recipientName)
                                    <p style="margin:0 0 16px 0;">Hi {{ $recipientName }},</p>
                                @endif

                                <p style="margin:0 0 16px 0;">
                                    <strong style="color:#1b3933;">{{ $senderName }}</strong> at
                                    {{ $company }} has sent you a secure link to one set of
                                    sign-in details:
                                </p>

                                {{-- The title, and nothing else off the entry.
                                     A title is a label somebody chose to be
                                     read; the fields behind it are not. --}}
                                <p style="margin:0 0 20px 0; padding:12px 14px; background-color:#f5f3ef; border-radius:6px; font-size:15px; font-weight:600; color:#1b3933;">
                                    {{ $title }}
                                </p>

                                <p style="margin:0 0 24px 0;">
                                    The details are not in this email. Open the link below to
                                    read them.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:0 32px 24px 32px;">
                                {{-- A table-wrapped button rather than a styled
                                     anchor: Outlook ignores padding on inline
                                     elements and would render a bare link. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td align="center" bgcolor="#1b3933" style="border-radius:6px;">
                                            <a href="{{ $url }}"
                                               style="display:inline-block; padding:14px 28px; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:6px;">
                                                View the credential
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:0 32px 24px 32px; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#6b7688;">
                                <p style="margin:0 0 6px 0;">
                                    If the button does not work, copy this address into your browser:
                                </p>
                                <p style="margin:0; word-break:break-all;">
                                    <a href="{{ $url }}" style="color:#1b3933;">{{ $url }}</a>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:0 32px 24px 32px; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif; font-size:14px; line-height:1.6; color:#443c2b;">
                                <p style="margin:0 0 6px 0;">
                                    The link expires on
                                    <strong style="color:#1b3933;">{{ $expires }}</strong>.
                                </p>
                                <p style="margin:0;">{{ $views }}</p>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:0 32px 28px 32px;">
                                {{-- The one thing the reader has to take from
                                     this email that is not "click here". Given
                                     its own block with a rule down the side,
                                     because it is the sentence most easily
                                     skimmed past and the most expensive to
                                     have missed. --}}
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f3ef; border-left:3px solid #1b3933; border-radius:4px;">
                                    <tr>
                                        <td style="padding:14px 16px; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#443c2b;">
                                            <strong style="color:#1b3933;">This link is the key.</strong>
                                            There is no password on the other side of it, so anyone
                                            who has the link can read the details until it expires.
                                            Please do not forward this email, and delete it once you
                                            have saved what you need.
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <tr>
                            <td style="padding:20px 32px; border-top:1px solid #e4e1dc; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif; font-size:12px; line-height:1.6; color:#6b7688;">
                                If you were not expecting this, ignore the email and let
                                {{ $senderName }} know - the link can be revoked, and one that is
                                never opened gives nothing away.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
