{{--
    The recipient's page for a vault share link.

    SELF-CONTAINED ON PURPOSE. The CRM's React application lives behind auth and
    is never served to whoever holds one of these links: no bundle, no session,
    no framework, nothing fetched from a CDN. Everything this page needs - the
    CSS, the twenty lines of script behind the show/copy buttons - is inline,
    so it renders identically on a locked-down corporate laptop, in a webmail
    preview pane and on a phone with a bad connection.

    NO SECRET IS PRINTED IN THE `prompt` STATE. That state is what a link
    preview bot fetches, and the whole design of the endpoint rests on there
    being nothing here for one to scrape. The values only exist in the
    `revealed` state, which is reachable only by POST.

    Values reach the script through `data-` attributes rather than through an
    interpolated JS string literal: Blade escapes an attribute correctly, where
    a password containing a quote or a `</script>` dropped into a script block
    is an XSS waiting to happen.

    Three states, one file:
      prompt    a sentence and a Reveal button
      revealed  the fields, masked where they should be
      gone      the identical answer for unknown, expired, revoked and spent
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Belt and braces with the headers the controller sets; a page saved to
         disk and re-served keeps these, where headers do not travel. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $state === 'revealed' ? 'Shared credential' : 'A credential has been shared with you' }}</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --card: #ffffff;
            --ink: #1f2328;
            --muted: #60666e;
            --line: #dfe2e6;
            --accent: #00386c;
            --accent-ink: #ffffff;
            --soft: #f0f2f5;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14161a;
                --card: #1c1f24;
                --ink: #e9ecef;
                --muted: #9aa2ac;
                --line: #2d3238;
                --accent: #4c93d8;
                --accent-ink: #0d1013;
                --soft: #23272d;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 2rem 1rem 3rem;
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                Helvetica, Arial, sans-serif;
            font-size: 15px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            max-width: 30rem;
            margin: 0 auto;
            padding: 1.75rem;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--card);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .mark {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            margin-bottom: 1.1rem;
            border-radius: 999px;
            background: var(--soft);
            color: var(--muted);
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.15rem;
            font-weight: 650;
            letter-spacing: -0.01em;
        }

        p { margin: 0 0 1rem; color: var(--muted); }

        .brand {
            margin: 0 0 0.35rem;
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .limits {
            margin: 0 0 1.25rem;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--soft);
            color: var(--muted);
            font-size: 0.82rem;
        }

        .limits strong { color: var(--ink); font-weight: 600; }

        button {
            font: inherit;
            cursor: pointer;
        }

        .primary {
            display: block;
            width: 100%;
            padding: 0.7rem 1rem;
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: var(--accent-ink);
            font-weight: 600;
        }

        .primary:hover { filter: brightness(1.08); }

        .field {
            margin: 0 0 0.75rem;
            padding: 0.7rem 0.8rem;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--soft);
        }

        .field-label {
            display: block;
            margin-bottom: 0.3rem;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .field-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .value {
            flex: 1 1 auto;
            min-width: 0;
            overflow-x: auto;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.92rem;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .value.masked {
            font-family: inherit;
            letter-spacing: 0.18em;
        }

        .mini {
            flex: 0 0 auto;
            padding: 0.3rem 0.55rem;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--card);
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 600;
        }

        .mini:hover { color: var(--ink); border-color: var(--muted); }

        .notes .value { white-space: pre-wrap; }

        .footnote {
            margin: 1.25rem 0 0;
            color: var(--muted);
            font-size: 0.78rem;
        }

        a { color: var(--accent); }
    </style>
</head>
<body>
<main class="card">

    @if ($state === 'gone')
        {{-- The identical page for unknown, expired, revoked and spent. Saying
             which would answer a question the holder of a dead link has no
             business asking. --}}
        <div class="mark" aria-hidden="true">&#9673;</div>
        <h1>This link is no longer available</h1>
        <p>
            It may have expired, been opened the maximum number of times, or been
            withdrawn by the person who sent it.
        </p>
        <p class="footnote">
            If you still need this, ask them to send you a new link.
        </p>

    @elseif ($state === 'prompt')
        <p class="brand">{{ $brand }}</p>
        <div class="mark" aria-hidden="true">&#128273;</div>
        <h1>A credential has been shared with you</h1>
        <p>
            Nothing is shown until you press the button below. Make sure nobody
            is reading over your shoulder.
        </p>

        <div class="limits">
            @if ($expires_at)
                This link expires
                <strong>{{ $expires_at->toDayDateTimeString() }}</strong>{{ $views_left === null ? '.' : '' }}
            @endif
            @if ($views_left !== null)
                @if ($expires_at) and @endif
                can be opened
                <strong>{{ $views_left }} more {{ $views_left === 1 ? 'time' : 'times' }}</strong>.
            @endif
        </div>

        {{-- A POST, and a real button. A preview bot follows links and fetches
             URLs; it does not submit forms, so pasting this link into a chat
             cannot spend a view or leak the secret into a preview cache. --}}
        <form method="POST" action="{{ $postUrl }}">
            @csrf
            <button type="submit" class="primary">Reveal the credential</button>
        </form>

        <p class="footnote">
            Opening this page counts towards the link's limit only when you press
            Reveal.
        </p>

    @else
        <p class="brand">{{ $brand }}</p>
        <h1>Shared credential</h1>
        <p>
            Copy what you need now. This page will not show it again once you
            close or reload it.
        </p>

        @php
            $labels = [
                'username' => 'Username',
                'password' => 'Password',
                'totp' => 'Two-factor code',
                'url' => 'Website',
                'notes' => 'Notes',
            ];
        @endphp

        @forelse ($values as $field => $value)
            <div class="field {{ $field === 'notes' ? 'notes' : '' }}">
                <span class="field-label">{{ $labels[$field] ?? $field }}</span>
                <div class="field-row">
                    @if ($field === 'password')
                        {{-- Masked until asked for. The real value rides in a
                             data attribute, which Blade escapes properly; it is
                             never interpolated into the script block. --}}
                        <span
                            class="value masked"
                            data-secret="{{ $value }}"
                            data-masked="1"
                        >{{ str_repeat('•', min(20, max(8, mb_strlen($value)))) }}</span>
                        <button type="button" class="mini" data-show>Show</button>
                    @elseif ($field === 'url')
                        <span class="value" data-secret="{{ $value }}">
                            <a href="{{ $value }}" rel="noreferrer noopener nofollow" target="_blank">{{ $value }}</a>
                        </span>
                    @else
                        <span class="value" data-secret="{{ $value }}">{{ $value }}</span>
                    @endif
                    <button type="button" class="mini" data-copy>Copy</button>
                </div>
            </div>
        @empty
            <p>There is nothing left on this entry to show.</p>
        @endforelse

        @if (in_array('totp', $fields, true) && ! array_key_exists('totp', $values))
            <p class="footnote">
                The two-factor code could not be generated for this credential.
            </p>
        @elseif (array_key_exists('totp', $values))
            <p class="footnote">
                The two-factor code changes every 30 seconds. Reload only if you
                have views left &mdash; otherwise ask for a new link.
            </p>
        @endif

        <p class="footnote">
            Treat this link as the credential itself: anyone who has it can see
            what you are seeing. Do not forward it.
        </p>
    @endif

</main>

@if ($state === 'revealed')
    <script>
        // Deliberately tiny and dependency-free. The two behaviours are
        // unmasking the password and putting a value on the clipboard; every
        // value comes off a data attribute Blade escaped, so nothing here
        // parses or builds markup.
        (function () {
            var say = function (button, word) {
                var was = button.textContent;
                button.textContent = word;
                setTimeout(function () { button.textContent = was; }, 1400);
            };

            document.querySelectorAll('[data-show]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var row = button.closest('.field-row');
                    var value = row && row.querySelector('[data-secret]');
                    if (!value) return;

                    if (value.getAttribute('data-masked') === '1') {
                        value.textContent = value.getAttribute('data-secret');
                        value.classList.remove('masked');
                        value.setAttribute('data-masked', '0');
                        button.textContent = 'Hide';
                    } else {
                        var secret = value.getAttribute('data-secret') || '';
                        var dots = Math.min(20, Math.max(8, secret.length));
                        value.textContent = new Array(dots + 1).join('•');
                        value.classList.add('masked');
                        value.setAttribute('data-masked', '1');
                        button.textContent = 'Show';
                    }
                });
            });

            document.querySelectorAll('[data-copy]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var row = button.closest('.field-row');
                    var value = row && row.querySelector('[data-secret]');
                    if (!value) return;

                    var text = value.getAttribute('data-secret') || '';

                    // navigator.clipboard needs a secure context and is absent
                    // on plain http and in older browsers; the textarea path is
                    // the one that still works there.
                    var fallback = function () {
                        var box = document.createElement('textarea');
                        box.value = text;
                        box.setAttribute('readonly', 'readonly');
                        box.style.position = 'fixed';
                        box.style.opacity = '0';
                        document.body.appendChild(box);
                        box.select();
                        try { document.execCommand('copy'); say(button, 'Copied'); }
                        catch (e) { say(button, 'Copy failed'); }
                        document.body.removeChild(box);
                    };

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text)
                            .then(function () { say(button, 'Copied'); })
                            .catch(fallback);
                    } else {
                        fallback();
                    }
                });
            });
        }());
    </script>
@endif
</body>
</html>
