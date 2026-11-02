{{ $inviter }} invited you to join "{{ $invitation->tenant->name }}" as {{ $invitation->role->name }}.

Accept the invitation (valid until {{ $invitation->expires_at->format('d M Y') }}):
{{ $url }}

If you were not expecting this, you can ignore this email.
