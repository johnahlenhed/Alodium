<x-mail::message>
# You've been invited to Alodium

Hi {{ $user->name }}, {{ $invitedBy->name }} has invited you to manage content on Alodium.

Choose a password to activate your account:

<x-mail::button :url="$resetUrl">
Set up your password
</x-mail::button>

This link expires in {{ $expiresInMinutes }} minutes. If it has expired, use "Forgot your password?" on the login page with this email address ({{ $user->email }}) to get a new one.

If you weren't expecting this invitation, you can ignore this email.

The Alodium team
</x-mail::message>
