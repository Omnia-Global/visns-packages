<?php

namespace Visnsstudio\VisnsPackages\Mail;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Reyhan Thee has shared a secure credential with you."
 *
 * THIS EMAIL CARRIES THE CREDENTIAL. Not the password - the link, which on this
 * feature amounts to the same thing: there is nothing on the far side of it but
 * a button. Every decision below follows from that.
 *
 *  - The entry's TITLE is the only thing about the credential that appears.
 *    Never the username, never the password, never a code, never the notes,
 *    never the URL field. A mailbox is not a vault, and a subject line that
 *    said "the Acme firewall admin password" would have leaked half of it
 *    before the link was ever clicked.
 *  - Not queued. The consuming applications run no worker (the vault module is
 *    deliberately deployable without one) and a link that silently waits in a
 *    table nobody drains is worse than one that fails in front of the sender.
 *    The caller catches a transport failure and hands the URL back instead.
 *  - No mailer named, so this goes out on the application's default - the
 *    package has no business choosing a transport for its consumer.
 *  - Every value is a plain string or a date. Nothing here is a model, so
 *    nothing here can be re-serialised into a job that reloads an entry and
 *    renders a password.
 *
 * The view is `visns-packages::vault.mail.share-link` by default and is
 * configurable (`vault.share.mail_view`), same as the public reveal page: an
 * application that wants its own house style should not have to fork the
 * package to get it.
 */
class VaultShareLink extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $url          the one-time share link
     * @param  string  $title        the entry's title, and nothing else off it
     * @param  string  $senderName   the CRM user who created the link
     * @param  DateTimeInterface  $expiresAt  when the link stops working
     * @param  int|null  $maxViews   the view budget, or null for "no limit"
     * @param  string|null  $recipientName  for the greeting, when one was given
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $senderName,
        public DateTimeInterface $expiresAt,
        public ?int $maxViews = null,
        public ?string $recipientName = null
    ) {
    }

    public function build()
    {
        $view = (string) config(
            'visns-packages.vault.share.mail_view',
            'visns-packages::vault.mail.share-link'
        );

        // The sender's name is in the subject because "shared by someone you
        // know" is what makes a security email read as expected rather than as
        // phishing - the same reason the invitation mail names its inviter.
        return $this
            ->subject($this->senderName . ' has shared a secure credential with you')
            ->view($view, [
                'company' => $this->company(),
                'expires' => $this->expires(),
                'views' => $this->views(),
            ]);
    }

    /**
     * Who the mail says it is from, in words.
     *
     * `config('company.name')` is the consuming CRM's own key and the better
     * answer where it exists - it is what the rest of that application signs
     * its outbound mail as - but it is not part of Laravel and this package
     * cannot assume it. `app.name` always exists, so the fallback is total.
     */
    private function company(): string
    {
        foreach ([config('company.name'), config('app.name')] as $candidate) {
            $name = trim((string) (is_string($candidate) ? $candidate : ''));

            if ($name !== '') {
                return $name;
            }
        }

        return 'the vault';
    }

    /**
     * The expiry as an absolute moment with its timezone.
     *
     * "Expires in 24 hours" would be read at some unknowable later point and be
     * wrong by then; a stamped date and time is true whenever it is read. The
     * zone is spelled out because the recipient is frequently not in ours.
     */
    private function expires(): string
    {
        $when = $this->expiresAt;

        if ($when instanceof \DateTimeImmutable || $when instanceof \DateTime) {
            return $when->format('D, j M Y \a\t g:ia T');
        }

        // A Carbon instance answers format() too; this is only here so that a
        // caller passing some other DateTimeInterface cannot break the mail.
        return (string) $when->format('D, j M Y \a\t g:ia T');
    }

    /**
     * The view budget, said the way a person would say it.
     *
     * Deliberately phrased as what the recipient may do rather than as a
     * number: "can be opened once" is an instruction, "max_views: 1" is a
     * field, and the one-view case is the one worth them understanding before
     * they click on a phone with a flaky connection.
     */
    private function views(): string
    {
        if ($this->maxViews === null) {
            return 'It can be opened as often as you like until it expires.';
        }

        if ($this->maxViews === 1) {
            return 'It can be opened once - after that the link stops working, '
                . 'so open it when you are ready to save the details.';
        }

        return 'It can be opened up to ' . $this->maxViews
            . ' times, after which the link stops working.';
    }
}
