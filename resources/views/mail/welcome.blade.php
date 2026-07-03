{{-- SPDX-License-Identifier: Apache-2.0 --}}
{{-- Welcome email body (onboarding-lite, NOV-123). Plain, code-controlled copy — no admin/user HTML. --}}
<p>Hi {{ $name }},</p>

<p>Welcome to {{ $siteName }} — we’re glad you joined.</p>

<p>Here are a few things to help you settle in:</p>
<ul>
    <li>Complete your profile so people know who you are.</li>
    <li>Write your first post and introduce yourself.</li>
    <li>React to a post you like.</li>
</ul>

<p>Your getting-started checklist on the home page will walk you through each step.</p>

<p><a href="{{ $url }}">Visit {{ $siteName }} →</a></p>

<p>See you in the forums!</p>
