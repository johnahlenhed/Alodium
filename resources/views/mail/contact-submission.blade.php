<x-mail::message>
# New contact message

**From:** {{ $submission->name }} ({{ $submission->email }})

**Received:** {{ $submission->created_at->format('Y-m-d H:i') }}

<x-mail::panel>
{!! nl2br(e($submission->message)) !!}
</x-mail::panel>

Reply to this email to answer {{ $submission->name }} directly.
</x-mail::message>
